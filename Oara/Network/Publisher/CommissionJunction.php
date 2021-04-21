<?php
namespace Oara\Network\Publisher;

/**
 * Export Class
 *
 * @author     Pim van den Broek
 * @category   Cj
 * @version    Release: 01.00
 *
 */
class CommissionJunction extends \Oara\Network
{
	private $_apiPassword = null;
	private $cid = null;

	/**
	 * @param $credentials
	 */
	public function login($credentials)
	{

		$this->cid = $credentials['cid'];
		$this->_apiPassword = $credentials['apipassword'];

		return true;

	}

	/**
	 * @return array
	 */
	public function getNeededCredentials() {
		$credentials = array();

		$parameter = array();
		$parameter["description"] = "API Password ";
		$parameter["required"] = true;
		$parameter["name"] = "API";
		$credentials["apipassword"] = $parameter;

		$parameter = array();
		$parameter["description"] = "CID";
		$parameter["required"] = true;
		$parameter["name"] = "CID";
		$credentials["cid"] = $parameter;

		return $credentials;
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

		$restUrl = 'https://advertiser-lookup.api.cj.com/v2/advertiser-lookup?requestor-cid=' . $this->cid . '&advertiser-ids=joined';

		$response = self::apiCall($restUrl);

		$xml = \simplexml_load_string($response, null, LIBXML_NOERROR | LIBXML_NOWARNING);

		if (!empty($xml->advertisers->advertiser)) {
			foreach($xml->advertisers->advertiser as $advertiser) {

				$obj = Array();
				$obj['cid'] = (int) $advertiser->{'advertiser-id'};
				$obj['name'] = (string) $advertiser->{'advertiser-name'};
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
	public function getTransactionList($merchantList = null, \DateTime $dStartDate = null, \DateTime $dEndDate = null) {

		$totalTransactions = Array();

		$restUrl = 'https://commissions.api.cj.com/query';

		$startDate = clone $dStartDate;

		$max_days = 31;

		do {
			$endDate = clone $dEndDate;

			// Get the results per 31 days until the end date is the same as the wanted end date
			// (API usage caps/limits - Date ranges of no more than 31 days are allowed in the arguments (https://developers.cj.com/graphql/reference/Commission%20Detail))
			if ($startDate->diff($dEndDate)->format('%a') > $max_days) {

				$endDate = clone $startDate;
				$endDate->add(new \DateInterval('P'.$max_days.'D')); // set new end date

				if ($endDate > $dEndDate) { // check to not proceed the original end date
					$endDate = $dEndDate;
				}

			}

			$post_data = '{ publisherCommissions (forPublishers:"'.$this->cid.'", sincePostingDate:"'.$startDate->format("Y-m-d\TH:i:s\Z").'",beforePostingDate:"'.$endDate->format("Y-m-d\TH:i:s\Z").'"){count payloadComplete records {actionStatus actionType commissionId  orderId	 reviewedStatus saleAmountPubCurrency validationStatus   websiteName advertiserId advertiserName postingDate pubCommissionAmountUsd pubCommissionAmountPubCurrency shopperId }  } }';

			$totalTransactions = array_merge($totalTransactions, self::getTransactions($restUrl, $post_data));

			$startDate = clone $endDate;
		} while ($endDate < $dEndDate);

		return $totalTransactions;

	}


	/**
	 * @param $restUrl
	 * @param $merchantList
	 * @return array
	 */
	private function getTransactions($restUrl, $post_data) {

		$raw_transactions = [];

		// This api uses cursor-based paging. If the value of payloadComplete is false, there is more data to get. Read the value of maxCommissionId to get current cursor position. In your subsequent query call, set the sinceCommissionId argument to this value. Repeat until payloadComplete is true. (https://developers.cj.com/graphql/reference/Commission%20Detail)
		do {
			$response = self::apiCall($restUrl, $post_data);
			$result = json_decode($response, true);

			$raw_transactions  = array_merge($raw_transactions, $result['data']['publisherCommissions']['records']);

		} while ($result['data']['publisherCommissions']['payloadComplete'] != '1');

		$totalTransactions = array();
		foreach($raw_transactions as $raw_transaction) {
			$transaction = Array();
			$transaction ['unique_id'] = $raw_transaction['orderId'];
			$transaction ['action'] = $raw_transaction['actionType'];
			$transaction['merchantId'] = $raw_transaction['advertiserId'];
			$transactionDate = \DateTime::createFromFormat("Y-m-d\TH:i:s", \substr($raw_transaction['postingDate'], 0, 19));
			$transaction['date'] = $transactionDate->format("Y-m-d H:i:s");

			$transaction['custom_id'] = $raw_transaction['shopperId'] ?? '';

			$transaction['amount'] = $raw_transaction['saleAmountPubCurrency'];
			$transaction['commission'] = $raw_transaction['pubCommissionAmountPubCurrency'];

			if ($raw_transaction['validationStatus'] == 'ACCEPTED') {
				$transaction ['status'] = \Oara\Utilities::STATUS_CONFIRMED;
			} else if ($raw_transaction['validationStatus'] == 'DECLINED') {
				$transaction ['status'] = \Oara\Utilities::STATUS_DECLINED;
			} else {
				$transaction ['status'] = \Oara\Utilities::STATUS_PENDING;
			}

			if ($transaction ['commission'] == 0) {
				$transaction ['status'] = \Oara\Utilities::STATUS_PENDING;
			}

			if ($transaction ['amount'] < 0 || $transaction ['commission'] < 0) {
				$transaction ['status'] = \Oara\Utilities::STATUS_DECLINED;
				$transaction ['amount'] = \abs($transaction ['amount']);
				$transaction ['commission'] = \abs($transaction ['commission']);
			}
			$totalTransactions[] = $transaction;

		}

		return $totalTransactions;
	}

	private function apiCall($url, $post_data = null) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: Bearer " . $this->_apiPassword));

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


}
