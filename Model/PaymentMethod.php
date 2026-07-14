<?php
declare(strict_types=1);

namespace Mohan\PaypalRest\Model;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;

class PaymentMethod extends AbstractMethod
{
    public const CODE = 'mohan_paypalrest';

    protected $_code                    = self::CODE;
    protected $_isGateway               = true;
    protected $_canCapture              = true;
    protected $_canRefund               = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canUseCheckout          = true;
    protected $_canUseInternal          = false;

    private Client $client;
    private UrlInterface $urlBuilder;

    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentHelper $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        Client $client,
        UrlInterface $urlBuilder,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->client     = $client;
        $this->urlBuilder = $urlBuilder;
        parent::__construct(
            $context, $registry, $extensionFactory, $customAttributeFactory,
            $paymentData, $scopeConfig, $logger, $resource, $resourceCollection, $data
        );
    }

    /**
     * Used by legacy Onepage controller to redirect after order placement.
     * The KO-based checkout uses the JS renderer's afterPlaceOrder instead.
     */
    public function getOrderPlaceRedirectUrl(): string
    {
        return $this->urlBuilder->getUrl('mohanpaypalrest/express/start');
    }

    /**
     * Called when admin creates an invoice with "Capture Online".
     * During the normal checkout flow the capture is done in the ReturnAction controller,
     * so by the time this is called a capture ID should already be recorded.
     */
    public function capture(InfoInterface $payment, $amount): self
    {
        $captureId = $payment->getAdditionalInformation('paypal_capture_id');
        if ($captureId) {
            $payment->setTransactionId($captureId)->setIsTransactionClosed(false);
            return $this;
        }

        throw new LocalizedException(
            __('This PayPal order has not been approved by the customer yet.')
        );
    }

    public function refund(InfoInterface $payment, $amount): self
    {
        $captureId = $payment->getParentTransactionId()
            ?: $payment->getAdditionalInformation('paypal_capture_id');

        if (!$captureId) {
            throw new LocalizedException(__('Cannot refund: no PayPal capture ID found.'));
        }

        $order      = $payment->getOrder();
        $grandTotal = (float)$order->getGrandTotal();
        $isPartial  = abs((float)$amount - $grandTotal) > 0.01;

        $payload = [];
        if ($isPartial) {
            $payload['amount'] = [
                'value'         => number_format((float)$amount, 2, '.', ''),
                'currency_code' => $order->getOrderCurrencyCode(),
            ];
        }

        $token = $this->client->getAccessToken();
        [, $response] = $this->client->postJson(
            '/v2/payments/captures/' . $captureId . '/refund',
            $token,
            $payload
        );

        $payment->setTransactionId($response['id'] ?? '')
            ->setIsTransactionClosed(true);

        return $this;
    }
}
