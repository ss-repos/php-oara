<?php
namespace Oara\Network\Publisher;

/**
 * Api Class
 *
 * @author     Pim
 * @version    Release: 01.00
 *
 */
class Webgains extends \Oara\Network
{
	private $_apiClient = null, $username, $password, $campaign_ids;

	public function login($credentials)
	{
		$this->username = $credentials['user'];
		$this->password = $credentials['password'];

		$this->campaign_ids = $credentials['program_ids'];

		$wsdlUrl = 'http://ws.webgains.com/aws.php';
		//Setting the client.
		$this->_apiClient = new \SoapClient($wsdlUrl, array(
			'encoding' => 'UTF-8',
			'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP | SOAP_COMPRESSION_DEFLATE,
			'soap_version' => SOAP_1_1,
			'keep_alive' => false
		));


		$campaign_id = $this->campaign_ids[0];

		$result = $this->_apiClient->getAccountBalance($this->username, $this->password, $campaign_id);
		return empty($result);
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

		return $credentials;
	}

	/**
	 * @return bool
	 */
	public function checkConnection()
	{
		$connection = true;
		return $connection;
	}

	/**
	 * @return array
	 */
	public function getMerchantList()
	{
		$merchants = array();

		foreach ($this->campaign_ids as $campaign_id) {

			$campaignsList = $this->_apiClient->getProgramsWithMembershipStatus($this->username, $this->password, $campaign_id);

			foreach ($campaignsList as $campaign) {
				if (!isset($merchants[$campaign->programName]) && $campaign->programMembershipStatusCode == 10) {
					$obj = Array();
					$obj['cid'] = $campaign->programID;
					$obj['name'] = $campaign->programName;
					$obj['url'] = $campaign->programURL;
					$merchants[$campaign->programName] = $obj;
				}
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


		$totalTransactions = array();
		$merchantIdList = \Oara\Utilities::getMerchantIdMapFromMerchantList($merchantList);

		foreach ($this->campaign_ids as $campaign_id) {

			$start_date = $dStartDate->format('Y-m-d');
			$end_date = $dEndDate->add(new \DateInterval('P1D'))->format('Y-m-d');

			$transactions = $this->_apiClient->getFullEarningsWithCurrency($start_date, $end_date, $campaign_id, $this->username, $this->password);

			foreach ($transactions as $transaction) {
				if ($merchantList == null || isset($merchantIdList[(int)$transaction->programID])) {
					$object = array();

					$object['unique_id'] = $transaction->transactionID;

					$object['merchantId'] = $transaction->programID;

					$transactionDate = new \DateTime($transaction->date);
					$object['date'] = $transactionDate->format("Y-m-d H:i:s");

					if ($transaction->clickRef != null) {
						$object['custom_id'] = $transaction->clickRef;
					}

					if ($transaction->status == 'confirmed' || $transaction->status == 'accepted') {
						$object['status'] = \Oara\Utilities::STATUS_CONFIRMED;
					} else
						if ($transaction->status == 'delayed') {
							$object['status'] = \Oara\Utilities::STATUS_PENDING;
						} else
							if ($transaction->status == 'cancelled' || $transaction->status == 'rejected') {
								$object['status'] = \Oara\Utilities::STATUS_DECLINED;
							}

					$object['amount'] = \Oara\Utilities::parseDouble($transaction->saleValue);
					$object['commission'] = \Oara\Utilities::parseDouble($transaction->commission);
					$totalTransactions[] = $object;
				}
			}
		}

		return $totalTransactions;
	}


}
