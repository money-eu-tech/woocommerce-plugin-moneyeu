/**
 * MoneyEU Payments Status Polling
 *
 * Polls admin-ajax.php every 5 seconds for up to 5 minutes.
 * Runs only on the view-order page when ?moneyeu2_status=pending is present.
 */
(function ($) {
    'use strict';

    var cfg          = window.moneyeu2Polling || {};
    var MAX_POLLS    = 60;   // 60 × 5 s = 5 minutes
    var INTERVAL_MS  = 5000;
    var pollCount    = 0;
    var timer        = null;
    var $notice      = null;

    function showNotice(msg, type) {
        if ($notice) {
            $notice.remove();
        }
        var cls = type === 'error' ? 'woocommerce-error' : 'woocommerce-info';
        $notice = $('<div class="woocommerce-message ' + cls + '" role="alert">' + msg + '</div>');
        $('.woocommerce-order-details, .woocommerce').first().before($notice);
        if ($notice.offset()) {
            $('html, body').animate({ scrollTop: $notice.offset().top - 80 }, 300);
        }
    }

    function doPoll() {
        pollCount++;

        $.ajax({
            url: cfg.ajaxUrl,
            type: 'GET',
            data: {
                action:    'moneyeu2_check_order_status',
                order_id:  cfg.orderId,
                order_key: cfg.orderKey,
            },
            success: function (resp) {
                if (!resp || !resp.success) {
                    stopPolling();
                    return;
                }

                var data   = resp.data || {};
                var status = data.wc_status || '';

                if (status === 'processing' || status === 'completed') {
                    stopPolling();
                    showNotice(cfg.i18n.success, 'info');
                    setTimeout(function () {
                        window.location.href = cfg.orderReceivedUrl;
                    }, 1500);
                    return;
                }

                if (status === 'failed' || status === 'cancelled') {
                    stopPolling();
                    showNotice(cfg.i18n.failed, 'error');
                    setTimeout(function () {
                        window.location.href = cfg.checkoutUrl +
                            (cfg.checkoutUrl.indexOf('?') >= 0 ? '&' : '?') +
                            'moneyeu2_status=failed';
                    }, 2500);
                    return;
                }

                if (pollCount >= MAX_POLLS) {
                    stopPolling();
                    showNotice(cfg.i18n.timeout, 'info');
                }
            },
            error: function () {
                if (pollCount >= MAX_POLLS) {
                    stopPolling();
                    showNotice(cfg.i18n.timeout, 'info');
                }
            },
        });
    }

    function stopPolling() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    $(document).ready(function () {
        if (!cfg.ajaxUrl || !cfg.orderId) {
            return;
        }

        showNotice(cfg.i18n.checking, 'info');

        doPoll();
        timer = setInterval(doPoll, INTERVAL_MS);
    });

}(jQuery));
