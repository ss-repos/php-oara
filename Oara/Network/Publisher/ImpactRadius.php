<?php
namespace Oara\Network\Publisher;
/**
 * The goal of the Open Affiliate Report Aggregator (OARA) is to develop a set
 * of PHP classes that can download affiliate reports from a number of affiliate networks, and store the data in a common format.
 *
 * Copyright (C) 2016  Fubra Limited
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Contact
 * ------------
 * Fubra Limited <support@fubra.com> , +44 (0)1252 367 200
 **/

/**
 * Export Class
 *
 * @author     Carlos Morillo Merino
 * @category   Smg
 * @copyright  Fubra Limited
 * @version    Release: 01.00
 *
 */
class ImpactRadius extends \Oara\Network
{
	private $_credentials = null;
	private $_accounts = null;

	/**
	 * @param $credentials
	 * @throws Exception
	 * @throws \Exception
	 * @throws \Oara\Curl\Exception
	 */
	public function login($credentials)
	{
		$this->_credentials = $credentials;
		$account['accountSid'] = $credentials['accountSID'];
		$account['authToken'] = $credentials['authToken'];
		$this->_accounts[] = $account;
	}

	/**
	 * @return array
	 */
	public function getNeededCredentials()
	{
		$credentials = array();

		$parameter = array();
		$parameter["description"] = "Account SID";
		$parameter["required"] = true;
		$parameter["name"] = "accountSID";
		$credentials["accountSID"] = $parameter;

		$parameter = array();
		$parameter["description"] = "API token";
		$parameter["required"] = true;
		$parameter["name"] = "authToken";
		$credentials["authToken"] = $parameter;

		return $credentials;
	}

	/**
	 * @return bool
	 */
	public function checkConnection()
	{
		$connection = false;

		$api_connected = true;
		foreach ($this->_accounts as $account) {
			//Checking API connection from Impact Radius
			$uri = "https://" . $account['accountSid'] . ":" . $account['authToken'] . "@api.impactradius.com/2010-09-01/Mediapartners/" . $account['accountSid'] . "/Campaigns.xml";
			$res = \simplexml_load_string(self::call($uri));
			if (!isset($res->Campaigns)) {
				$api_connected = false;
				break;
			}
		}

		if ($api_connected) {
			$connection = true;
		}

		return $connection;
	}

	/**
	 * @return array
	 */
	public function getMerchantList()
	{
		$merchantReportList = self::getMerchantReportList();
		$merchants = Array();
		foreach ($merchantReportList as $key => $value) {
			$obj = Array();
			$obj['cid'] = $key;
			$obj['name'] = $value;
			$merchants[] = $obj;
		}
		return $merchants;
	}

	/**
	 * It returns an array with the different merchants
	 * @return array
	 */
	private function getMerchantReportList()
	{
		foreach ($this->_accounts as $account) {
			$uri = "https://" . $account['accountSid'] . ":" . $account['authToken'] . "@api.impactradius.com/2010-09-01/Mediapartners/" . $account['accountSid'] . "/Campaigns.xml";
			$res = \simplexml_load_string(self::call($uri));
			$currentPage = (int)$res->Campaigns->attributes()->page;
			$pageNumber = (int)$res->Campaigns->attributes()->numpages;
			while ($currentPage <= $pageNumber) {

				foreach ($res->Campaigns->Campaign as $campaign) {
					$campaignId = (int)$campaign->CampaignId;
					$campaignName = (string)$campaign->CampaignName;
					$merchantReportList[$campaignId] = $campaignName;
				}

				$currentPage++;
				$nextPageUri = (string)$res->Campaigns->attributes()->nextpageuri;
				if ($nextPageUri != null) {
					$res = \simplexml_load_string(self::call("https://" . $account['accountSid'] . ":" . $account['authToken'] . "@api.impactradius.com" . $nextPageUri));
				}
			}
		}
		return $merchantReportList;
	}

