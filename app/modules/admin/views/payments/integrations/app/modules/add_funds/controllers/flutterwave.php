<?php
defined('BASEPATH') OR exit('No direct script access allowed');
 
class flutterwave extends MX_Controller {
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

	public function __construct($payment = ""){
		parent::__construct();
		$this->load->model('add_funds_model', 'model');

		$this->tb_users            = USERS;
		$this->tb_transaction_logs = TRANSACTION_LOGS;
		$this->tb_payments         = PAYMENTS_METHOD;
		$this->tb_payments_bonuses = PAYMENTS_BONUSES;
		$this->payment_type		   = get_class($this);
		$this->currency_code       = 'NGN';
		
		if (!$payment) {
			$payment = $this->model->get('id, type, name, params', $this->tb_payments, ['type' => $this->payment_type]);
		}
		$this->payment_id 	      = $payment->id;
		$params  			      = $payment->params;
		$option                   = get_value($params, 'option');
		$this->mode               = get_value($option, 'environment');
		$this->payment_fee        = get_value($option, 'tnx_fee');

		// Payment Option
		$this->public_key      		= get_value($option, 'public_key');
		$this->secret_key      		= get_value($option, 'secret_key');
		$this->currency_rate_to_usd = get_value($option, 'rate_to_usd');
	}

	public function index(){
		redirect(cn('add_funds'));
	}

	/**
	 *
	 * Create payment
	 *
	 */
	public function create_payment($data_payment = ""){
		_is_ajax($data_payment['module']);
		$amount = $data_payment['amount'];
		if (!$amount) {
			_validation('error', lang('There_was_an_error_processing_your_request_Please_try_again_later'));
		}

		if (!$this->public_key || !$this->secret_key) {
			_validation('error', lang('this_payment_is_not_active_please_choose_another_payment_or_contact_us_for_more_detail'));
		}

        $users = session('user_current_info');

		$amount = (double)$amount;
		$email  = $users['email'];
        
        // My Order Data
		$orderId   = strtotime(NOW);
		$paramList = [
			"public_key" 		    => $this->public_key,
			"tx_ref" 	            => 'bitethtx-' . $orderId,
			"amount" 			    => $amount,
			"currency" 	            => $this->currency_code,
			"meta_token" 	        => '54',
			"name" 	                => $users['last_name']. ' ' . $users['first_name'],
			"email" 	            => $users['email'],
			"phone" 	            => '0771234567',
			"redirect_url" 	        => cn("add_funds/flutterwave/complete"),
		];

		$data_redirect = [
			"paramList" 	         => $paramList,
		];
		if ( strtolower($this->mode) == 'live' ) {
			$data_redirect['action_url'] = 'https://checkout.flutterwave.com/v3/hosted/pay' ;
		}else{
			$data_redirect['action_url'] = 'https://checkout.flutterwave.com/v3/hosted/pay' ;
		}
		$converted_amount = $amount / $this->currency_rate_to_usd;
		$data_tnx_log = array(
			"ids" 				=> ids(),
			"uid" 				=> session("uid"),
			"type" 				=> $this->payment_type,
			"transaction_id" 	=> $paramList['tx_ref'],
			"amount" 	        => round($converted_amount, 4) ,
			'txn_fee'           => round($converted_amount * ($this->payment_fee / 100), 4),
			"note" 	            => $amount,
			"status" 	        => 0,
			"created" 			=> NOW,
		);
		$transaction_log_id = $this->db->insert($this->tb_transaction_logs, $data_tnx_log);
		$this->load->view( $this->payment_type . "/redirect", $data_redirect);
	}

	public function complete(){
        
		if (!isset($_REQUEST['tx_ref']) || !isset($_REQUEST['status']) || !isset($_REQUEST['transaction_id'])) {
			redirect(cn("add_funds"));
		}

        $tnx_ref = strip_tags($_REQUEST['tx_ref']);
        $tnx_id = strip_tags($_REQUEST['transaction_id']);
        $transaction = $this->model->get('*', $this->tb_transaction_logs, ['transaction_id' => $tnx_ref, 'status' => 0, 'type' => $this->payment_type]);

        if(!$transaction){
            redirect(cn("add_funds"));
        }

        $verify_url = "https://api.flutterwave.com/v3/transactions/".$tnx_id . "/verify";
        
        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL =>  $verify_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json",
            "Authorization: Bearer ".$this->secret_key,
        ),
        ));

        $response = curl_exec($curl);
		$err = curl_error($curl);

		if($err){
		  redirect(cn("add_funds"));
		}
		$tranx = json_decode($response);
		if(!$tranx->status){
		  die('API returned error: ' . $tranx->message);
		}
		
		if($tranx->status == 'success' && 'successful' == $tranx->data->status && $transaction){
			$data_tnx_log = [
                'status' => 1,
                'transaction_id' => $tnx_id,
            ];

			$this->db->update($this->tb_transaction_logs, $data_tnx_log,  ['id' => $transaction->id]);

			// Update Balance 
    		require_once 'add_funds.php';
    		$add_funds = new add_funds();
    		$add_funds->add_funds_bonus_email($transaction, $this->payment_id);
            
			set_session("transaction_id", $transaction->id);
			redirect(cn("add_funds/success"));

		}else{
			redirect(cn("add_funds/unsuccess"));
		}
	}

}