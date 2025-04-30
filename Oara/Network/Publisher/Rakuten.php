<?php
namespace Oara\Network\Publisher;

/**
 * API Class
 *
 * We do not use the official API, since you can only query back 30 days with it
 * Instead, we use the report-api-calls. This requires an "advertisers" report to be created containing the following columns:
 * MID | Advertiser Name | # of Clicks
 *
 *
 * @author     Pim van den Broek
 *
 *
 */
class Rakuten extends \Oara\Network {

	private $client;
	private $_credentials = null;

	/**
	 * @param $credentials
	 */
	public function login($credentials) {

		$this->_credentials['token'] = $credentials['token'];
		if (!empty($credentials['network'])) {
			$this->_credentials['network'] = $credentials['network'];
		}


		// Does not work properly
		// Whole API class is not being used at the moment
//        $username = $credentials['user'];
//        $password = $credentials['password'];
//        $id = $credentials['id'];
//		  $this->client = new RakuteAPI($username, $password, $id);

	}

	/**
	 * @return bool
	 */
	public function checkConnection(): bool {

		return true;
	}

	/**
	 * @return array
	 * @throws \Exception
	 */
	public function getMerchantList(): array {

		$url = 'https://ran-reporting.rakutenmarketing.com/en/reports/api-orders-report/filters?date_range=last-7-days&include_summary=N&tz=GMT&date_type=transaction&token=' . $this->_credentials['token'];

		if (!empty($this->_credentials['network'])) {
			$url .= '&network=' . $this->_credentials['network'];
		}

		$csv_report = trim(file_get_contents($url));

		// -------- remove the utf-8 BOM ----
		$csv_report = str_replace("\xEF\xBB\xBF",'',$csv_report);

		$csv_array = str_getcsv($csv_report, "\n");

		$header = str_getcsv(array_shift($csv_array));

		foreach($csv_array as $row) {
			$row = str_getcsv($row);
			$merchant_list[] = array_combine($header, $row);
		}
		$merchants = array();

		if (!empty($merchant_list)) {

			foreach ($merchant_list as $id => $merchant) {

				$obj = Array();
				$obj['cid'] = $merchant['MID'];
				$obj['name'] = $merchant['Advertiser Name'];

				$merchants[] = $obj;
			}
		}

		// if there are no sales in the dateperiod, we want to return some dummy data to avoid an error because the merchants are empty
		if (empty($merchants) && !empty($csv_report)) {
			$obj = Array();
			$obj['cid'] = '35718';
			$obj['name'] = 'ASOS (UK)';
			$merchants[] = $obj;
		}


		return $merchants;

	}

	public function getTransactionList($merchantList = null, \DateTime $dStartDate = null, \DateTime $dEndDate = null): array {
		$totalTransactions = array();

		$merchantIdList = \Oara\Utilities::getMerchantIdMapFromMerchantList($merchantList);

		$transactionList = [];

		$url = 'https://ran-reporting.rakutenmarketing.com/en/reports/api-orders-report/filters?start_date='.$dStartDate->format("Y-m-d").'&end_date='.$dEndDate->sub(new \DateInterval("P1D"))->format("Y-m-d").'&include_summary=N&tz=GMT&date_type=transaction&token=' . $this->_credentials['token'];

		if (!empty($this->_credentials['network'])) {
			$url .= '&network=' . $this->_credentials['network'];
		}

		// calls sometimes take a loooooong time...
		ini_set('default_socket_timeout', 5*60); // 5 minutes
		$csv_report = file_get_contents($url);

		$array = str_getcsv($csv_report, "\n");

		$header = str_getcsv(array_shift($array));

		foreach($array as $row) {
			$row = str_getcsv($row);
			$transactionList[] = array_combine($header, $row);

		}

		if ($transactionList) {
			foreach ($transactionList as $transaction) {
				$merchantId = $transaction['MID'];
				if (isset($merchantIdList[$merchantId])) {

					$transactionArray = Array();
					$transactionArray['unique_id'] = $transaction['Order ID'];
					$transactionArray['merchantId'] = $merchantId;
					$transactionDate = new \DateTime($transaction['Transaction Date'] . ' ' . $transaction['Transaction Time']);
					$transactionArray['date'] = $transactionDate->format("Y-m-d H:i:s");
					if (!empty($transaction['Member ID (U1)']) && $transaction['Member ID (U1)'] != 'null') {
						$transactionArray['custom_id'] = $transaction['Member ID (U1)'];
					}

					$transactionArray['status'] = \Oara\Utilities::STATUS_PENDING; // no status info, so use pending

					$transactionArray['amount'] = \Oara\Utilities::parseDouble($transaction['Sales']);
					$transactionArray['commission'] = \Oara\Utilities::parseDouble($transaction['Total Commission']);

					/**
					 *  We have noticed that the custom_id is not always present for the most recent transactions, these
					 *  are most likely added through a batch process. For this reason we skip transactions if they do
					 *  not contain a custom_id and are less than 12h old.
					 */
					if((empty($transactionArray['custom_id'])) && (intval($transactionDate->diff(new \DateTime())->format('%h')) < 12)) {
						continue;
					}

					$totalTransactions[] = $transactionArray;
				}
			}
		}

		return $totalTransactions;
	}



}

