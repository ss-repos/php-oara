<?php
namespace Oara\Network\Publisher;

/**
 * Export Class
 *
 * @author     Pim van den Broek
 * @version    Release: 01.01
 *
 */
class TradeDoubler extends \Oara\Network
{
	private $client_id, $client_secret, $username, $password, $site_ids;
	private $access_token = null;

	/**
	 * @param $credentials
	 * @return bool
	 * @throws \Exception
	 */
	public function login($credentials): bool {

		$this->client_id = $credentials['client_id'];
		$this->client_secret = $credentials['client_secret'];
		$this->username = $credentials['username'];
		$this->password = $credentials['password'];
		$this->site_ids = $credentials['site_ids'];

		$result = $this->getToken();

		return !empty($result);

	}

	/**
	 * @return bool
	 */
	public function checkConnection(): bool {
		return !empty($this->access_token);
	}


	/**
	 * @return array
	 */
	public function getMerchantList(): array {
		$merchants = [];

		foreach($this->site_ids as $site_id) {

			$restUrl = 'https://connect.tradedoubler.com/publisher/programs?sourceId=' . $site_id . '&statusId=3&limit=100';

			$response = self::apiCall($restUrl);
			$source_merchants = json_decode($response);

			if (!empty($source_merchants->{'items'})) {
				foreach ($source_merchants->{'items'} as $source_merchant) {
					$merchant = Array();
					$merchant['cid'] = (int) $source_merchant->{'id'};
					$merchant['name'] = (string) $source_merchant->{'name'};
					$merchants[] = $merchant;
				}
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
		$page_size = 100; // API max number results is 100
		$days = 31; // API max number is days is 31

		$end_date_for_api = (clone $dStartDate)->add(new \DateInterval('P'.$days.'D'));

		do { // loop over date frames
			$restUrl = 'https://connect.tradedoubler.com/publisher/report/transactions?fromDate=' . $dStartDate->format('Ymd') . '&toDate=' . $end_date_for_api->format('Ymd') . '&limit=' . $page_size;
			$offset = 0;

			do { // loop over page size
				$url = $restUrl . '&offset=' . $offset;

				$response = self::apiCall($url);
				$result = json_decode($response, true);

				if (!empty($result['items'])) {
					foreach ($result['items'] as $item) {

						$transaction = [];
						$transaction ['unique_id'] = $item['transactionId'];
						$transaction['merchantId'] = $item['programId'];
						$transactionDate = \DateTime::createFromFormat("Y-m-d\TH:i:s", \substr($item['timeOfTransaction'], 0, 19));
						$transaction['date'] = $transactionDate->format("Y-m-d H:i:s");

						$transaction['custom_id'] = $item['epi'] ?? '';

						$transaction['amount'] = $item['orderValue'];
						$transaction['commission'] = $item['commission'];

						if ($item['status'] == 'A') {
							$transaction ['status'] = \Oara\Utilities::STATUS_CONFIRMED;
						} else if ($item['status'] == 'D') {
							$transaction ['status'] = \Oara\Utilities::STATUS_DECLINED;
						} else {
							$transaction ['status'] = \Oara\Utilities::STATUS_PENDING;
						}

						$totalTransactions[] = $transaction;
					}
				}

				$offset += $page_size;
			} while (!empty($result['items']) && sizeof($result['items']) == $page_size);

			$get_more_dates = $end_date_for_api < $dEndDate;

			$end_date_for_api->add(new \DateInterval('P'.($days+1).'D')); // $days + 1 because the dates are inclusive, thus otherwise we would get double transactions
			$dStartDate->add(new \DateInterval('P'.($days+1).'D')); // $days + 1 because the dates are inclusive, thus otherwise we would get double transactions

		} while ($get_more_dates);

		return $totalTransactions;

	}



	private function apiCall($url, $post_data = null) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: Bearer " . $this->access_token));

		if (empty($post_data)) {
			curl_setopt($ch, CURLOPT_POST, false);
		} else {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
		}

		$curl_results = curl_exec($ch);

		curl_close($ch);
		return $curl_results;
	}

	private function getToken() {

		$auth_key = base64_encode($this->client_id . ':' . $this->client_secret);

		$data = array("grant_type" => "password", "username" => $this->username, 'password' => $this->password);

		$ch = curl_init('https://connect.tradedoubler.com/uaa/oauth/token');
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/x-www-form-urlencoded',
			'Authorization: Basic ' . $auth_key,
		));
		$body = curl_exec($ch);
		$errno = curl_errno($ch);
		if ($errno !== 0) {
			throw new \Exception(sprintf("Error connecting to TradeDoubler Token: [%s] %s", $errno, curl_error($ch)), $errno);
		}

		$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if ($http_status >= 400) {
			throw new \Exception(sprintf("TradeDoubler Error Token [%s] %s", $http_status, strip_tags($body)), $http_status);
		}
		$result = json_decode($body);

		if (!empty($result->access_token)) {
			$this->access_token = $result->access_token;
			return $result->access_token;
		}

		return null;

	}

}
