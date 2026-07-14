<?php
declare(strict_types=1);

namespace Mohan\PaypalRest\Controller\Express;

use Mohan\PaypalRest\Model\Client;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment\Transaction as PaymentTransaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface as TransactionBuilder;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;

class ReturnAction implements HttpGetActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly Client $client,
        private readonly RequestInterface $request,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderManagementInterface $orderManagement,
        private readonly InvoiceService $invoiceService,
        private readonly TransactionBuilder $transactionBuilder,
        private readonly TransactionFactory $dbTransactionFactory,
        private readonly OrderSender $orderSender,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {}

    public function execute()
    {
        $paypalOrderId = (string)$this->request->getParam('token');
        $order         = $this->checkoutSession->getLastRealOrder();

        if (!$order->getId() || $paypalOrderId === '') {
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }

        $payment       = $order->getPayment();
        $storedOrderId = (string)$payment->getAdditionalInformation('paypal_order_id');

        if ($storedOrderId !== $paypalOrderId) {
            $this->logger->error(sprintf(
                'PaypalRest ReturnAction: token mismatch. Expected %s, got %s.',
                $storedOrderId, $paypalOrderId
            ));
            $this->messageManager->addErrorMessage(__('Payment verification failed. Please try again.'));
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }

        try {
            $accessToken = $this->client->getAccessToken();
            [, $captureResponse] = $this->client->postJson(
                '/v2/checkout/orders/' . $paypalOrderId . '/capture',
                $accessToken,
                []
            );

            $capture       = $captureResponse['purchase_units'][0]['payments']['captures'][0] ?? [];
            $captureId     = (string)($capture['id'] ?? '');
            $captureStatus = strtoupper((string)($capture['status'] ?? ''));

            if ($captureStatus !== 'COMPLETED' || $captureId === '') {
                throw new LocalizedException(
                    __('PayPal payment was not completed (status: %1).', $captureStatus ?: 'unknown')
                );
            }

            $payment->setTransactionId($captureId)
                ->setAdditionalInformation('paypal_capture_id', $captureId)
                ->setIsTransactionClosed(true);

            $this->transactionBuilder
                ->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($captureId)
                ->setAdditionalInformation([PaymentTransaction::RAW_DETAILS => [
                    'Capture ID'   => $captureId,
                    'Status'       => $capture['status'] ?? '',
                    'Amount'       => ($capture['amount']['value'] ?? '') . ' ' . ($capture['amount']['currency_code'] ?? ''),
                    'PayPal Order' => $paypalOrderId,
                ]])
                ->setFailSafe(true)
                ->build(PaymentTransaction::TYPE_CAPTURE);

            if ($order->canInvoice()) {
                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->setTransactionId($captureId);
                $invoice->register();
                $invoice->pay();

                $this->dbTransactionFactory->create()
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder())
                    ->save();
            }

            $order->setState(Order::STATE_PROCESSING)
                ->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING))
                ->addCommentToStatusHistory(sprintf('PayPal capture %s completed.', $captureId));

            $this->orderRepository->save($order);

            if (!$order->getEmailSent()) {
                $this->orderSender->send($order);
            }

            return $this->redirectFactory->create()->setPath('checkout/onepage/success');

        } catch (\Throwable $e) {
            $this->logger->error('PaypalRest ReturnAction: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Payment could not be processed. Please try again or contact support.')
            );

            if ($order->canCancel()) {
                $this->orderManagement->cancel((int)$order->getEntityId());
            }
            $this->checkoutSession->restoreQuote();

            return $this->redirectFactory->create()->setPath('checkout/cart');
        }
    }
}
