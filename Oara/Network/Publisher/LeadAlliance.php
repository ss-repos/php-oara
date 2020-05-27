<?php

namespace Oara\Network\Publisher;


class LeadAlliance extends \Oara\Network {

	private $_credentials = null;


	/**
	 * @param $credentials
	 */
	public function login($credentials)
	{
		$this->_credentials = $credentials;
	}

	/**
	 * Check the connection
	 */
	public function checkConnection()
	{
		// If not login properly the construct launch an exception
		$connection = true;

		try {

			$url = "https://partner.c-a.com/api/v1/index.php/partner/partnership";

			$response = $this->call($url);

			$merchants = \json_decode($response, true);

			if (empty($merchants) || (isset($merchants['status']) && $merchants['status'] == 'error')) {
				throw new \Exception("No publisher found");
			}

		} catch (\Exception $e) {
			$connection = false;
		}

		return $connection;
	}

	/**
	 * (non-PHPdoc)
	 * @see library/Oara/Network/Interface#getMerchantList()
	 */
	public function getMerchantList()
	{
		$merchants = array();

		$url = "https://partner.c-a.com/api/v1/index.php/partner/partnership";

		$response = $this->call($url);

		$merchant_list = \json_decode($response, true);
		if (!empty($merchant_list)) {

			foreach ($merchant_list as $merchant_id => $merchant_name) {

				$obj = Array();
				$obj['cid'] = $merchant_id;
				$obj['name'] = $merchant_name;

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
	public function getTransactionList($merchantList = null, \DateTime $dStartDate = null, \DateTime $dEndDate = null)
	{
		$totalTransactions = array();

		$merchantIdList = \Oara\Utilities::getMerchantIdMapFromMerchantList($merchantList);

		$url = 'https://partner.c-a.com/api/v1/index.php/partner/transactions?date='.$dStartDate->format("Y-m-d").'&dateend='.$dEndDate->format("Y-m-d");

		$response = $this->call($url);

		$transactionList = \json_decode($response, true);

		if ($transactionList) {
			foreach ($transactionList as $transaction) {
				$merchantId = $transaction['programid'];
				if (isset($merchantIdList[$merchantId])) {

					$transactionArray = Array();
					$transactionArray['unique_id'] = $transaction['transactionid'];
					$transactionArray['merchantId'] = $merchantId;
					$transactionDate = new \DateTime($transaction['dateorigin'] . ' ' . $transaction['timeorigin']);
					$transactionArray['date'] = $transactionDate->format("Y-m-d H:i:s");
					if (!empty($transaction['subid'])) {
						$transactionArray['custom_id'] = $transaction['subid'];
					}
					if ($transaction['status'] == 0) {
						$transactionArray['status'] = \Oara\Utilities::STATUS_DECLINED;
					} else if ($transaction['status'] == 1) {
						$transactionArray['status'] = \Oara\Utilities::STATUS_PENDING;
					} else if ($transaction['status'] == 2) {
						$transactionArray['status'] = \Oara\Utilities::STATUS_CONFIRMED;
					} else {
						throw new \Exception("New status {$transactionArray['status']}");
					}

					$transactionArray['amount'] = \Oara\Utilities::parseDouble($transaction['value']);
					$transactionArray['commission'] = \Oara\Utilities::parseDouble($transaction['commission']);
					$totalTransactions[] = $transactionArray;
				}
			}
		}

		return $totalTransactions;
	}


	private function call($url, $method = 'GET') {

		$public_key = $this->_credentials['public_key'];
		$private_key = $this->_credentials['private_key'];

		$headers = array(
			'Accept: application/json',
			'Content-Type: application/json',
			'lea-Public: '.$public_key
		);

		$data = json_encode(
			array(
			)
		);

		if( strlen( $data ) <= 2 )
			$data = '';

		// create lea-Hash, small letters expected
		$headers[] = 'lea-Hash: '. hash_hmac('sha256', $data, $private_key);


		$handle = curl_init();
		curl_setopt($handle, CURLOPT_URL, $url);
		curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);

		switch($method) {
			case 'GET':
				break;
			case 'POST':
				curl_setopt($handle, CURLOPT_POST, true);
				curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
				break;
			case 'PUT':
				curl_setopt($handle, CURLOPT_CUSTOMREQUEST, 'PUT');
				curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
				break;
			case 'DELETE':
				curl_setopt($handle, CURLOPT_CUSTOMREQUEST, 'DELETE');
				break;
		}

		$response = curl_exec($handle);
		return $response;
	}


}
