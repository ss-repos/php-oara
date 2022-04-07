<?php
namespace Oara\Network\Publisher;

/**
 * Export Class
 *
 * @author     Pim van den Broek
 * @category   Adtraction
 * @version    Release: 01.00
 *
 */
class Adtraction extends \Oara\Network
{
	private $api_password = null;
	private $market = null;
	private $channel_id = null;


	/**
	 * @param $credentials
	 */
	public function login($credentials)
	{

		$this->api_password = $credentials['api_token'];
		$this->market = $credentials['market'];
		$this->channel_id = $credentials['channel_id'];

		return true;

	}

	/**
	 * @return array
	 */
	public function getNeededCredentials() {
		$credentials = array();

		$parameter = array();
		$parameter["description"] = "API token ";
		$parameter["required"] = true;
		$parameter["name"] = "API";
		$credentials["api_token"] = $parameter;

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

		$url = 'https://api.adtraction.com/v3/partner/programs/';
		$post_data = '{ "market": "'.$this->market.'", "channelId": '.$this->channel_id.', "approvalStatus": 1 }';

		$response = self::apiCall($url, $post_data);

		if (!empty($response)) {
			foreach($response as $advertiser) {

				$obj = [];
				$obj['cid'] = (int) $advertiser->programId;
				$obj['name'] = (string) $advertiser->programName;
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

		$url = 'https://api.adtraction.com/v2/partner/transactions/';

		$post_data = '{ "fromDate": "'.$dStartDate->format("Y-m-d").'T00:00:00+0200", "toDate": "'.$dEndDate->format("Y-m-d").'T23:59:59+0200", "transactionStatus": 0 }';

		$response = self::apiCall($url, $post_data);

		if (!empty($response)) {

			if (empty($response->message)) { // e.g. [message] => No transactions found.

				foreach ($response as $raw_transaction) {

					$transaction = array();
					$transaction['unique_id'] = (string)$raw_transaction->uniqueId;
					$transaction['merchantId'] = (string)$raw_transaction->click->programId;
					$transaction['date'] = (string)$raw_transaction->transactionDate;

					$transaction['custom_id'] = (string)$raw_transaction->click->epi ?? '';

					$transaction['commission'] = round($raw_transaction->commission, 2);
					$transaction['amount'] = round($raw_transaction->orderValue, 2);

					if ($raw_transaction->transactionStatus == 1) {
						$transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
					} else if ($raw_transaction->transactionStatus == 5) {
						$transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
					} else {
						$transaction['status'] = \Oara\Utilities::STATUS_PENDING;
					}

					$totalTransactions[] = $transaction;
				}
			}
		}

		return $totalTransactions;

	}


	private function apiCall($url, $post_data = null) {

		$url .= (strpos($url, '?') !== false) ? '&' : '?';
		$url .= 'token=' . $this->api_password;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_POST, true);

		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			"Content-Type: application/json;charset=UTF-8"
		));

		if (!empty($post_data)) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
		}

		$curl_results = curl_exec($ch);

		$response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		try {
			return json_decode($curl_results);
		} catch (\Exception $e) {
			throw new \Exception('Adtraction fail: ' . $curl_results);
		}


	}


}
