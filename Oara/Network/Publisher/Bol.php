<?php
namespace Oara\Network\Publisher;

class Bol extends \Oara\Network {

	private $_credentials = null;

	/**
	 * @param $credentials
	 */
	public function login(array $credentials)	{

		$this->getAccessToken($credentials);

	}

	/**
	 * @return bool
	 */
	public function checkConnection() {

		return !empty($this->_credentials['access_token']);

	}
	/**
	 * @return array
	 */
	public function getMerchantList() {
		$merchants = array();

		$obj = array();
		$obj['cid'] = "1";
		$obj['name'] = "Bol.com";
		$merchants[] = $obj;

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

		$days = 3;

		$start_date_for_api = (clone $dStartDate);
		$end_date_for_api = (clone $dStartDate)->add(new \DateInterval('P'.($days-1).'D')); // $days-1 because the end-date is inclusive and we are getting double transactions otherwise

		do { // loop over date frames (API times out if we get the sales of a too long period)

			$url = 'https://api.bol.com/marketing/affiliate/reports/v1/order-report?startDate='. \urlencode($start_date_for_api->format("Y-m-d")) . '&endDate=' . \urlencode($end_date_for_api->format("Y-m-d"));

			$result = self::callAPI($url);

			if (!empty($result['items'])) {
				foreach ($result['items'] as $item) {

					$transaction = [];

					$order_date = new \Datetime($item['orderDate']);

					// we have logged unique_id's with a postfix of MS serialized datetime value in the old BOL importer, so we want to keep this doing so we can match the old transactions
					$postfix = $this->formattedPHPToExcel($order_date->format("Y"), $order_date->format("m"), $order_date->format("d"));

					$transaction['unique_id'] = $item['orderNumber'] . '_' . $postfix;

					$transaction['merchantId'] = "1";
					$transaction['date'] = $order_date->format("Y-m-d 00:00:00");

					$transaction['custom_id'] = $item['subId'] ?? '';

					$transaction['amount'] = $item['priceExcludingVat'];
					$transaction['commission'] = $item['commission'];

					if ($item['status'] == 'Geaccepteerd') {
						$transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
					} else if ($item['status'] == 'Open') {
						$transaction['status'] = \Oara\Utilities::STATUS_PENDING;
					} else if ($item['status'] == 'geweigerd: klik te oud' || $item['status'] == 'Geweigerd') {
						$transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
					} else {
						throw new \Exception("new status " . $item['status']);
					}

					$totalTransactions[] = $transaction;
				}
			}

			$get_more_dates = $end_date_for_api < $dEndDate;

			$start_date_for_api->add(new \DateInterval('P'.($days).'D'));
			$end_date_for_api->add(new \DateInterval('P'.($days).'D'));

		} while ($get_more_dates);

		$totalTransactions = self::mergeProductsFromOneOrder($totalTransactions);

		return $totalTransactions;

	}



	public static function getTransationsFromExcel($objPHPExcel) {
		$totalTransactions = [];

		$objWorksheet = $objPHPExcel->getActiveSheet();
		$highestRow = $objWorksheet->getHighestRow();

		for ($row = 2; $row <= $highestRow; ++$row) {

			$transaction = Array();
			$transaction['unique_id'] = $objWorksheet->getCellByColumnAndRow(1, $row)->getValue() . "_" . $objWorksheet->getCellByColumnAndRow(2, $row)->getValue();
			$transaction['merchantId'] = "1";
			$transactionDate = $objWorksheet->getCellByColumnAndRow(2, $row)->getValue();
			$transaction['date'] = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($transactionDate)->format("Y-m-d 00:00:00");

			$transaction['custom_id'] = $objWorksheet->getCellByColumnAndRow(7, $row)->getValue();
			$status = $objWorksheet->getCellByColumnAndRow(13, $row)->getValue();
			if ($status == 'Geaccepteerd') {
				$transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
			} else
				if ($status == 'Open') {
					$transaction['status'] = \Oara\Utilities::STATUS_PENDING;
				} else
					if ($status == 'geweigerd: klik te oud' || $status == 'Geweigerd') {
						$transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
					} else {
						throw new \Exception("new status " . $status);
					}
			$transaction['amount'] = \Oara\Utilities::parseDouble(round($objWorksheet->getCellByColumnAndRow(10, $row)->getValue(), 2)); // price without VAT
			$transaction['commission'] = \Oara\Utilities::parseDouble(round($objWorksheet->getCellByColumnAndRow(11, $row)->getValue(), 2));
			$totalTransactions[] = $transaction;

		}

		$totalTransactions = self::mergeProductsFromOneOrder($totalTransactions);

		return $totalTransactions;
	}

