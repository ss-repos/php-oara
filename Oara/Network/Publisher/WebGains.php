<?php
namespace Oara\Network\Publisher;

/**
 * Api Class
 *
 * @author     Pim
 * @version    Release: 01.00
 *
 */
class Webgains extends \Oara\Network
{
	private $publisher_id, $api_token;

	public function login($credentials)	{

		$this->api_token = $credentials['api_token'];
		$this->publisher_id = $credentials['publisher_id'];

		return true;

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

		$url = 'https://platform-api.webgains.com/merchants/programs?filters[program_statuses][]=1&size=250';

		$response = self::apiCall($url);
		if (!empty($response['data'])) {
			foreach ($response['data'] as $advertiser) {
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
	 * @param \DateTime|null $dStartDate
	 * @param \DateTime|null $dEndDate
	 * @return array
	 * @throws \Exception
	 */
	public function getTransactionList($merchantList = null, \DateTime $dStartDate = null, \DateTime $dEndDate = null): array {

		$totalTransactions = [];
		$page_size = 250; // API max number results is 250

		$restUrl = 'https://platform-api.webgains.com/publishers/'.$this->publisher_id.'/reports/transactions?filters[start_date]=' . $dStartDate->getTimestamp() . '&filters[end_date]=' . $dEndDate->getTimestamp() . '&size='.$page_size;
		$offset = 0;

		$page = 1;

		do { // loop over pages
			$url = $restUrl . '&page=' . $page;

			$result = self::apiCall($url);

			if (!empty($result['data'])) {
				foreach ($result['data'] as $item) {

					$transaction = [];
					$transaction ['unique_id'] = $item['id'];
					$transaction['merchantId'] = $item['program']['id'];
					$date = new \DateTime();
					$date->setTimestamp($item['date']);
					$transaction['date'] = $date->format("Y-m-d H:i:s");

					$transaction['custom_id'] = $item['click_reference'] ?? '';

					$transaction['amount'] = round($item['value']['amount'] / 10000, 2);
					$transaction['commission'] = round($item['commission']['amount'] / 10000, 2);

					if (in_array($item['status'], ['10', '20', '30', '40'])) {
						$transaction ['status'] = \Oara\Utilities::STATUS_CONFIRMED;
					} else if ($item['status'] == '70') {
						$transaction ['status'] = \Oara\Utilities::STATUS_DECLINED;
					} else {
						$transaction ['status'] = \Oara\Utilities::STATUS_PENDING;
					}
					$totalTransactions[] = $transaction;
				}
			}
			$page++;

		} while ($result['pagination']['current_page'] != $result['pagination']['last_page']);

		return $totalTransactions;

	}



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

		curl_close($ch);

		try {
			return json_decode($curl_results, true);
		} catch (\Exception $e) {
			throw new \Exception('Webgains fail: ' . $curl_results);
		}

	}



}
