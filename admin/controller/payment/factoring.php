<?php
if (!defined('DIR_APPLICATION')) {
    die();
}

require_once DIR_SYSTEM . 'library/Px/Px.php';

class ControllerPaymentFactoring extends Controller
{
    protected static $_px;

    private $error = array();

    protected $_module_name = 'factoring';

    protected $_options = array(
        'factoring_account_number',
        'factoring_encryption_key',
        'factoring_mode',
        'factoring_type',
        'factoring_total',
        'factoring_completed_status_id',
        'factoring_pending_status_id',
        'factoring_canceled_status_id',
        'factoring_failed_status_id',
        'factoring_refunded_status_id',
        'factoring_geo_zone_id',
        'factoring_status',
        'factoring_sort_order',
    );

    protected $_texts = array(
        'button_save',
        'button_cancel',
        'button_credit',
        'heading_title',
        'text_settings',
        'text_transactions',
        'text_pending_transactions',
        'text_account_number',
        'text_encryption_key',
        'text_mode',
        'text_type',
        'text_transactiontype',
        'text_total',
        'text_complete_status',
        'text_pending_status',
        'text_canceled_status',
        'text_failed_status',
        'text_refunded_status',
        'text_geo_zone',
        'text_all_zones',
        'text_status',
        'text_enabled',
        'text_disabled',
        'text_sort_order',
        'text_success',
        'text_order_id',
        'text_transaction_id',
        'text_date',
        'text_transaction_status',
        'text_actions',
        'text_wait',
        'text_capture',
        'text_cancel',
        'text_refund',
        'text_captured',
        'text_canceled',
        'text_refunded',
    );

