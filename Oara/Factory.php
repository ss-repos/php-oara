<?php
/**
   The goal of the Open Affiliate Report Aggregator (OARA) is to develop a set 
   of PHP classes that can download affiliate reports from a number of affiliate networks, and store the data in a common format.
   
    Copyright (C) 2014  Carlos Morillo Merino
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or any later version.
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.
    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
	and we should add some contact information
**/	
/**
 * Implementation Class
 *
 * @author     Carlos Morillo Merino
 * @category   Oara_Factory
 * @copyright  Fubra Limited
 * @version    Release: 01.00
 *
 */
class Oara_Factory {
	/**
	 * Factory create instance function, It returns the specific Affiliate Network
	 *
	 * @param $credentials
	 * @return Oara_Network
	 * @throws Exception
	 */
	public static function createInstance($credentials) {

		$affiliate = null;
		$networkName = $credentials['networkName'];
		try {
			$networkClassName = 'Oara_Network_'.$credentials["type"].'_'.$networkName;
			$affiliate = new $networkClassName($credentials);
		} catch (Exception $e) {
			throw new Exception('No Network Available '.$networkName);
		}
		return $affiliate;

	}

}
