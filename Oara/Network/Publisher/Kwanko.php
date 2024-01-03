<?php
namespace Oara\Network\Publisher;

use DateTime;
use Exception;

/**
 * API Class
 *
 * @author     Pim van den Broek
 *
 */
class Kwanko extends \Oara\Network{

	private string $api_token;

	/**
	 * @param $credentials
	 * @return bool
	 */
	public function login($credentials): bool {

		$this->api_token = $credentials['api_token'];

		return true;

	}

	/**
	 * @return array
	 */
	public function getNeededCredentials(): array {
		$credentials = array();

		$parameter = array();
		$parameter["description"] = "API token ";
		$parameter["required"] = true;
		$parameter["name"] = "API token";
		$credentials["api_token"] = $parameter;

		return $credentials;

	}

	/**
	 * @return bool
	 */
	public function checkConnection(): bool {

		return !empty($this->api_token);

	}

	/**
	 * @return array
	 * @throws Exception
	 */
	public function getMerchantList(): array {

		$merchants = array();

		$url = 'https://api.kwanko.com/publishers/campaigns';

		$response = self::apiCall($url);

		if (!empty($response['campaigns'])) {
			foreach ($response['campaigns'] as $advertiser) {
				$obj = [];
				$obj['cid'] = (int)$advertiser['id'];
				$obj['name'] = (string)$advertiser['name'];
				$merchants[] = $obj;
			}
		}

		return $merchants;
	}


	/**
	 * @param null $merchantList
	 * @param DateTime|null $dStartDate
	 * @param DateTime|null $dEndDate
	 * @return array
	 * @throws Exception
	 */
	public function getTransactionList($merchantList = null, DateTime $dStartDate = null, DateTime $dEndDate = null): array {

		$totalTransactions = [];

		$url = 'https://api.kwanko.com/publishers/conversions?columns=ALL&completion_date_from=' . urlencode($dStartDate->format('c')) . '&completion_date_to=' . urlencode($dEndDate->format('c'));

		$result = self::apiCall($url);

		if (!empty($result['conversions'])) {
			foreach ($result['conversions'] as $item) {

				$transaction = [];
				$transaction['merchantId'] = $item['campaign']['id'];
				$transaction ['unique_id'] = $transaction['merchantId'] . '-' . $item['kwanko_id'];
				$date = new DateTime();
				$date->setTimestamp($item['completed_at_timestamp']);
				$transaction['date'] = $date->format("Y-m-d H:i:s");

				$transaction['custom_id'] = $item['websites_per_language'][0]['argsites']['argsite'] ?? '';

				$transaction['amount'] = $item['turnover']['value'];
				$transaction['commission'] = $item['websites_per_language'][0]['earnings']['value'];

				if ($item['state'] == 'approved') {
					$transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
				} else if ($item['state'] == 'rejected') {
					$transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
				} else  if ($item['state'] == 'pending_approval') {
					$transaction['status'] = \Oara\Utilities::STATUS_PENDING;
				} else {
					throw new Exception ("Kwanko status not found: " . $item['state']);
				}

				$totalTransactions[] = $transaction;
			}
		}

		return $totalTransactions;

	}


	/**
	 * @throws Exception
	 */
	private function apiCall($url, $post_data = null) {

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Authorization: Bearer ' . $this->api_token,
			'Content-Type: application/json;charset=UTF-8'
		]);

		if (!empty($post_data)) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
		}
		$curl_results = curl_exec($ch);

		$response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		try {
			return json_decode($curl_results, true);
		} catch (Exception $e) {
			throw new Exception('Kwanko fail: ' . $curl_results);
		}

	}



}
