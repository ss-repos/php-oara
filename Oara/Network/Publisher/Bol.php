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

		$connection_check_url = 'https://partner.bol.com/partner/index.do';

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

		if (\preg_match('/account\/uitloggen/', $exportReport[0], $match)) {
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
		$totalTransactions = array();
		$valuesFromExport = array();

		$report_url = 'https://partner.bol.com/partner/s/excelReport/orders';

		if (empty($this->web_proxy_url)) {
			$url = $report_url;

			$valuesFromExport[] = new \Oara\Curl\Parameter('id', "-1");
			$valuesFromExport[] = new \Oara\Curl\Parameter('yearStart', $dStartDate->format("Y"));
			$valuesFromExport[] = new \Oara\Curl\Parameter('monthStart', $dStartDate->format("m"));
			$valuesFromExport[] = new \Oara\Curl\Parameter('dayStart', $dStartDate->format("d"));
			$valuesFromExport[] = new \Oara\Curl\Parameter('yearEnd', $dEndDate->format("Y"));
			$valuesFromExport[] = new \Oara\Curl\Parameter('monthEnd', $dEndDate->format("m"));
			$valuesFromExport[] = new \Oara\Curl\Parameter('dayEnd', $dEndDate->format("d"));

		} else {

			$valuesFromExport['id'] = "-1";
			$valuesFromExport['yearStart'] = $dStartDate->format("Y");
			$valuesFromExport['monthStart'] = $dStartDate->format("m");
			$valuesFromExport['dayStart'] = $dStartDate->format("d");
			$valuesFromExport['yearEnd'] = $dEndDate->format("Y");
			$valuesFromExport['monthEnd'] = $dEndDate->format("m");
			$valuesFromExport['dayEnd'] = $dEndDate->format("d");

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

		$objReader = \PHPExcel_IOFactory::createReader('Excel2007');
		$objReader->setReadDataOnly(true);
		$objPHPExcel = $objReader->load($my_file);
		$objWorksheet = $objPHPExcel->getActiveSheet();
		$highestRow = $objWorksheet->getHighestRow();

		for ($row = 2; $row <= $highestRow; ++$row) {


			$transaction = Array();
			$transaction['unique_id'] = $objWorksheet->getCellByColumnAndRow(0, $row)->getValue() . "_" . $objWorksheet->getCellByColumnAndRow(1, $row)->getValue();
			$transaction['merchantId'] = "1";
			$transactionDate = \DateTime::createFromFormat("d-m-Y", $objWorksheet->getCellByColumnAndRow(2, $row)->getValue());
			$transaction['date'] = $transactionDate->format("Y-m-d 00:00:00");
			$transaction['custom_id'] = $objWorksheet->getCellByColumnAndRow(8, $row)->getValue();
			if ($objWorksheet->getCellByColumnAndRow(14, $row)->getValue() == 'geaccepteerd') {
				$transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
			} else
				if ($objWorksheet->getCellByColumnAndRow(14, $row)->getValue() == 'in behandeling') {
					$transaction['status'] = \Oara\Utilities::STATUS_PENDING;
				} else
					if ($objWorksheet->getCellByColumnAndRow(14, $row)->getValue() == 'geweigerd: klik te oud' || $objWorksheet->getCellByColumnAndRow(14, $row)->getValue() == 'geweigerd') {
						$transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
					} else {
						throw new \Exception("new status " . $objWorksheet->getCellByColumnAndRow(14, $row)->getValue());
					}
			$transaction['amount'] = \Oara\Utilities::parseDouble(round($objWorksheet->getCellByColumnAndRow(11, $row)->getValue(), 2));
			$transaction['commission'] = \Oara\Utilities::parseDouble(round($objWorksheet->getCellByColumnAndRow(12, $row)->getValue(), 2));
			$totalTransactions[] = $transaction;

		}
		unlink($my_file);

		return $totalTransactions;
	}




}