    /**
     * Index Action
     */
    function index()
    {
        $this->load->language('payment/' . $this->_module_name);
        $this->load->model('setting/setting');

        // Install DB Tables
        $this->load->model('module/factoring');
        $this->model_module_factoring->createModuleTables();

        $data['currency'] = $this->currency;

        $this->document->setTitle($this->language->get('heading_title'));

        // Load texts
        foreach ($this->_texts as $text) {
            $data[$text] = $this->language->get($text);
        }

        // Load options
        foreach ($this->_options as $option) {
            if (isset($this->request->post[$option])) {
                $data[$option] = $this->request->post[$option];
            } else {
                $data[$option] = $this->config->get($option);
            }
        }

        // Load config
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $data['action'] = $this->url->link('payment/' . $this->_module_name, 'token=' . $this->session->data['token'], 'SSL');
        $data['cancel'] = $this->url->link('extension/payment', 'token=' . $this->session->data['token'], 'SSL');
        $data['error'] = $this->error;

        if (($this->request->server['REQUEST_METHOD'] === 'POST')) {
            if (isset($this->request->post['action'])) {
                $this->load->model('sale/order');

                $order_id = $this->request->post['order_id'];
                $transaction_id = $this->request->post['transaction_id'];
                $order = $this->model_sale_order->getOrder($order_id);

                if (!$order) {
                    $json = array(
                        'status' => 'error',
                        'message' => 'Invalid order Id'
                    );
                    $this->response->setOutput(json_encode($json));
                    return;
                }

                switch ($this->request->post['action']) {
                    case 'capture':
                        // Call PxOrder.Capture5
                        $params = array(
                            'accountNumber' => '',
                            'transactionNumber' => $transaction_id,
                            'amount' => round(100 * $order['total']),
                            'orderId' => $order_id,
                            'vatAmount' => 0,
                            'additionalValues' => 'FINANCINGINVOICE_ORDERLINES=' . urlencode($this->getInvoiceExtraPrintBlocksXML($order_id))
                        );
                        $result = $this->getPx()->Capture5($params);
                        if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
                            $json = array(
                                'status' => 'error',
                                'message' => $result['errorCode'] . ' (' . $result['description'] . ')'
                            );
                            $this->response->setOutput(json_encode($json));
                            return;
                        }

                        // Save Transaction
                        $this->model_module_factoring->addTransaction($order_id, $result['transactionNumber'], $result['transactionStatus'], $result, isset($result['date']) ? strtotime($result['date']) : time());
                        $this->model_module_factoring->setAsCaptured($transaction_id);

                        // Set Order Status
                        $order_status_id = $data['factoring_completed_status_id'];
                        $this->db->query("UPDATE `" . DB_PREFIX . "order` SET order_status_id = '" . (int)$order_status_id . "', date_modified = NOW() WHERE order_id = '" . (int)$order_id . "'");
                        $this->db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = '" . (int)$order_id . "', order_status_id = '" . (int)$order_status_id . "', date_added = NOW()");

                        // Create Invoice Number
                        $this->model_sale_order->createInvoiceNo($order_id);

                        $json = array(
                            'status' => 'ok',
                            'message' => 'Order successfully captured.',
                            'label' => $data['text_captured']
                        );
                        $this->response->setOutput(json_encode($json));
                        return;
                    case 'cancel':
                        // Call PxOrder.Cancel2
                        $params = array(
                            'accountNumber' => '',
                            'transactionNumber' => $transaction_id
                        );
                        $result = $this->getPx()->Cancel2($params);
                        if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
                            $json = array(
                                'status' => 'error',
                                'message' => $result['errorCode'] . ' (' . $result['description'] . ')'
                            );
                            $this->response->setOutput(json_encode($json));
                            return;
                        }

                        // Save Transaction
                        $this->model_module_factoring->addTransaction($order_id, $result['transactionNumber'], $result['transactionStatus'], $result, isset($result['date']) ? strtotime($result['date']) : time());
                        $this->model_module_factoring->setAsCanceled($transaction_id);

                        // Set Order Status
                        $order_status_id = $data['factoring_canceled_status_id'];
                        $this->db->query("UPDATE `" . DB_PREFIX . "order` SET order_status_id = '" . (int)$order_status_id . "', date_modified = NOW() WHERE order_id = '" . (int)$order_id . "'");
                        $this->db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = '" . (int)$order_id . "', order_status_id = '" . (int)$order_status_id . "', date_added = NOW()");

                        $json = array(
                            'status' => 'ok',
                            'message' => 'Order successfully canceled.',
                            'label' => $data['text_canceled']
                        );
                        $this->response->setOutput(json_encode($json));
                        return;
                    case 'refund':
                        $total_refunded = $this->request->post['total_refunded'];

                        // Call PxOrder.Credit5
                        $params = array(
                            'accountNumber' => '',
                            'transactionNumber' => $transaction_id,
                            'amount' => round(100 * $total_refunded),
                            'orderId' => $order_id,
                            'vatAmount' => 0,
                            'additionalValues' => ''
                        );
                        $result = $this->getPx()->Credit5($params);
                        if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
                            $json = array(
                                'status' => 'error',
                                'message' => $result['errorCode'] . ' (' . $result['description'] . ')'
                            );
                            $this->response->setOutput(json_encode($json));
                            return;
                        }

                        // Save Transaction
                        $this->model_module_factoring->addTransaction($order_id, $result['transactionNumber'], $result['transactionStatus'], $result, isset($result['date']) ? strtotime($result['date']) : time());
                        $this->model_module_factoring->setAsRefunded($transaction_id, $total_refunded);

                        // Set Order Status
                        $order_status_id = $data['factoring_refunded_status_id'];
                        $this->db->query("UPDATE `" . DB_PREFIX . "order` SET order_status_id = '" . (int)$order_status_id . "', date_modified = NOW() WHERE order_id = '" . (int)$order_id . "'");
                        $this->db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = '" . (int)$order_id . "', order_status_id = '" . (int)$order_status_id . "', date_added = NOW()");

                        $json = array(
                            'status' => 'ok',
                            'message' => 'Order successfully refunded.',
                            'label' => $data['text_refunded']
                        );
                        $this->response->setOutput(json_encode($json));
                        return;
                    default:
                        //
                }
            }

            if ($this->validate()) {
                $this->save();
            }
        }

        // Breadcrumbs
        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/home', 'token=' . $this->session->data['token'], 'SSL'),
            'separator' => false
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_payment'),
            'href' => $this->url->link('extension/payment', 'token=' . $this->session->data['token'], 'SSL'),
            'separator' => ' :: '
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('payment/' . $this->_module_name, 'token=' . $this->session->data['token'], 'SSL'),
            'separator' => ' :: '
        );

        $query = sprintf("SELECT * FROM %sfactoring_transactions ORDER BY order_id DESC;", DB_PREFIX);
        $transactions = $this->db->query($query);
        foreach ($transactions->rows as $_key => &$transaction) {
            $transaction['order_link'] = $this->url->link('sale/order/info', 'token=' . $this->session->data['token'] . '&order_id=' . $transaction['order_id'], 'SSL');
        }
        $data['transactions'] = $transactions->rows;

        $query = sprintf("SELECT * FROM %sfactoring_transactions WHERE transaction_status = %d AND is_captured <> 1 AND is_canceled <> 1 ORDER BY date DESC;", DB_PREFIX, 3);
        $pending_transactions = $this->db->query($query);
        foreach ($pending_transactions->rows as $_key => &$transaction) {
            $transaction['order_link'] = $this->url->link('sale/order/info', 'token=' . $this->session->data['token'] . '&order_id=' . $transaction['order_id'], 'SSL');
        }
        $data['pending_transactions'] = $pending_transactions->rows;

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('payment/factoring.tpl', $data));
    }

    /**
     * Validate configuration
     */
    private function validate()
    {
        if (!$this->user->hasPermission('modify', 'payment/' . $this->_module_name)) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        $account_number = $this->request->post['factoring_account_number'];
        $encryption_key = $this->request->post['factoring_encryption_key'];

        if ($account_number && !filter_var($account_number, FILTER_VALIDATE_INT)) {
            $this->error['factoring_account_number'] = $this->language->get('error_account_number');
        }

        if (empty($encryption_key)) {
            $this->error['factoring_encryption_key'] = $this->language->get('error_encryption_key');
        }

        return !$this->error;
    }

    /**
     * Save configuration
     */
    protected function save()
    {
        $data = array();
        foreach ($this->_options as $option) {
            $data[$option] = $this->request->post[$option];
        }
        $this->model_setting_setting->editSetting($this->_module_name, $data);

        $this->session->data['success'] = $this->language->get('text_success');
        $this->response->redirect($this->url->link('extension/payment', 'token=' . $this->session->data['token'], 'SSL'));
    }

    /**
     * Get PayEx Handler
     * @return Px
     */
    protected function getPx()
    {
        if (is_null(self::$_px)) {
            $account_number = $this->config->get('factoring_account_number');
            $encryption_key = $this->config->get('factoring_encryption_key');
            $mode = $this->config->get('factoring_mode');
            self::$_px = new Px();
            self::$_px->setEnvironment($account_number, $encryption_key, ($mode !== 'LIVE'));
        }

        return self::$_px;
    }

    /**
     * Generate Invoice Print XML
     * @param $order_id
     * @return mixed
     */
    protected function getInvoiceExtraPrintBlocksXML($order_id)
    {
        $dom = new DOMDocument('1.0', 'utf-8');
        $OnlineInvoice = $dom->createElement('OnlineInvoice');
        $dom->appendChild($OnlineInvoice);
        $OnlineInvoice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $OnlineInvoice->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsd', 'http://www.w3.org/2001/XMLSchema');

        $OrderLines = $dom->createElement('OrderLines');
        $OnlineInvoice->appendChild($OrderLines);

        $this->load->model('sale/order');

        // Add Totals
        // Note: At moment Opencart can't provide good content of order
        $totals = $this->model_sale_order->getOrderTotals($order_id);
        foreach ($totals as $key => $total)
        {
            if ($total['code'] === 'total') {
                continue;
            }

            $OrderLine = $dom->createElement('OrderLine');
            $OrderLine->appendChild($dom->createElement('Product', $total['title']));
            $OrderLine->appendChild($dom->createElement('Qty', 1));
            $OrderLine->appendChild($dom->createElement('UnitPrice', round($total['value'], 2)));
            $OrderLine->appendChild($dom->createElement('VatRate', 0));
            $OrderLine->appendChild($dom->createElement('VatAmount', 0));
            $OrderLine->appendChild($dom->createElement('Amount', round($total['value'], 2)));
            $OrderLines->appendChild($OrderLine);
        }

        return str_replace("\n", '', $dom->saveXML());
    }
}