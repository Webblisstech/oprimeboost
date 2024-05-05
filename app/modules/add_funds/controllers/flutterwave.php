<?php
defined('BASEPATH') or exit('No direct script access allowed');

class flutterwave extends MX_Controller
{
    public $tb_users;
    public $tb_transaction_logs;
    public $tb_payments;
    public $tb_payments_bonuses;
    public $paypal;
    public $payment_type;
    public $payment_id;
    public $currency_code;
    public $payment_lib;
    public $mode;

    public $public_key;
    public $pm_alternate_pass;

    public function __construct($payment = "")
    {
        parent::__construct();
        $this->load->model('add_funds_model', 'model');

        $this->tb_users = USERS;
        $this->tb_transaction_logs = TRANSACTION_LOGS;
        $this->tb_payments = PAYMENTS_METHOD;
        $this->tb_payments_bonuses = PAYMENTS_BONUSES;
        $this->payment_type = get_class($this);

        if (!$payment) {
            $payment = $this->model->get('id, type, name, params', $this->tb_payments, ['type' => $this->payment_type]);
        }
        $this->payment_id = $payment->id;
        $params = $payment->params;
        $option = get_value($params, 'option');
        $this->mode = get_value($option, 'environment');
        $this->payment_fee = get_value($option, 'tnx_fee');

        // Payment Option
        $this->public_key = get_value($option, 'public_key');
        $this->secret_key = get_value($option, 'secret_key');
        $this->currency_code = get_value($option, 'currency_code');
        if ($this->currency_code == "") {
            $this->currency_code = 'USD';
        }
        $this->currency_rate_to_usd = (int) get_value($option, 'rate_to_usd');
        if ($this->currency_rate_to_usd <= 0) {
            $this->currency_rate_to_usd = 1;
        }
    }

    public function index()
    {
        redirect(cn('add_funds'));
    }

    /**
     *
     * Create payment
     *
     */
    public function create_payment($data_payment = "")
    {
        _is_ajax($data_payment['module']);
        $amount = $data_payment['amount'];
        if (!$amount) {
            _validation('error', lang('There_was_an_error_processing_your_request_Please_try_again_later'));
        }

        if (!$this->public_key || !$this->secret_key) {
            _validation('error', lang('this_payment_is_not_active_please_choose_another_payment_or_contact_us_for_more_detail'));
        }

        $users = session('user_current_info');

        $amount = (float) $amount;
        $email = $users['email'];

        // My Order Data
        $orderId = strtotime(NOW);
        $paramList = [
            "public_key" => $this->public_key,
            "tx_ref" => 'bitethtx-' . $orderId,
            "amount" => $amount,
            "currency" => $this->currency_code,
            "meta_token" => '54',
            "name" => $users['last_name'] . ' ' . $users['first_name'],
            "email" => $users['email'],
            "phone" => '0771234567',
            "redirect_url" => cn("add_funds"),
        ];

        $data_redirect = [
            "paramList" => $paramList,
        ];
        if (strtolower($this->mode) == 'live') {
            $data_redirect['action_url'] = 'https://checkout.flutterwave.com/v3/hosted/pay';
        } else {
            $data_redirect['action_url'] = 'https://checkout.flutterwave.com/v3/hosted/pay';
        }
        $converted_amount = $amount / $this->currency_rate_to_usd;
        $data_tnx_log = array(
            "ids" => ids(),
            "uid" => session("uid"),
            "type" => $this->payment_type,
            "transaction_id" => $paramList['tx_ref'],
            "amount" => round($converted_amount, 4),
            'txn_fee' => round($converted_amount * ($this->payment_fee / 100), 4),
            "note" => $amount,
            "status" => 0,
            "created" => NOW,
        );
        $this->db->insert($this->tb_transaction_logs, $data_tnx_log);
        $transaction_id = $this->db->insert_id();
        if ($this->db->affected_rows() != 1) {
            _validation('error', 'Failed To created Payment');
        } else {
            $this->load->view($this->payment_type . "/redirect", $data_redirect);
        }
    }

    public function ipn()
    {
        $body = @file_get_contents("php://input");
        $response = json_decode($body);
        file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/fl_ipn.txt', $body . PHP_EOL, FILE_APPEND);
        http_response_code(200);
        if (!isset($response->event)) {
            exit('Invalid payment');
        }
        $event = $response->event;
        $data = $response->data;
        $transaction = $this->model->get('*', $this->tb_transaction_logs, ['transaction_id' => $data->tx_ref, 'status' => 0, 'note' => $data->amount, 'type' => $this->payment_type]);

        if (!$transaction) {
            exit('Transaction ID does not exists!');
        }
        // verify payment
        $params = [
            'tnx_id' => $data->id,
            'amount' => $data->amount,
            'tx_ref' => $data->tx_ref,
        ];
        $verify_payment = $this->verify_payment($params);
        if (!$verify_payment) {
            exit('Transaction ID does not exists!');
        }
        if ('successful' == $data->status && $transaction && $event == 'charge.completed' && $verify_payment) {
            $data_tnx_log = [
                'status' => 1,
                'transaction_id' => $data->id,
                'data' => $body,
            ];
            $this->db->update($this->tb_transaction_logs, $data_tnx_log, ['id' => $transaction->id]);
            $this->model->add_funds_bonus_email($transaction, $this->payment_id);
            file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/fl_ipn_complete.txt', json_encode($data_tnx_log) . PHP_EOL, FILE_APPEND);
            exit('successfully');
        } else {
            exit('Transaction invalid');
        }
    }

    private function verify_payment($params)
    {
        $verify_url = "https://api.flutterwave.com/v3/transactions/" . $params['tnx_id'] . "/verify";

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $verify_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json",
                "Authorization: Bearer " . $this->secret_key,
            ),
        ));
        $response = curl_exec($curl);
        $err = curl_error($curl);
        if ($err) {
            return false;
        }
        $tranx = json_decode($response);
        if (!$tranx->status) {
            return false;
        }
        if ($tranx->status == 'success' && 'successful' == $tranx->data->status && $tranx->data->amount == $params['amount'] && $tranx->data->tx_ref == $params['tx_ref']) {
            return true;
        } else {
            return false;
        }
    }
}
