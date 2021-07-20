<?php
namespace Oara\Network\Publisher;

/**
 * Export Class
 *
 * @author     Pim van den Broek
 * @category   RetailAds
 * @version    Release: 01.00
 *
 */
class RetailAds extends \Oara\Network
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

		$date = new \DateTime();
		$date->sub(new \DateInterval('P100D'));

		$url = 'https://data.retailads.net/api/stats_publisher?user='.$this->userid.'&key='.$this->api_password.'&type=program&startDate='.$date->format("Y-m-d").'&format=json';

		$response = self::apiCall($url);
		if (!empty($response)) {
			foreach($response as $advertiser) {

				$obj = [];
				$obj['cid'] = $advertiser->programId;
				$obj['name'] = $advertiser->programName;
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
	 * @throws Exception
	 */
	public function getTransactionList($merchantList = null, \DateTime $dStartDate = null, \DateTime $dEndDate = null) {

		$totalTransactions = Array();

		$dEndDate->add(new \DateInterval('P1D')); // add one day so we also get results of today
		$dStartDate->sub(new \DateInterval('P100D')); // add one day so we also get results of today

		$url = 'https://data.retailads.net/api/stats_publisher?user='.$this->userid.'&key='.$this->api_password.'&type=leadssales&startDate='.$dStartDate->format("Y-m-d").'&endDate='.$dEndDate->format("Y-m-d").'&format=json';
		$response = self::apiCall($url);

		if (!empty($response)) {

			foreach($response as $raw_transaction) {

				$transaction = Array();
				$transaction['unique_id'] = $raw_transaction->orderId;
				$transaction['merchantId'] = $raw_transaction->programId;
				$transaction['date'] = $raw_transaction->timeClick;

				$transaction['custom_id'] = $raw_transaction->subid ?? '';

				$transaction['amount'] = round($raw_transaction->basketValue, 2);
				$transaction['commission'] = round($raw_transaction->commission, 2);

				if ($raw_transaction->status == 'APPROVED') {
					$transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
				} else if ($raw_transaction->status == 'CANCELED') {
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

		curl_close($ch);
		return json_decode($curl_results);
	}


}
