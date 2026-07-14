define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'dzinehub_paypalrest',
        component: 'Dzinehub_PaypalRest/js/view/payment/method-renderer/paypalrest'
    });

    return Component.extend({});
});
