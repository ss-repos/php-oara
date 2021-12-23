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

		$totalTransactions = Bol::getTransationsFromExcel($objPHPExcel);

		return $totalTransactions;
	}

}
