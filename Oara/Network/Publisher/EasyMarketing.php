<?php
namespace Oara\Network\Publisher;

/**
 *
 * @author     Pim van den Broek
 * @version    Release: 01.00
 *
 */

class EasyMarketing extends \Oara\Network {

	private array $_credentials = [];

	/**
	 * @param array $credentials
	 * @return bool
	 */
	public function login(array $credentials): bool {

		$this->_credentials = $credentials;

		return true;

	}

	/**
	 * @return bool
	 */
	public function checkConnection(): bool {

		return true;

	}

	/**
	 * @return array
	 */
	public function getMerchantList(): array {
		$merchants = [];

		$obj['cid'] = "3";
		$obj['name'] = "Media Markt E-Business GmbH";
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

		$url = 'https://pvn.mediamarkt.de/api//'.$this->_credentials['api_token'].'/publisher/'.$this->_credentials['publisher_id'].'/get-statistic_transactions.xml?condition[mode]=mine&condition[period][from]='.$dStartDate->format('Y-m-d').'&condition[period][to]='.$dEndDate->format('Y-m-d').'&condition[l:campaigns]=1';

		$res = \simplexml_load_string(self::callAPI($url));
		if ($res) {

			foreach ($res->item as $item) {
				$transaction = [];
				$transaction['merchantId'] = (int)$item->advertiser_id;

				$transaction['date'] = (string)$item->trackingtime;

				$transaction['unique_id'] = (string)$item->criterion;
				$transaction['custom_id'] = urldecode($item->subid);

				$status = (string)$item->status;
				if ($status == 2) {
					$transaction['status'] = \Oara\Utilities::STATUS_DECLINED;
				} else if ($status == 1) {
					$transaction['status'] = \Oara\Utilities::STATUS_CONFIRMED;
				} else {
					$transaction['status'] = \Oara\Utilities::STATUS_PENDING;
				}

				$transaction['amount'] = (string)$item->turnover;
				$transaction['commission'] = (string)$item->provision;
				$totalTransactions[] = $transaction;
			}


		}

		return $totalTransactions;
	}

	private function callAPI(string $url) {

		$curl = curl_init($url);

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

		$response = curl_exec($curl);

		$error = curl_error($curl);
		if (empty($error) === false) {
			var_dump($error);
			die;
		}

		curl_close($curl);

		return $response;
	}



}
