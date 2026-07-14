# PayPal REST Checkout for Magento 2

A standalone PayPal REST Orders v2 payment module for Magento 2. Uses only the modern REST API — no dependency on `Magento_Paypal`, SOAP, or the deprecated NVP/SOAP stack that PayPal is retiring.

## Features

- PayPal REST Orders v2 API (`/v2/checkout/orders`)
- Server-side redirect checkout flow — no JS SDK required
- Automatic invoice generation on successful capture
- Online refunds (full and partial) via credit memo
- Admin transaction detail page with PayPal capture information
- Sandbox / Live toggle in admin config
- No `Magento_Paypal` dependency

## Requirements

- Magento 2.4.4 or later
- PHP 8.1 or later
- PayPal REST API credentials (Client ID + Secret from [developer.paypal.com](https://developer.paypal.com))

## Installation

### Via Composer (recommended)

```bash
composer require dzinehub/magento2-paypal-rest-checkout
php bin/magento module:enable Dzinehub_PaypalRest
php bin/magento setup:upgrade
php bin/magento cache:clean
```

### Manual

1. Copy the module to `app/code/Dzinehub/PaypalRest/`
2. Run:
   ```bash
   php bin/magento module:enable Dzinehub_PaypalRest
   php bin/magento setup:upgrade
   php bin/magento cache:clean
   ```

## Configuration

**Stores > Configuration > Dzinehub > PayPal REST Checkout**

| Field | Description |
|-------|-------------|
| Enabled | Enable/disable the payment method |
| Payment Title | Label shown at checkout (default: "PayPal") |
| Sort Order | Position in the payment method list |
| Payment from Applicable Countries | Restrict to specific countries |
| Use Sandbox | Enable for testing; disable for production |
| Client ID | From developer.paypal.com REST app |
| Client Secret | From developer.paypal.com REST app |

## Checkout Flow

1. Customer selects PayPal at checkout and clicks **Place Order**
2. Magento creates the order in `pending_payment` state
3. Module creates a PayPal Orders v2 order and redirects the customer to PayPal
4. Customer approves on PayPal and is returned to the store
5. Module captures the payment, generates an invoice, and moves the order to `processing`
6. Order confirmation email is sent

## Refunds

Full and partial refunds are processed via **Sales > Orders > Credit Memo** in the Magento admin. The module calls `/v2/payments/captures/{id}/refund` on PayPal automatically.

## Support

- [Dzine Hub](https://dzine-hub.com)
- Email: mohan@dzine-hub.com

## License

[OSL-3.0](LICENSE.txt)
