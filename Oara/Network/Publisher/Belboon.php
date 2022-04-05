<?php
namespace Oara\Network\Publisher;

/**
 * Export Class
 *
 * @author     Pim van den Broek
 * @category   Belboon
 * @version    Release: 01.00
 *
 */
class Belboon extends \Oara\Network
{
	private $api_password = null;
	private $userid = null;
	private $token = null;

	/**
	 * @param $credentials
	 */
	public function login($credentials)
	{

		$this->api_password = $credentials['apipassword'];
		$this->userid = $credentials['userid'];

		return true;

	}

	/**
	 * @return array
	 */
	public function getNeededCredentials() {
		$credentials = array();

		$parameter = array();
		$parameter["description"] = "API Password ";
		$parameter["required"] = true;
		$parameter["name"] = "API";
		$credentials["apipassword"] = $parameter;

		$parameter = array();
		$parameter["description"] = "userid";
		$parameter["required"] = true;
		$parameter["name"] = "userid";
		$credentials["userid"] = $parameter;

		return $credentials;
	}

	/**
	 * @return bool
	 */
	public function checkConnection() {
		return true;
	}


	/**
	 * @return array
	 */
	public function getMerchantList() {
		$merchants = array();

		$start_date = new \DateTime();
		$start_date->sub(new \DateInterval('P100D'));

		$end_date = new \DateTime();

		$url = 'https://export.service.belboon.com/'.$this->api_password.'/mlist_'.$this->userid.'.xml?filter[zeitraumvon]='.$start_date->format("d.m.Y").'&filter[zeitraumbis]='.$end_date->format("d.m.Y").'&filter[zeitraumAuswahl]=absolute';

		$response = self::apiCall($url);

		if (!empty($response->merchant)) {
			foreach($response->merchant as $advertiser) {

				$obj = [];
				$obj['cid'] = (int) $advertiser->mid;
				$obj['name'] = (string) $advertiser->title;
				$merchants[] = $obj;
			}
		}
		return $merchants;
	}

	/**
	 * @param null $merchantList
	 * @param \DateTime|null $dStartDate
	 * @param \DateTime|null $dEndDate
	 * @return array
	 * @throws \Exception
	 */
	public function getTransactionList($merchantList = null, \DateTime $dStartDate = null, \DateTime $dEndDate = null) {

		$totalTransactions = Array();

		$dEndDate->add(new \DateInterval('P1D')); // add one day so we also get results of today

		$url = 'https://export.service.belboon.com/'.$this->api_password.'/reporttransactions_'.$this->userid.'.xml?filter[currencycode]=EUR&filter[zeitraumvon]='.$dStartDate->format("d.m.Y").'&filter[zeitraumbis]='.$dEndDate->format("d.m.Y").'&filter[zeitraumAuswahl]=absolute';

		$response = self::apiCall($url);

		if (!empty($response)) {

			foreach($response as $raw_transaction) {

				$transaction = Array();
				$transaction['unique_id'] = (string) $raw_transaction->conversion_uniqid;
				$transaction['merchantId'] = (string) $raw_transaction->advertiser_id;
				$transaction['date'] = (string) $raw_transaction->conversion_tracking_time;

				$transaction['custom_id'] = (string) $raw_transaction->click_subid[0] ?? $raw_transaction->click_subid ?? '';

				$transaction['commission'] = round($raw_transaction->conversion_commission_total, 2);
				$transaction['amount'] = round($raw_transaction->conversion_order_value, 2);

				if ($raw_transaction->status == 'approved' || $raw_transaction->status == 'confirmed') {
					$transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
				} else if ($raw_transaction->status == 'rejected') {
					$transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
				} else {
					$transaction['status'] = \Oara\Utilities::STATUS_PENDING;
				}

				$totalTransactions[] = $transaction;
			}
		}

		return $totalTransactions;

	}


	private function apiCall($url, $post_data = null) {

		$url .= (strpos($url, '?') !== false) ? '&' : '?';
		$url .= 'token=' . $this->token;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

		if (empty($post_data)) {
			curl_setopt($ch, CURLOPT_POST, false);
		} else {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
		}

		$curl_results = curl_exec($ch);
		$response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if ($response_code == 502) { // 502 Bad Gateway
			return null;
		}

		curl_close($ch);

		try {
			return simplexml_load_string($curl_results);
		} catch (\Exception $e) {
			throw new \Exception('Belboon XML fail: ' . $curl_results);
		}


	}


}