	/**
	 * If an order contains multiple products, every product is in a new row with the same unique_id.
	 * We want to merge them so we have one row containing the sums of all not-disapproved products
	 *
	 * @param $transactionList
	 * @return array
	 */
	private static function mergeProductsFromOneOrder(array $transactionList) {
		$unique_transactions = [];
		$transactions_per_unique_id = [];

		foreach ($transactionList as $transaction) {
			$transactions_per_unique_id[$transaction['unique_id']][] = $transaction;
		}

		foreach ($transactions_per_unique_id as $unique_id => $product_transactions) {

			// if there is only one product ordered, always just use that one
			if (sizeof($product_transactions) == 1) {
				$unique_transactions[] = reset($product_transactions);
			} else { // the order contains multiple products

				$open_and_approved_products = array_where($product_transactions, function ($product_transaction) {
					return $product_transaction['status'] != \Oara\Utilities::STATUS_DECLINED;
				});

				// if we have open and/or approved products, use only those
				if (!empty($open_and_approved_products)) {
					$products = $open_and_approved_products;
				} else { // only disapproved products, use all disapproved products
					// There is a small chance that this results in a too big refund figure:
					// If multiple products are marked as disapproved at *different* batches, and if after the last batch, all products have been disapproved:
					// We have already updated the numbers to only contain the figures of the open & approved products in earlier batches.
					// After the last product is disapproved, we use the sum of all the disapproved figures, as we do not have a history.
					// This should be very rare however, if at all occurring.
					$products = $product_transactions;
				}

				$merged_transaction = [];

				// go add the numbers
				foreach ($products as $product_transaction) {

					if (empty($merged_transaction)) { // first product
						$merged_transaction = $product_transaction;
					} else { // additional products, go add the numbers
						$merged_transaction['amount'] += $product_transaction['amount'];
						$merged_transaction['commission'] += $product_transaction['commission'];
					}
				}

				$unique_transactions[] = $merged_transaction;

			}

		}

		return $unique_transactions;

	}




	private function getAccessToken(array $credentials) {

		$curl = curl_init('https://login.bol.com/token?grant_type=client_credentials');

		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

		curl_setopt($curl, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Authorization: Basic ' . base64_encode($credentials['client_id'].':'.$credentials['client_secret']),

		]);

		$response = curl_exec($curl);
		$token = json_decode($response);

		if (!empty($token->access_token)) {
			$this->_credentials['access_token'] = $token->access_token;
		}

	}


	private function callAPI(string $url, string $type = 'GET', array $data = []) {

		$curl = curl_init($url);

		curl_setopt($curl, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $this->_credentials['access_token'],
		]);

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

		$response = curl_exec($curl);

		$error = curl_error($curl);
		if (empty($error) === false) {
			var_dump($error);
			die;
		}

		curl_close($curl);

		return json_decode($response, true);
	}



	/**
	 * formattedPHPToExcel.
	 *
	 * @param int $year
	 * @param int $month
	 * @param int $day
	 * @param int $hours
	 * @param int $minutes
	 * @param int $seconds
	 *
	 * @return float Excel date/time value
	 */
	private static function formattedPHPToExcel($year, $month, $day, $hours = 0, $minutes = 0, $seconds = 0) {
		//
		//    Fudge factor for the erroneous fact that the year 1900 is treated as a Leap Year in MS Excel
		//    This affects every date following 28th February 1900
		//
		$excel1900isLeapYear = true;
		if (($year == 1900) && ($month <= 2)) {
			$excel1900isLeapYear = false;
		}
		$myexcelBaseDate = 2415020;

		//    Julian base date Adjustment
		if ($month > 2) {
			$month -= 3;
		} else {
			$month += 9;
			--$year;
		}

		//    Calculate the Julian Date, then subtract the Excel base date (JD 2415020 = 31-Dec-1899 Giving Excel Date of 0)
		$century = (int) substr((string) $year, 0, 2);
		$decade = (int) substr((string) $year, 2, 2);
		$excelDate = floor((146097 * $century) / 4) + floor((1461 * $decade) / 4) + floor((153 * $month + 2) / 5) + $day + 1721119 - $myexcelBaseDate + $excel1900isLeapYear;

		$excelTime = (($hours * 3600) + ($minutes * 60) + $seconds) / 86400;

		return (float) $excelDate + $excelTime;
	}

}
