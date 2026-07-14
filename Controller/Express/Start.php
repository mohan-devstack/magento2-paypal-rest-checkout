<?php
declare(strict_types=1);

namespace Dzinehub\PaypalRest\Controller\Express;

use Dzinehub\PaypalRest\Model\Client;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class Start implements HttpGetActionInterface
{
    private const SANDBOX_CONFIG = 'dz_paypalrest/api/sandbox';

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly Client $client,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlInterface $urlBuilder,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderManagementInterface $orderManagement,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {}

    public function execute()
    {
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order->getId()) {
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }

        try {
            $token   = $this->client->getAccessToken();
            $sandbox = (bool)$this->scopeConfig->getValue(self::SANDBOX_CONFIG, ScopeInterface::SCOPE_STORE);

            [, $response] = $this->client->postJson('/v2/checkout/orders', $token, [
                'intent'              => 'CAPTURE',
                'purchase_units'      => [[
                    'reference_id' => $order->getIncrementId(),
                    'invoice_id'   => $order->getIncrementId(),
                    'amount'       => [
                        'currency_code' => $order->getOrderCurrencyCode(),
                        'value'         => number_format((float)$order->getGrandTotal(), 2, '.', ''),
                    ],
                ]],
                'application_context' => [
                    'return_url'          => $this->urlBuilder->getUrl('dzinehubpaypalrest/express/returnaction'),
                    'cancel_url'          => $this->urlBuilder->getUrl('dzinehubpaypalrest/express/cancel'),
                    'user_action'         => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                ],
            ]);

            $paypalOrderId = (string)($response['id'] ?? '');
            if ($paypalOrderId === '') {
                throw new \RuntimeException('PayPal did not return an order ID.');
            }

            $order->getPayment()->setAdditionalInformation('paypal_order_id', $paypalOrderId);
            $this->orderRepository->save($order);

            $paypalUrl = sprintf(
                'https://www.%spaypal.com/checkoutnow?token=%s&useraction=commit',
                $sandbox ? 'sandbox.' : '',
                urlencode($paypalOrderId)
            );

            return $this->redirectFactory->create()->setUrl($paypalUrl);

        } catch (\Throwable $e) {
            $this->logger->error('PaypalRest Start: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('We could not connect to PayPal. Please try again or choose a different payment method.')
            );

            if ($order->getId() && $order->canCancel()) {
                $this->orderManagement->cancel((int)$order->getEntityId());
            }
            $this->checkoutSession->restoreQuote();

            return $this->redirectFactory->create()->setPath('checkout/cart');
        }
    }
}
