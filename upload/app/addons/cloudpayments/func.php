<?php

use Tygh\Addons\RusTaxes\TaxType;
use Tygh\Registry;
use Tygh\Settings;
use Tygh\Http;

if (!defined('AREA')) {
    die('Access denied');
}

function fn_cloudpayments_install() {
    $processor_data = array(
        'processor'          => 'CloudPayments',
        'processor_script'   => 'cloudpayments.php',
        'processor_template' => 'views/orders/components/payments/cc_outside.tpl',
        'admin_template'     => 'cloudpayments.tpl',
        'callback'           => 'Y',
        'type'               => 'P',
        'position'           => 10,
        'addon'              => 'cloudpayments',
    );

    $processor_id = db_get_field(
        'SELECT processor_id FROM ?:payment_processors WHERE admin_template = ?s',
        $processor_data['admin_template']
    );

    if (empty($processor_id)) {
        db_query('INSERT INTO ?:payment_processors ?e', $processor_data);
    } else {
        db_query('UPDATE ?:payment_processors SET ?u WHERE processor_id = ?i', $processor_data, $processor_id);
    }
}

function fn_cloudpayments_uninstall() {
    $admin_tpl = 'cloudpayments.tpl';

    $payment_ids = db_get_fields(
        'SELECT a.payment_id FROM ?:payments AS a'
        . ' LEFT JOIN ?:payment_processors AS b ON a.processor_id = b.processor_id'
        . ' WHERE b.admin_template = ?s',
        $admin_tpl
    );
    foreach ($payment_ids as $payment_id) {
        fn_delete_payment($payment_id);
    }
    db_query('DELETE FROM ?:payment_processors WHERE admin_template = ?s', $admin_tpl);

    $processor_id = db_get_field("SELECT processor_id FROM ?:payment_processors WHERE admin_template = ?s", $admin_tpl);
    if (!empty($processor_id)) {
        db_query("UPDATE ?:payments SET processor_id = 0, status = 'D' WHERE processor_id = ?i", $processor_id);
    }
}

function fn_cloudpayments_callback_response($code, $msg = '') {
    header('Content-Type: application/json');
    echo json_encode(array('code' => $code, 'msg' => $msg));
}

function fn_cloudpayments_exit_with_response($code, $msg = '') {
    fn_cloudpayments_callback_response($code, $msg);
    die();
}

function fn_cloudpayments_order_placement_routines($order_id, $force_notification, $order_info, $_error){
  if(AREA == "C" && isset(Tygh::$app['session']['repay']) && Tygh::$app['session']['repay'] == $order_id){
    Tygh::$app['session']['notifications'] = [];
    if(isset($force_notification['cloudpayments'])){
      if($force_notification['cloudpayments'] == 'finish_success'){
        fn_set_notification('N', __('cloudpayments_order_desc_prefix').$order_id, __('text_order_repayed_successfully'));
      } elseif($force_notification['cloudpayments'] == 'finish_fail'){
        fn_set_notification('W', __('cloudpayments_order_desc_prefix').$order_id, __('cloudpayments_payment_failed'));
      }
    }
    $redirect_url = "orders.details&order_id=".$order_id;
    fn_redirect($redirect_url);    
  }
}

/**
 * Gets product tax data
 *
 * @param array $order_info Order information
 *
 * @return array An array of products with taxes
 */
function fn_cloudpayments_get_inventory_positions($order_info, $processor_params) {
    $map_taxes           = fn_get_schema('cloudpayments', 'map_taxes');
    $inventory_positions = array();

    /** @var \Tygh\Addons\RusTaxes\ReceiptFactory $receipt_factory */
    $receipt_factory = Tygh::$app['addons.rus_taxes.receipt_factory'];
    $receipt         = $receipt_factory->createReceiptFromOrder($order_info, CART_PRIMARY_CURRENCY);

    if ($receipt) {
        foreach ($receipt->getItems() as $item) {
            $new_inventory_position = array(
                'label'    => $item->getName(),
                'price'    => floatval(number_format((float)$item->getPrice(), 2, '.', '')),
                'quantity' => $item->getQuantity(),
                'amount'   => floatval(number_format((float)$item->getTotal(), 2, '.', '')),
                'vat'      => isset($map_taxes[$item->getTaxType()]) ? $map_taxes[$item->getTaxType()] : $map_taxes[TaxType::NONE]
            );

            if ($item->getType() == "product") {
              $product_features = fn_get_product_features(['product_id'=>$order_info['products'][$item->getId()]['product_id']]);
              foreach ($product_features[0] as $feature):
                if ($feature['feature_code'] == "SPIC") $new_inventory_position['spic'] = $feature['value'];
                if ($feature['feature_code'] == "PACKAGE_CODE") $new_inventory_position['packageCode'] = $feature['value'];
              endforeach;
            }

            if ($item->getType() == "shipping") {
              $new_inventory_position['spic'] = $processor_params['spic'];
              $new_inventory_position['packageCode'] = $processor_params['package_code'];
            }

            $inventory_positions[] = $new_inventory_position;
        }
    }

    return $inventory_positions;
}
