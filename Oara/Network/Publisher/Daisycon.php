<?php
namespace Oara\Network\Publisher;

class Daisycon extends \Oara\Network {

	private $_credentials = null;

	/**
	 * @param $credentials
	 */
	public function login($credentials) {

		$this->_credentials = $credentials;
		$this->refreshTokens();

	}

	public function checkConnection(): bool {
		return !empty($this->_credentials['access_token']);
	}

	public function getMerchantList(): array {
		$merchants = [];

		foreach ($this->_credentials['media_ids'] as $media_id) {
			$page = 1;
			$page_size = 250;
			$finish = false;

			while (!$finish) {
				$url = 'https://services.daisycon.com/publishers/' . $this->_credentials['publisher_id'] . '/programs?media_id='.$media_id.'&page='.$page.'&per_page='.$page_size.'&fields=status,id,name';

				$response = $this->callAPI($url,'GET');
				$merchant_list = json_decode($response, true);

				if (!empty($merchant_list)) {
					foreach ($merchant_list as $merchant) {
						if ($merchant['status'] == 'active') {
							$merchants[$merchant['id']] = ['cid' => $merchant['id'], 'name' => $merchant['name']];
						}
					}
				}

				if (empty($merchant_list) || count($merchant_list) != $page_size) {
					$finish = true;
				}
				$page++;
			}
		}

		return $merchants;
	}

	public function getTransactionList($merchantList = null, \DateTime $dStartDate = null, \DateTime $dEndDate = null): array {

		$totalTransactions = [];

		$merchantIdList = \Oara\Utilities::getMerchantIdMapFromMerchantList($merchantList);

		$days = 5; // API max number is days is 31
		$page_size = 500; // API max page size if 500

		foreach ($this->_credentials['media_ids'] as $media_id) {

			$end_date_for_api = (clone $dStartDate)->add(new \DateInterval('P'.$days.'D'));

			do { // loop over date frames (API max result set size is 10000, so we cannot use the date range with a high page number for bigger result set and therefore we have to split up)

				$api_url = 'https://services.daisycon.com/publishers/' . $this->_credentials['publisher_id'] . '/transactions?currency_code=EUR&media_id='.$media_id.'&per_page='.$page_size.'&start='. \urlencode($dStartDate->format("Y-m-d H:i:s")) . '&end=' . \urlencode($end_date_for_api->format("Y-m-d H:i:s"));

				$page = 1;
				$finish = false;

				while (!$finish) {
					$url = $api_url . '&page=' . $page;

					$response = $this->callAPI($url, 'GET');
					$transactionList = json_decode($response, true);
					if (!empty($transactionList)) {
						foreach ($transactionList as $transaction) {

							$merchantId = $transaction['program_id'];
							if (isset($merchantIdList[$merchantId])) {

								$transactionArray = array();
								$transactionArray['unique_id'] = $transaction['affiliatemarketing_id'];
								$transactionArray['merchantId'] = $merchantId;
								$transactionDate = new \DateTime($transaction['date']);
								$transactionArray['date'] = $transactionDate->format("Y-m-d H:i:s");
								$parts = \current($transaction['parts']);
								if ($parts['subid'] != null) {
									$transactionArray['custom_id'] = $parts['subid'];
								}
								if ($parts['status'] == 'approved') {
									$transactionArray['status'] = \Oara\Utilities::STATUS_CONFIRMED;
								} else
									if ($parts['status'] == 'pending' || $parts['status'] == 'potential' || $parts['status'] == 'open') {
										$transactionArray['status'] = \Oara\Utilities::STATUS_PENDING;
									} else
										if ($parts['status'] == 'disapproved' || $parts['status'] == 'incasso') {
											$transactionArray['status'] = \Oara\Utilities::STATUS_DECLINED;
										} else {
											throw new \Exception("New status {$parts['status']}");
										}
								$transactionArray['amount'] = \Oara\Utilities::parseDouble($parts['revenue']);
								$transactionArray['commission'] = \Oara\Utilities::parseDouble($parts['commission']);
								$totalTransactions[] = $transactionArray;
							}
						}
					}

					if (empty($transactionList) || \count($transactionList) != $page_size) {
						$finish = true;
					}
					$page++;
				}

				$get_more_dates = $end_date_for_api < $dEndDate;

				$end_date_for_api->add(new \DateInterval('P'.($days).'D'));
				$dStartDate->add(new \DateInterval('P'.($days).'D'));

			} while ($get_more_dates);

		} // end for every media
		return $totalTransactions;
	}


	private function refreshTokens() {

		// retrieve refresh token from valuestore
		if(empty($refresh_token = $this->_credentials['valuestore_location']::retrieve('daisycon_refresh_token'))) {
			throw new \Exception("No refresh token found");
		}

		$response = $this->callAPI(
			'https://login.daisycon.com/oauth/access-token',
			'POST',
			[
				'grant_type'    => 'refresh_token',
				'redirect_uri'  => 'https://login.daisycon.com/oauth/cli',
				'client_id'     => $this->_credentials['client_id'],
				'client_secret' => $this->_credentials['client_secret'],
				'refresh_token' => $refresh_token,
			]
		);

		$tokens = json_decode($response);

		if (!empty($tokens->refresh_token)) { // store refresh token in valuestore for next time
			$this->_credentials['valuestore_location']::store('daisycon_refresh_token', $tokens->refresh_token);
		}
		if (!empty($tokens->access_token)) { // use acces token this run
			$this->_credentials['access_token'] = $tokens->access_token;
		}

	}


	private function callAPI($url, $type, $data = []) {

		$curl = curl_init($url);

		// if we have an access token, use it
		if (!empty($this->_credentials['access_token'])) {

			curl_setopt($curl, CURLOPT_HTTPHEADER, [
				"Authorization: Bearer {$this->_credentials['access_token']}",
				'Content-Type: application/json',
			]);

		}
		if (strtoupper($type) == 'POST') {
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
		}

		curl_setopt(
			$curl,
			CURLOPT_HEADERFUNCTION,
			function ($curl, $responseHeader) use (&$responseHeaders) {
				@list($headerName, $headerValue) = explode(': ', $responseHeader);
				if (true === str_starts_with($headerName, 'X-'))
				{
					$responseHeaders[$headerName] = trim($headerValue);
				}
				return strlen($responseHeader);
			}
		);

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($curl);

		$error = curl_error($curl);
		if (empty($error) === false) {
			var_dump($error);
			die;
		}

		curl_close($curl);
		return $response;
	}


}
