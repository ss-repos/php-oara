<?php
namespace Oara\Network\Publisher;

class Amazon extends \Oara\Network {

	public function login($credentials) {
		$this->_credentials = $credentials;
	}

	/**
	 * @return array
	 */
	public function getNeededCredentials() {
		$credentials = array();

		$parameter = array();
		$parameter["description"] = "User for the feed https://assoc-datafeeds-eu.amazon.com/datafeed/listReports";
		$parameter["required"] = true;
		$parameter["name"] = "User";
		$credentials["user"] = $parameter;

		$parameter = array();
		$parameter["description"] = "Password for the feed https://assoc-datafeeds-eu.amazon.com/datafeed/listReports";
		$parameter["required"] = true;
		$parameter["name"] = "Password";
		$credentials["password"] = $parameter;

		return $credentials;
	}


	/**
	 * Check the connection
	 */
	public function checkConnection() {
		// If not login properly the construct launch an exception
		$connection = true;

		$url = "https://assoc-datafeeds-eu.amazon.com/datafeed/listReports";
		$curl = \curl_init();
		\curl_setopt($curl, CURLOPT_URL, $url);
		\curl_setopt($curl, CURLOPT_USERPWD, $this->_credentials["user"] . ':' . $this->_credentials["password"]);
		\curl_setopt($curl, CURLOPT_HEADER, false);
		\curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		\curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
		\curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		\curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		\curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
		\curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
		$output = \curl_exec($curl);
		if (\preg_match("/Error/", $output)) {
			$connection = false;
		}
		return $connection;
	}

	/**
	 * (non-PHPdoc)
	 * @see library/Oara/Network/Oara_Network_Publisher_Interface#getMerchantList()
	 */
	public function getMerchantList() {
		$merchants = array();

		$obj = array();
		$obj['cid'] = "1";
		$obj['name'] = "Amazon";
		$obj['url'] = "www.amazon.com";
		$merchants[] = $obj;

		return $merchants;
	}

	/**
	 * (non-PHPdoc)
	 * @see library/Oara/Network/Oara_Network_Publisher_Interface#getTransactionList($aMerchantIds, $dStartDate, $dEndDate, $sTransactionStatus)
	 */
	public function getTransactionList($merchantList = null, \DateTime $dStartDate = null, \DateTime $dEndDate = null) {

		$totalTransactions = [];
		$amountDays = $dStartDate->diff($dEndDate)->days;
		$auxDate = clone $dStartDate;
		for ($j = 0; $j <= $amountDays; $j++) {
			$date = $auxDate->format("Ymd");

			$url = "https://assoc-datafeeds-eu.amazon.com/datafeed/getReport?filename={$this->_credentials["user"]}-earnings-report-$date.tsv.gz";
			$curl = \curl_init();
			\curl_setopt($curl, CURLOPT_URL, $url);
			\curl_setopt($curl, CURLOPT_USERPWD, $this->_credentials["user"] . ':' . $this->_credentials["password"]);
			\curl_setopt($curl, CURLOPT_HEADER, false);
			\curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
			\curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
			\curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			\curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
			\curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
			\curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
			$output = \curl_exec($curl);
			if ($output) {
				$filename = \realpath(\dirname(COOKIES_BASE_DIR)) . "/pdf/{$this->_credentials["user"]}-earnings-report-$date.tsv.gz";
				\file_put_contents($filename, $output);
				$zd = \gzopen($filename, "r");
				$contents = \gzread($zd, 1000000);
				\gzclose($zd);

				$exportData = \explode("\n", $contents);

				$num = \count($exportData);

				$unique_ids_in_this_day = [];

				for ($i = 2; $i < $num; $i++) {
					$transactionExportArray = \explode("\t", $exportData[$i]);
					if (count($transactionExportArray) > 1) {

						$transaction = [];

						$transaction['merchantId'] = 1;
						$transaction['date'] = $auxDate->format("Y-m-d 00:00:00");

						$transaction['amount'] = \Oara\Utilities::parseDouble($transactionExportArray[7]);
						$transaction['commission'] = \Oara\Utilities::parseDouble($transactionExportArray[8]);

						if ($transaction['commission'] < 0) { // refund
							$transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
							$transaction['amount'] = abs($transaction['amount']); // since we mark these orders as declined, we need a positive amount
							$transaction['commission'] = abs($transaction['commission']); // since we mark these orders as declined, we need a positive commission
						} else {
							$transaction['status'] = \Oara\Utilities::STATUS_PENDING;
						}

						if ($transactionExportArray[9] != null && $transactionExportArray[9] != '-') {
							$transaction['custom_id'] = $transactionExportArray[9];

							$unique_id = $transaction['custom_id'] . '-' . $transaction['amount'];

							// handle multiple orders of the same product on this day with the same price by the same person (e.g. multiple sizes of the same product), since else all but one they would be overwritten
							if (in_array($unique_id, $unique_ids_in_this_day)) { // there has already been an order with this same id, go add a postfix
								$counter = 0;
								$unique_id_new = $unique_id;
								while (in_array($unique_id_new, $unique_ids_in_this_day)) {
									$unique_id_new = $unique_id . '-' . ++$counter;
								}
								$transaction['unique_id'] = $unique_id_new;
							} else { // no other orders with this same unique_id (yet)
								$transaction['unique_id'] = $unique_id;
							}

							$unique_ids_in_this_day[] = $transaction['unique_id'];

						} else { // if we have no sub id, skip this order since we cannot create a unique id for it.
							continue;
						}

						$totalTransactions[$transaction['unique_id']] = $transaction; // overwrite old transactions with the same unique_id so we only retrieve the most recent update
					}


				}
				\unlink($filename);

			}
			$auxDate->add(new \DateInterval('P1D'));
		}

		return $totalTransactions;
	}
}
