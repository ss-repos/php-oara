<?php
namespace Oara\Network\Publisher;

class PerformanceHorizon extends \Oara\Network
{

	protected $_sitesAllowed = array();
	private $_pass = null;
	private $_publisherList = null;

	/**
	 * @param $credentials
	 */
	public function login($credentials)
	{
		$this->_pass = $credentials['apipassword'];

	}

	/**
	 * @return array
	 */
	public function getNeededCredentials()
	{
		$credentials = array();

		$parameter = array();
		$parameter["description"] = "API Password";
		$parameter["required"] = true;
		$parameter["name"] = "API Password";
		$credentials["apipassword"] = $parameter;

		return $credentials;
	}

	/**
	 * @return bool
	 */
	public function checkConnection()
	{
		//If not login properly the construct launch an exception
		$connection = true;
		$result = \file_get_contents("https://{$this->_pass}@api.performancehorizon.com/user/publisher.json");
		if ($result == false) {
			$connection = false;
		}
		return $connection;
	}

	/**
	 * @return array
	 */
	public function getMerchantList()
	{
		$merchants = Array();
		$result = \file_get_contents("https://{$this->_pass}@api.performancehorizon.com/user/account.json");
		$publisherList = \json_decode($result, true);
		foreach ($publisherList["user_accounts"] as $publisher) {
			if (isset($publisher["publisher"])) {
				$publisher = $publisher["publisher"];
				$this->_publisherList[$publisher["publisher_id"]] = $publisher["account_name"];
			}
		}

		foreach ($this->_publisherList as $id => $name) {
			$url = "https://{$this->_pass}@api.performancehorizon.com/user/publisher/$id/campaign/a.json";
			$result = \file_get_contents($url);
			$merchantList = \json_decode($result, true);
			foreach ($merchantList["campaigns"] as $merchant) {
				$merchant = $merchant["campaign"];
				$obj = Array();
				$obj['cid'] = \str_replace("l", "", $merchant["campaign_id"]);
				$obj['name'] = $merchant["title"];
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
	 */
	public function getTransactionList($merchantList = null, \DateTime $dStartDate = null, \DateTime $dEndDate = null)
	{
		$transactions = array();
		$merchantIdList = \Oara\Utilities::getMerchantIdMapFromMerchantList($merchantList);

		foreach ($this->_publisherList as $publisherId => $publisherName) {
			$page = 0;
			$import = true;
			while ($import) {

				$offset = ($page * 300);

				$url = "https://{$this->_pass}@api.performancehorizon.com/reporting/report_publisher/publisher/$publisherId/conversion.json?";
				$url .= "status=approved|mixed|pending|rejected";
				$url .= "&start_date=" . \urlencode($dStartDate->format("Y-m-d H:i"));
				$url .= "&end_date=" . \urlencode($dEndDate->format("Y-m-d H:i"));
				$url .= "&offset=" . $offset;

				$result = \file_get_contents($url);
				$conversionList = \json_decode($result, true);

				foreach ($conversionList["conversions"] as $conversion) {

					$conversion = $conversion["conversion_data"];
					$conversion["campaign_id"] = \str_replace("l", "", $conversion["campaign_id"]);
					if (isset($merchantIdList[$conversion["campaign_id"]])) {

						if (\count($this->_sitesAllowed) == 0 || \in_array($conversion["campaign_id"], $this->_sitesAllowed)){
							$transaction = array();
							$transaction['unique_id'] = $conversion["conversion_id"];
							$transaction['merchantId'] = $conversion["campaign_id"];
							$transaction['date'] = $conversion["conversion_time"];

							$transaction['currency'] = $conversion["currency"];
							$transaction['amount'] = $conversion["conversion_value"]["value"];
							$transaction['commission'] = $conversion["conversion_value"]["publisher_commission"];

							if ($conversion["publisher_reference"] != null) {
								$transaction['custom_id'] = $conversion["publisher_reference"];
							}

							if ($conversion["conversion_value"]["conversion_status"] == 'approved') {
								$transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
							} else if ($conversion["conversion_value"]["conversion_status"] == 'pending') {
								$transaction['status'] = \Oara\Utilities::STATUS_PENDING;
							} else if ($conversion["conversion_value"]["conversion_status"] == 'rejected') {
								$transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
							} else if ($conversion["conversion_value"]["conversion_status"] == 'mixed') {
								// mixed: both accepted & rejected items
								// the amount & commission however contain the sum of both approved & rejected items
								// we need to loop over the items and remove the rejected items

								$value = $publisher_commission = 0;
								if (!empty($conversion["conversion_items"])) {
									foreach($conversion["conversion_items"] as $item) {
										if ($item['item_status'] !== 'rejected') {
											$value += $item['item_value'];
											$publisher_commission += $item['item_publisher_commission'];
										}
									}
								}

								$transaction['amount'] = $value;
								$transaction['commission'] = $publisher_commission;

								$transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED; // set to confirmed
							}


							$transactions[] = $transaction;
						}
					}

				}


				if (((int)$conversionList["count"]) < $offset) {
					$import = false;
				}
				$page++;

			}
		}

		return $transactions;
	}

	/**
	 * @return array
	 */
	public function getPaymentHistory()
	{
		$paymentHistory = array();
		foreach ($this->_publisherList as $publisherId => $publisherName) {
			$url = "https://{$this->_pass}@api.performancehorizon.com/user/publisher/$publisherId/selfbill.json?";
			$result = \file_get_contents($url);
			$paymentList = \json_decode($result, true);

			foreach ($paymentList["selfbills"] as $selfbill) {
				$selfbill = $selfbill["selfbill"];
				$obj = array();
				$obj['date'] = $selfbill["timestamp"];
				$obj['pid'] = \intval($selfbill["publisher_self_bill_id"]);
				$obj['value'] = $selfbill["total_value"];
				$obj['method'] = "BACS";
				$paymentHistory[] = $obj;
			}
		}
		return $paymentHistory;
	}

}