	/**
	 * @param null $merchantList
	 * @param \DateTime|null $dStartDate
	 * @param \DateTime|null $dEndDate
	 * @return array
	 */
	public function getTransactionList($merchantList = null, \DateTime $dStartDate = null, \DateTime $dEndDate = null)
	{
		$totalTransactions = Array();

		foreach ($this->_accounts as $account) {

			//New Interface
			$uri = "https://" . $account['accountSid'] . ":" . $account['authToken'] . "@api.impactradius.com/2010-09-01/Mediapartners/" . $account['accountSid'] . "/Actions?ActionDateStart=" . $dStartDate->format('Y-m-d\TH:i:s') . "-00:00&ActionDateEnd=" . $dEndDate->format('Y-m-d\TH:i:s') . "-00:00";
			$res = \simplexml_load_string(self::call($uri));
			if ($res) {

				$currentPage = (int)$res->Actions->attributes()->page;
				$pageNumber = (int)$res->Actions->attributes()->numpages;
				while ($currentPage <= $pageNumber) {

					foreach ($res->Actions->Action as $action) {
						$transaction = Array();
						$transaction['merchantId'] = (int)$action->CampaignId;

						$transactionDate = \DateTime::createFromFormat("Y-m-d\TH:i:s", \substr((string)$action->EventDate,0,19));
						$transaction['date'] = $transactionDate->format("Y-m-d H:i:s");

						$transaction['unique_id'] = (string)$action->Id;
						if ((string)$action->SharedId != '') {
							$transaction['custom_id'] = (string)$action->SharedId;
						}
						if ((string)$action->SubId1 != '') {
							$transaction['custom_id'] = (string)$action->SubId1;
						}

						$status = (string)$action->Status;
						$statusArray[$status] = "";
						if ($status == 'APPROVED' || $status == 'DEFAULT') {
							$transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
						} else
							if ($status == 'REJECTED') {
								$transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
							} else {
								$transaction['status'] = \Oara\Utilities::STATUS_PENDING;
							}

						$transaction['amount'] = (double)$action->Amount;
						$transaction['commission'] = (double)$action->Payout;
						$totalTransactions[] = $transaction;
					}

					$currentPage++;
					$nextPageUri = (string)$res->Actions->attributes()->nextpageuri;
					if ($nextPageUri != null) {
						$res = \simplexml_load_string(self::call("https://" . $account['accountSid'] . ":" . $account['authToken'] . "@api.impactradius.com" . $nextPageUri));
					}
				}
			}
		}

		return $totalTransactions;

	}

	/**
	 * @return array
	 * @throws Exception
	 */
	public function getPaymentHistory()
	{
		$paymentHistory = array();

		$urls = array();
		$urls[] = new \Oara\Curl\Request('https://member.impactradius.co.uk/secure/nositemesh/accounting/getPayStubParamsCSV.csv', array());
		$exportReport = $this->_client->get($urls);
		$exportData = \str_getcsv($exportReport[0], "\n");

		$num = \count($exportData);
		for ($i = 1; $i < $num; $i++) {
			$paymentExportArray = \str_getcsv($exportData[$i], ",");
			$obj = array();
			$date = \DateTime::createFromFormat("M d, Y", $paymentExportArray[1]);
			$obj['date'] = $date->format("y-m-d H:i:s");
			$obj['pid'] = $paymentExportArray[0];
			$obj['method'] = 'BACS';
			$obj['value'] = \Oara\Utilities::parseDouble($paymentExportArray[6]);
			$paymentHistory[] = $obj;
		}
		return $paymentHistory;
	}


	/**
	 * @param $apiUrl
	 * @return mixed
	 */
	private function call($apiUrl)
	{
		// Initiate the REST call via curl
		$ch = \curl_init($apiUrl);
		// Set the HTTP method to GET
		\curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
		// Don't return headers
		\curl_setopt($ch, CURLOPT_HEADER, false);
		// Return data after call is made
		\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		// Execute the REST call
		$response = \curl_exec($ch);
		// Close the connection
		\curl_close($ch);
		return $response;
	}

}
