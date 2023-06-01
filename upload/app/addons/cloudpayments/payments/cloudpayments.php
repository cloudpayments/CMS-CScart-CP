<?php

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

const CLOUDPAYMENTS_RESULT_SUCCESS             = 0;
const CLOUDPAYMENTS_RESULT_ERROR_INVALID_ORDER = 10;
const CLOUDPAYMENTS_RESULT_ERROR_INVALID_COST  = 12;
const CLOUDPAYMENTS_RESULT_ERROR_NOT_ACCEPTED  = 13;
const CLOUDPAYMENTS_RESULT_ERROR_EXPIRED       = 20;

if (defined('PAYMENT_NOTIFICATION')) {
    if (in_array($mode, array('finish_success', 'finish_fail'))) {
        //Redirect from widget
        $force_notification = [
          'cloudpayments' => $mode
        ];
        fn_order_placement_routines('route', $_REQUEST['order_id'], $force_notification);
        exit;
    }
    writeLogCloudPayments($_REQUEST, 'CloudPayments.log');

    if (empty($_POST['InvoiceId'])) {
        fn_cloudpayments_exit_with_response(CLOUDPAYMENTS_RESULT_ERROR_NOT_ACCEPTED, 'Invalid POST data');
    }

    if (in_array($mode, array('check', 'pay', 'confirm')) && empty($_POST['Amount'])) {
        fn_cloudpayments_exit_with_response(CLOUDPAYMENTS_RESULT_ERROR_NOT_ACCEPTED, 'Invalid POST data');
    }
    $Status_pay     = $_POST['Status'];
    $order_id       = (int)$_POST['InvoiceId'];
    $payment_id     = db_get_field("SELECT payment_id FROM ?:orders WHERE order_id = ?i", $order_id);
    $processor_data = fn_get_processor_data($payment_id);
    if (!$processor_data) {
        fn_cloudpayments_exit_with_response(CLOUDPAYMENTS_RESULT_ERROR_INVALID_ORDER, 'Order not found');
    }

    // Check sign
    $postData    = file_get_contents('php://input');
    $checkSign   = base64_encode(hash_hmac('SHA256', $postData, $processor_data['processor_params']['secret_key'], true));
    $requestSign = isset($_SERVER['HTTP_CONTENT_HMAC']) ? $_SERVER['HTTP_CONTENT_HMAC'] : '';

    if ($checkSign !== $requestSign) {
        fn_cloudpayments_exit_with_response(CLOUDPAYMENTS_RESULT_ERROR_NOT_ACCEPTED, 'Invalid sign');
    }

    if($_POST['PaymentCurrency'] != $processor_data['processor_params']['currency']){
      writeLogCloudPayments(["Курс транзакции" => $_POST['PaymentCurrency']], 'CloudPayments.log');
      fn_cloudpayments_exit_with_response(CLOUDPAYMENTS_RESULT_ERROR_INVALID_COST, 'Amount not corrected');
    }

    writeLogCloudPayments(["Проверки" => "Пройдены"], 'CloudPayments.log');

    switch ($mode) {
        case 'check':
            $valid_id = db_get_field("SELECT order_id FROM ?:order_data WHERE order_id = ?i AND type = 'S'", $order_id);
            if (empty($valid_id)) {
                $idata = array (
                    'order_id' => $order_id,
                    'type' => 'S',
                    'data' => TIME,
                );
                db_query("REPLACE INTO ?:order_data ?e", $idata);
            }
            $total = db_get_field('SELECT total FROM ?:orders WHERE order_id = ?i', $order_id);
            if(!$total){
              fn_cloudpayments_exit_with_response(CLOUDPAYMENTS_RESULT_ERROR_INVALID_ORDER, 'Order not found');
            } elseif($total != $_POST['Amount']){
              writeLogCloudPayments([$total => $_POST['Amount']], 'CloudPayments.log');
              fn_cloudpayments_exit_with_response(CLOUDPAYMENTS_RESULT_ERROR_INVALID_COST, 'Amount not corrected');
            }
            break;
        case 'pay':
            if ($Status_pay == 'Completed') {
                $pp_response['order_status'] = $processor_data['processor_params']['statuses']['paid'];
                $pp_response['reason_text']  = __('approved');
                fn_finish_payment($order_id, $pp_response);
            };
            if ($Status_pay == 'Authorized') {
                $pp_response['order_status'] = $processor_data['processor_params']['statuses']['confirm'];
                $pp_response['reason_text']  = __('cloudpayments_payment_confirm');
                fn_finish_payment($order_id, $pp_response);
            };
            break;
        case 'confirm':
                $pp_response['order_status'] = $processor_data['processor_params']['statuses']['paid'];
                $pp_response['reason_text']  = __('approved');
                fn_update_order_payment_info($order_id, $pp_response);
                fn_change_order_status($order_id, $pp_response['order_status']);
            break;
        case 'fail':
            $pp_response['order_status'] = $processor_data['processor_params']['statuses']['failed'];
            $pp_response['reason_text']  = __('cloudpayments_payment_failed');
            fn_finish_payment($order_id, $pp_response);
            break;
        case 'refund':
            $pp_response['order_status'] = $processor_data['processor_params']['statuses']['refunded'];
            $pp_response['reason_text']  = __('refunded');
            fn_update_order_payment_info($order_id, $pp_response);
            fn_change_order_status($order_id, $pp_response['order_status']);
            break;
        case 'cancel':
            $pp_response['order_status'] = $processor_data['processor_params']['statuses']['cancel'];
            $pp_response['reason_text']  = __('cloudpayments_payment_canceled');
            fn_update_order_payment_info($order_id, $pp_response);
            fn_change_order_status($order_id, $pp_response['order_status']);
            break;
        default:
            fn_cloudpayments_exit_with_response(CLOUDPAYMENTS_RESULT_ERROR_NOT_ACCEPTED, 'Invalid action');
    }

    fn_cloudpayments_callback_response(CLOUDPAYMENTS_RESULT_SUCCESS);
} else {
    /** @var array $processor_data */
    /** @var array $order_info */

    if(isset(Tygh::$app['session']['cart']['placement_action']) && Tygh::$app['session']['cart']['placement_action'] == "repay"){
      Tygh::$app['session']['repay'] = $order_id;
    }

    $total          = fn_format_price_by_currency($order_info['total'], CART_PRIMARY_CURRENCY, $processor_data['processor_params']['currency']);
    $user_id        = (!empty($order_info['user_id'])) ? $order_info['user_id'] : 0;
    $order_id       = (!empty($order_id)) ? $order_id : 0;
    $customer_email = $order_info['email'];
    $customer_phone = $order_info['phone'];
    $customer_name  = trim($order_info['b_firstname'] . ' ' . $order_info['b_lastname']);
    $widget_lang    = $processor_data['processor_params']['language'];
    $description    = __('cloudpayments_order_desc_prefix') . $order_id;
    $success_url    = fn_url('payment_notification.finish_success?payment=cloudpayments&order_id=' . $order_id, 'C', 'current');
    $fail_url       = fn_url('payment_notification.finish_fail?payment=cloudpayments&order_id=' . $order_id, 'C', 'current');
    $payment_scheme = $processor_data['processor_params']['payment_scheme'];

    $widget_params = array(
        "publicId"    => $processor_data['processor_params']['public_id'],  //id из личного кабинета
        "description" => $description, //назначение
        "amount"      => floatval(number_format((float)$total, 2, '.', '')), //сумма
        "currency"    => $processor_data['processor_params']['currency'], //валюта
        "invoiceId"   => $order_id, //номер заказа  (необязательно)
        "accountId"   => $customer_email, //идентификатор плательщика (необязательно)
        "email"       => $customer_email,
	      "skin"        => $processor_data['processor_params']['skin'],
        "retryPayment" => true,
        "data"        => array(
            "name"          => $customer_name,
            "phone"         => $customer_phone,
            "cloudPayments" => array(),
        )
    );

    if (isset($processor_data['processor_params']['receipt']) && $processor_data['processor_params']['receipt'] == 'Y') {
        $receipt_data = array(
			'Items'            => fn_cloudpayments_get_inventory_positions($order_info, $processor_data['processor_params']),
            'calculationPlace' => 'www.'.$_SERVER['SERVER_NAME'],
	          'taxationSystem'   => $processor_data['processor_params']['taxation_system'],
            'email'            => $customer_email,
            'phone'            => $customer_phone,
            'amounts'          => array('electronic'=> floatval(number_format((float)$order_info['total'], 2, '.', '')))
        );

		if (isset($receipt_data['Items'][0]['spic']) && isset($receipt_data['Items'][0]['packageCode']))
			$receipt_data['AdditionalReceiptInfos'] = array("Вы стали обладателем права на 1% cashback");

        $widget_params['data']['cloudPayments']['customerReceipt'] = $receipt_data;
    }

    $widget_params = json_encode($widget_params);

    $widget_script = <<<SCRIPT
<html>
    <head>
        <title>{$description}</title>
        <meta http-equiv='Content-Type' content='text/html; charset=UTF-8>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    </head>
    <body>
        <script src="https://widget.cloudpayments.ru/bundles/cloudpayments?cms=CScart"></script>
        <script>
            var widget = new cp.CloudPayments({language: '{$widget_lang}'});
            widget.{$payment_scheme}({$widget_params}, '{$success_url}', '{$fail_url}');
        </script>
    </body>
</html>
SCRIPT;

    echo $widget_script;
}



function writeLogCloudPayments($data, $file = 'CloudPayments.log')
{
    $path = fn_get_files_dir_path();
    fn_mkdir($path);
    $file = fopen($path . $file, 'a');

    if (!empty($file)) {
        fputs($file, 'TIME: ' . date('Y-m-d H:i:s', TIME) . "\n");
        fputs($file, fn_array2code_string($data) . "\n\n");
        fclose($file);
    }
}

exit;
