<?php
namespace Oara\Network\Publisher;

class Bol extends \Oara\Network
{

	private $_client, $web_proxy_url, $web_proxy_auth = null;

	/**
	 * @param $credentials
	 */
	public function login($credentials)	{

		$this->_client = new \Oara\Curl\Access($credentials);

		$login_get_url = 'https://login.bol.com/login?client_id=apm';

		$curl_options = $this->_client->getOptions();

		if (!empty($credentials['proxy'])) { // Use IP proxy
			$curl_options[CURLOPT_PROXY] = $credentials['proxy'];
			$curl_options[CURLOPT_PROXYUSERPWD] = $credentials['proxyuserpwd'];
			$url = $login_get_url;
		} else if (!empty($credentials['web_proxy'])) { // Use a web proxy instead of IP proxy

			$this->web_proxy_url = $credentials['web_proxy'];
			$this->web_proxy_auth = $credentials['web_proxy_auth'];

			$curl_options[CURLOPT_HTTPHEADER] = [
				'Proxy-Auth: ' . $this->web_proxy_auth,
				'Proxy-Target-URL: '. $login_get_url,
			];

			$url = $this->web_proxy_url;
		}

		$this->_client->setOptions($curl_options);

		// Go get the csrf token from the login page
		$urls = array();
		$urls[] = new \Oara\Curl\Request($url, []);
		$login_page = $this->_client->get($urls);

		if(strpos($login_page[0], 'g-recaptcha') !== false) {
			throw new \Exception ('Bol login requires ReCAPTCHA');
		}
		// If we have found a token, continue with the login
		if (\preg_match('#name="csrftoken"(\s*)value="(.*?)"#mi', $login_page[0], $matches) && !empty($matches[2])) {

			$csrf_token = $matches[2];

			$valuesLogin = array(
				new \Oara\Curl\Parameter('j_username', $credentials['user']),
				new \Oara\Curl\Parameter('j_password', $credentials['password']),
				new \Oara\Curl\Parameter('csrftoken', $csrf_token),
			);


			$login_post_url = 'https://login.bol.com/j_spring_security_check';

			if (empty($this->web_proxy_url)) {
				$url = $login_post_url;
			} else {
				$curl_options = $this->_client->getOptions();
				$curl_options[CURLOPT_HTTPHEADER] = [
					'Proxy-Auth: ' . $this->web_proxy_auth,
					'Proxy-Target-URL: '. $login_post_url,
				];
				$this->_client->setOptions($curl_options);
				$url = $this->web_proxy_url;
			}

			$urls = array();
			$urls[] = new \Oara\Curl\Request($url, $valuesLogin);

			$this->_client->post($urls);

		} else {
			throw new \Exception ('Error while retrieving csrf token');
		}


	}

	/**
	 * @return bool
	 */
	public function checkConnection()
	{
		//If not login properly the construct launch an exception
		$connection = false;

		$connection_check_url = 'https://partner.bol.com/orders/rapportages';

		if (empty($this->web_proxy_url)) {
			$url = $connection_check_url;
		} else {
			$curl_options = $this->_client->getOptions();
			$curl_options[CURLOPT_HTTPHEADER] = [
				'Proxy-Auth: ' . $this->web_proxy_auth,
				'Proxy-Target-URL: '. $connection_check_url,
			];
			$this->_client->setOptions($curl_options);
			$url = $this->web_proxy_url;
		}


		$urls = array();
		$urls[] = new \Oara\Curl\Request($url, []);

		$exportReport = $this->_client->get($urls);

		if (\preg_match('/app-root/', $exportReport[0], $match)) {
			$connection = true;
		}
		return $connection;
	}

	/**
	 * @return array
	 */
	public function getNeededCredentials()
	{
		$credentials = array();

		$parameter = array();
		$parameter["description"] = "User Log in";
		$parameter["required"] = true;
		$parameter["name"] = "User";
		$credentials["user"] = $parameter;

		$parameter = array();
		$parameter["description"] = "Password to Log in";
		$parameter["required"] = true;
		$parameter["name"] = "Password";
		$credentials["password"] = $parameter;

		$parameter = array();
		$parameter["description"] = "Proxy to connect";
		$parameter["required"] = true;
		$parameter["name"] = "Proxy";
		$credentials["proxy"] = $parameter;

		$parameter = array();
		$parameter["description"] = "Proxy user/pw";
		$parameter["required"] = true;
		$parameter["name"] = "Proxyuser";
		$credentials["proxyuserpwd"] = $parameter;

		return $credentials;
	}

	/**
	 * @return array
	 */
	public function getMerchantList()
	{
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
	 */
	public function getTransactionList($merchantList = null, \DateTime $dStartDate = null, \DateTime $dEndDate = null)
	{

		$folder = \realpath(\dirname(COOKIES_BASE_DIR)) . '/pdf/';
		$valuesFromExport = array();

		$report_url = 'https://partner.bol.com/orders/v1/reports/orders/26742';

		if (empty($this->web_proxy_url)) {
			$url = $report_url;

			$valuesFromExport[] = new \Oara\Curl\Parameter('startDate', $dStartDate->format("d-m-Y"));
			$valuesFromExport[] = new \Oara\Curl\Parameter('endDate', $dEndDate->format("d-m-Y"));

		} else {

			$valuesFromExport['startDate'] = $dStartDate->format("d-m-Y");
			$valuesFromExport['endDate'] = $dEndDate->format("d-m-Y");

			$curl_options = $this->_client->getOptions();
			$curl_options[CURLOPT_HTTPHEADER] = [
				'Proxy-Auth: ' . $this->web_proxy_auth,
				'Proxy-Target-URL: '. $report_url . '?' . http_build_query($valuesFromExport),
			];
			$this->_client->setOptions($curl_options);
			$url = $this->web_proxy_url;

		}


		$urls = array();
		$urls[] = new \Oara\Curl\Request($url, []);

		$exportReport = $this->_client->get($urls);

		$my_file = $folder . \mt_rand() . '.xlsx';
		$handle = \fopen($my_file, 'w') or die('Cannot open file:  ' . $my_file);
		$data = $exportReport[0];
		\fwrite($handle, $data);
		\fclose($handle);

		$objReader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
		$objReader->setReadDataOnly(true);
		$objPHPExcel = $objReader->load($my_file);

		$totalTransactions = self::getTransationsFromExcel($objPHPExcel);

		unlink($my_file);

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
	private static function mergeProductsFromOneOrder($transactionList) {
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


}
