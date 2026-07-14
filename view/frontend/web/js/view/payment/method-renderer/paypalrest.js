define([
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function (Component, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Mohan_PaypalRest/payment/paypalrest',
            redirectAfterPlaceOrder: false
        },

        getCode: function () {
            return 'mohan_paypalrest';
        },

        /**
         * After Magento places the order (pending_payment state), redirect to our
         * Start controller which creates the PayPal order and forwards to PayPal.
         */
        afterPlaceOrder: function () {
            window.location.replace(url.build('mohanpaypalrest/express/start'));
        }
    });
});
