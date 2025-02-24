<?php
namespace Oara\Network\Publisher;

/**
 * Export Class
 *
 * @author     Pim van den Broek
 * @category   AdCell
 * @version    Release: 01.00
 *
 */
class AdCell extends \Oara\Network
{
	private $api_password = null;
	private $username = null;
	private $token = null;

	/**
	 * @param $credentials
	 */
	public function login($credentials)
	{

		$this->api_password = $credentials['apipassword'];
		$this->username = $credentials['username'];
		$result = json_decode(file_get_contents('https://api.adcell.org/api/v2/user/getToken?userName='.$this->username.'&password='.$this->api_password.''));

		if (!empty($result->data->token)) {
			$this->token = $result->data->token;
			return true;
		} else {
			return false;
		}

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
		$parameter["description"] = "username";
		$parameter["required"] = true;
		$parameter["name"] = "username";
		$credentials["username"] = $parameter;

		return $credentials;
	}

	/**
	 * @return bool
	 */
	public function checkConnection() {
		return !empty($this->token);
	}


	/**
	 * @return array
	 */
	public function getMerchantList() {
		$merchants = array();

		$url = 'https://api.adcell.org/api/v2/affiliate/program/export?affiliateStatus=accepted';

		$response = self::apiCall($url);

		if (!empty($response->data->items)) {
			foreach($response->data->items as $advertiser) {

				$obj = Array();
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

		$url = 'https://api.adcell.org/api/v2/affiliate/statistic/byCommission?rows=1000&startDate='.$dStartDate->format("Y-m-d").'&endDate='.$dEndDate->format("Y-m-d");
		$response = self::apiCall($url);
		if (!empty($response->data->items)) {

			foreach($response->data->items as $raw_transaction) {

				$transaction = Array();
				$transaction['unique_id'] = $raw_transaction->commissionId;
				$transaction['merchantId'] = $raw_transaction->programId;
				$transaction['date'] = $raw_transaction->createTime;

				$transaction['custom_id'] = $raw_transaction->subId ?? '';

				$transaction['amount'] = round($raw_transaction->totalShoppingCart, 2);
				$transaction['commission'] = round($raw_transaction->totalCommission, 2);

				if ($raw_transaction->status == 'accepted') {
					$transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
				} else if ($raw_transaction->status == 'cancelled') {
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
