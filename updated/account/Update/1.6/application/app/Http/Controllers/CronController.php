<?php

namespace App\Http\Controllers;

use App\Constants\Status;
use App\Lib\CurlRequest;
use App\Models\GeneralSetting;
use App\Models\Order;

class CronController extends Controller
{
	public function placeOrderToApi()
	{
		$apiOrders          = Order::where('status', 0)->with('provider')->where('api_provider_id', '!=', 0)->where('order_placed_to_api', 0)->get();
		$general            = GeneralSetting::first();
		$general->last_cron = now();
		$general->save();

		foreach ($apiOrders as $order) {
			$response = CurlRequest::curlPostContent($order->provider->api_url, [
				'key'      => $order->provider->api_key,
				'action'   => "add",
				'service'  => $order->api_service_id,
				'link'     => $order->link,
				'quantity' => $order->quantity,
			]);

            $response = json_decode($response);
            if (!isset($response->error)) {
    			$order->status  = 1;
    			$order->order_placed_to_api = 1;
    			$order->api_order_id = $response->order;
    			$order->save();
			}
		}
		$this->serviceUpdate();
		exit('Cron ran successfully');
	}

	public function serviceUpdate()
	{
		$orders = Order::where('status', 1)->with('provider')->where('api_provider_id', '!=', 0)->where('api_order_id', '!=', 0)->where('order_placed_to_api', 1)->get();
		
		foreach ($orders as $order) {

			$response = CurlRequest::curlPostContent($order->provider->api_url, [
                'key'    => $order->provider->api_key,
				'action' => 'status',
				'order'  => $order->api_order_id,
			]);
            
			$response = json_decode($response);
			if (isset($response->error)) {
				return response()->json(['error' => $response->error]) . '<br>';
			}

			$order->start_count = $response->start_count;
			$order->remain_count = $response->remains;

			if ($response->status == 'Completed') {
				$order->status = 2;
			}

			if ($response->status == 'Canceled') {
				$order->status = 3;
			}

			if ($response->status == 'Refunded') {
				$order->status = 4;
			}
			$order->save();
		}
	}
}