class RakuteAPI {
	public $domain = "https://api.rakutenmarketing.com/%s/%s";
	/**
	 * Curl handle
	 *
	 * @var resource
	 */
	protected $curl;
	/**
	 * API Key for authenticating requests
	 *
	 * @var string
	 */
	protected $api_key;
	/**
	 * The Commission Junction API Client is completely self contained with it's own API key.
	 * The cURL resource used for the actual querying can be overidden in the contstructor for
	 * testing or performance tweaks, or via the setCurl() method.
	 *
	 * @param string $api_key API Key
	 * @param null|resource $curl Manually provided cURL handle
	 */
	public function __construct($username, $password, $id, $curl = null) {

		$this->api_key = $this->getToken($username, $password, $id);
		if ($curl) $this->setCurl($curl);
	}

	/**
	 * Convenience method to access Product Catalog Search Service
	 *
	 * @param array $parameters GET request parameters to be appended to the url
	 * @return array Commission Junction API response, converted to a PHP array
	 * @throws Exception on cURL failure or http status code greater than or equal to 400
	 */
	public function productSearch(array $parameters = array()) {
		return $this->api("productsearch", "productsearch", $parameters);
	}
	public function getToken($username, $password, $id)
	{
		return $this->apiToken($username, $password, $id);
	}

	public function getMerchants(array $parameters = array()) {
		return $this->api("advertisersearch", '', $parameters);
	}

	public function getTransactions(array $parameters = array()) {
		$data = $this->api("events", "transactions", $parameters);
		return json_decode($data, true);

	}

	/**
	 * Convenience method to access Commission Detail Service
	 *
	 * @param array $parameters GET request parameters to be appended to the url
	 * @return array Commission Junction API response, converted to a PHP array
	 * @throws Exception on cURL failure or http status code greater than or equal to 400
	 */
	private function commissionDetailLookup(array $parameters = array()) {
		throw new \Exception("Not implemented");
	}

	/**
	 * Generic method to fire API requests at Commission Junctions servers
	 *
	 * @param string $subdomain The subomdain portion of the REST API url
	 * @param string $resource The resource portion of the REST API url (e.g. /v2/RESOURCE)
	 * @param array $parameters GET request parameters to be appended to the url
	 * @param string $version The version portion of the REST API url, defaults to v2
	 * @return array Commission Junction API response, converted to a PHP array
	 * @throws Exception on cURL failure or http status code greater than or equal to 400
	 */
	public function api($subdomain, $resource, array $parameters = array(), $version = '1.0') {
		$ch = $this->getCurl();
		$url = sprintf($this->domain, $subdomain, $version);

		if (!empty($resource)) {
			$url .= '/' . $resource;
		}

		if (!empty($parameters)) {
			$url .= "?" . http_build_query($parameters);
		}

		curl_setopt_array($ch, array(
			CURLOPT_URL  => $url,
			CURLOPT_HTTPHEADER => array(
				'Accept: application/xml',
				'authorization: ' . 'Bearer ' . $this->api_key,
			)
		));
		$body = curl_exec($ch);
		$errno = curl_errno($ch);
		if ($errno !== 0) {
			throw new \Exception(sprintf("Error connecting to Rakuten: [%s] %s", $errno, curl_error($ch)), $errno);
		}

		$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($http_status >= 400) {
			throw new \Exception(sprintf("Rakuten Error [%s] %s", $http_status, strip_tags($body)), $http_status);
		}

		return $body;
	}

	public function apiToken($username, $password, $scope) {
		$data = array("grant_type" => "password", "username" => $username, 'password' => $password, 'scope' => $scope);

		$ch = curl_init('https://api.rakutenmarketing.com/token');
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/x-www-form-urlencoded',
			'Authorization: Basic TW1FSkF1WGVDN0pnNVJDTDVLQW9DTHlTQ3VnYTpqV1hqdnZselhNY29PYlI1c1ZTYWxtZnZJU1Vh',
		));
		$body = curl_exec($ch);
		$errno = curl_errno($ch);
		if ($errno !== 0) {
			throw new \Exception(sprintf("Error connecting to Rakuten Token : [%s] %s", $errno, curl_error($ch)), $errno);
		}

		$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($http_status >= 400) {
			throw new \Exception(sprintf("Rakuten Error Token  [%s] %s", $http_status, strip_tags($body)), $http_status);
		}
		$result = json_decode($body);

		if (!empty($result->access_token)) {
			return $result->access_token;
		}
		return NULL;
	}

	/**
	 * @param resource $curl
	 */
	public function setCurl($curl) {
		$this->curl = $curl;
	}
	/**
	 * @return resource
	 */
	public function getCurl() {
		if (!is_resource($this->curl)) {
			$this->curl = curl_init();
			curl_setopt_array($this->curl, array(
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_MAXREDIRS      => 1,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => 30,
			));
		}
		return $this->curl;
	}
}