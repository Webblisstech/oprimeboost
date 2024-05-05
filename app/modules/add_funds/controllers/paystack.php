<?php
defined('BASEPATH') or exit('No direct script access allowed');

class paystack extends MX_Controller
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
        $this->currency_code = 'NGN';

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
        $this->currency_rate_to_usd = get_value($option, 'rate_to_usd');
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

        $amount = (double) $amount;
        $email = $users['email'];
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.paystack.co/transaction/initialize",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode([
                'amount' => $amount * 100, //the amount in kobo. This value is actually NGN 300
                'email' => $users['email'],
                'callback_url' => cn('add_funds/paystack/complete'),
            ]),
            CURLOPT_HTTPHEADER => [
                "authorization: Bearer " . $this->secret_key,
                "content-type: application/json",
                "cache-control: no-cache",
            ],
        ));
        $response = curl_exec($curl);
        $err = curl_error($curl);

        if ($err) {
            // there was an error contacting the Paystack API
            _validation('error', 'Curl returned error: ' . $err);
        }
        $tranx = json_decode($response, true);

        if (!$tranx['status']) {
            _validation('error', 'API returned error: ' . $tranx['message']);
        }
        $converted_amount = $amount / $this->currency_rate_to_usd;
        $data_tnx_log = array(
            "ids" => ids(),
            "uid" => session("uid"),
            "type" => $this->payment_type,
            "transaction_id" => $tranx['data']['reference'],
            "amount" => round($converted_amount, 4),
            'txn_fee' => round($converted_amount * ($this->payment_fee / 100), 4),
            "note" => $amount,
            "status" => 0,
            "created" => NOW,
        );
        $transaction_log_id = $this->db->insert($this->tb_transaction_logs, $data_tnx_log);
        $this->load->view("redirect", ['redirect_url' => $tranx['data']['authorization_url']]);
    }

    public function complete()
    {
        if (!isset($_GET['reference'])) {
            redirect(cn("add_funds"));
        }

        $curl = curl_init();
        $reference = isset($_GET['reference']) ? $_GET['reference'] : '';
        if (!$reference) {
            redirect(cn("add_funds"));
        }

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . rawurlencode($reference),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "accept: application/json",
                "authorization: Bearer " . $this->secret_key,
                "cache-control: no-cache",
            ],
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        if ($err) {
            redirect(cn("add_funds"));
        }
        $tranx = json_decode($response);
        if (!$tranx->status) {
            die('API returned error: ' . $tranx->message);
        }

        $transaction = $this->model->get('*', $this->tb_transaction_logs, ['transaction_id' => $reference, 'status' => 0, 'type' => $this->payment_type]);
        if ('success' == $tranx->data->status && $transaction) {

            $this->db->update($this->tb_transaction_logs, ['status' => 1], ['id' => $transaction->id]);

            // Update Balance
			$this->model->add_funds_bonus_email($transaction, $this->payment_id);

            set_session("transaction_id", $transaction->id);
            redirect(cn("add_funds/success"));

        } else {
            redirect(cn("add_funds/unsuccess"));
        }
    }

}
