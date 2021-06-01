<?php
namespace Oara\Network\Publisher;

/**
 * API Class
 *
 * @author     Pim van den Broek
 *
 */
class NetAffiliation extends \Oara\Network
{
	protected $_sitesAllowed = array();
	private $_api_password = null;
	private $_username = null;

	/**
	 * @param $credentials
	 * @throws \Exception
	 * @throws \Oara\Curl\Exception
	 */
	public function login($credentials) {

		$this->_api_password = $credentials['apipassword'];
		$this->_username = $credentials['username'];

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
		$parameter["description"] = "username";
		$parameter["required"] = true;
		$parameter["name"] = "username";
		$credentials["username"] = $parameter;

		return $credentials;

	}

	/**
	 * @return bool
	 */
	public function checkConnection() {

		return !empty($this->_api_password);

	}

	/**
	 * @return array
	 */
	public function getMerchantList() {
		$merchants = [];

		$url = 'https://stat.netaffiliation.com/listing.php?authl='.$this->_username.'&authv='.$this->_api_password.'&champ=idcamp,venval,gaiatt,gaival&debut='.date('Y-m-d', strtotime("-30 days")).'&fin='.date('Y-m-d');

		$content = file_get_contents($url);
		$rows = explode(PHP_EOL, $content);

		if (empty($rows[0]) || stripos($rows[0], 'KO') !== false) {
			return [];
		}

		$number_rows = (count($rows) - 1);
		for ($i = 1; $i < $number_rows; $i++) {
			$values = str_getcsv($rows[$i], ';');

			if (!empty($values[0]) && !empty($values[1])) {
				$obj = [];
				$obj['cid'] = $values[0];
				$obj['name'] = $values[1];
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
		$totalTransactions =[];
		$merchantIdList = \Oara\Utilities::getMerchantIdMapFromMerchantList($merchantList);

		$url = 'https://stat.netaffiliation.com/requete.php?authl='.$this->_username.'&authv='.$this->_api_password.'&etat=va&champs=idcampagne,id,gains,montant,monnaie,etat,date,argsite&debut='.$dStartDate->format("Y-m-d").'&fin='.$dEndDate->format("Y-m-d");
		$content = file_get_contents($url);
		$rows = explode(PHP_EOL, $content);

		if (empty($rows[0]) || stripos($rows[0], 'KO') !== false) {
			return [];
		}

		$number_rows = (count($rows) - 1);
		for ($i = 1; $i < $number_rows; $i++) {
			$values = str_getcsv($rows[$i], ';');
			if (!empty($values[0]) && !empty($values[1])) {

				$transaction = [];
				$transaction['merchantId'] = $values[0];
				$transaction['unique_id'] = $values[0] . '-' . $values[1];

				$transaction['date'] = $values[6];
				$transaction['custom_id'] = $values[7];

				$status = $values[5];
				if ($status == 'v') {
					$transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
				} else if ($status == 'r') {
					$transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
				} else if ($status == 'a') {
					$transaction['status'] = \Oara\Utilities::STATUS_PENDING;
				} else {
					throw new \Exception ("Status not found");
				}
				$transaction['amount'] = \Oara\Utilities::parseDouble($values[3]);
				$transaction['commission'] = \Oara\Utilities::parseDouble($values[2]);
				$totalTransactions[] = $transaction;
			}
		}

		return $totalTransactions;
	}

}
