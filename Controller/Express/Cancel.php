<?php
declare(strict_types=1);

namespace Mohan\PaypalRest\Controller\Express;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Psr\Log\LoggerInterface;

class Cancel implements HttpGetActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderManagementInterface $orderManagement,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {}

    public function execute()
    {
        $order = $this->checkoutSession->getLastRealOrder();

        if ($order->getId() && $order->canCancel()) {
            try {
                $this->orderManagement->cancel((int)$order->getEntityId());
            } catch (\Throwable $e) {
                $this->logger->error('PaypalRest Cancel: ' . $e->getMessage());
            }
        }

        $this->checkoutSession->restoreQuote();
        $this->messageManager->addNoticeMessage(__('You have cancelled your PayPal payment.'));

        return $this->redirectFactory->create()->setPath('checkout/cart');
    }
}
