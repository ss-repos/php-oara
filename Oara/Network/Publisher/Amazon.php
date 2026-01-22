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

		return $credentials;
	}


	/**
	 * Check the connection
	 */
	public function checkConnection() {
		$connection = false;

		$creators_api_class = $this->_credentials['creators_api_client'] ?? null;
		$creators_api_country = $this->_credentials['creators_api_country'] ?? null;
		if($creators_api_class && class_exists($creators_api_class) && !empty($creators_api_country)) {
			$connection = true;
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
		// Init CreatorsAPI
		$creators_api_class = $this->_credentials['creators_api_client'];
		$api_client = new $creators_api_class($this->_credentials['creators_api_country']);

		$totalTransactions = [];
		$amountDays = $dStartDate->diff($dEndDate)->days;
		$auxDate = clone $dStartDate;
		for ($j = 0; $j <= $amountDays; $j++) {
			$date = $auxDate->format("Ymd");

			$filename = "{$this->_credentials['store_id']}-earnings-report-$date.tsv.gz";

			$contents = $api_client->getReportContents($filename);
			if ($contents) {
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
			}
			$auxDate->add(new \DateInterval('P1D'));
		}
		return $totalTransactions;
	}
}