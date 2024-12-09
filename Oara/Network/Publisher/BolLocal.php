<?php
namespace Oara\Network\Publisher;

/**
 * BolLocal class
 *
 * Can be used when the original BOL connector fails
 *
 * @author     Pim van den Broek
 * @version    Release: 01.00
 *
 */

class BolLocal extends \Oara\Network
{

	private $folder, $filename = null;

	/**
	 * @param $credentials
	 * @return bool
	 */
	public function login($credentials): bool {

		$this->folder = $credentials['folder'];
		$this->filename = $credentials['filename'];

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

		$folder = realpath(\dirname(COOKIES_BASE_DIR)) . DS. $this->folder . DS;

		$filename = $folder . $this->filename;

		if (!file_exists($filename)) {
			throw new \Exception("error opening BOL file " . $filename);
		}

		$objReader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
		$objReader->setReadDataOnly(true);
		$objPHPExcel = $objReader->load($filename);

		$totalTransactions = self::getTransactionsFromExcel($objPHPExcel);

		return $totalTransactions;
	}




	private static function getTransactionsFromExcel($objPHPExcel): array {
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

		return Bol::mergeProductsFromOneOrder($totalTransactions);
	}



}
