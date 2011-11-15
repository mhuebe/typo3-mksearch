<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2011 das Medienkombinat GmbH
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

require_once(t3lib_extMgm::extPath('rn_base') . 'class.tx_rnbase.php');
tx_rnbase::load('tx_phpunit_database_testcase');

class tx_mksearch_tests_service_internal_Index_testcase extends tx_phpunit_database_testcase {
	
	
	/**
     * Sets up the fixture, for example, open a network connection.
     * This method is called before a test is executed.
     * 
	 * setUp() = init DB etc.
	 */
	protected function setUp(){
		
		$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['devlog']['nolog'] = true;
		
		$this->createDatabase();
		// assuming that test-database can be created otherwise PHPUnit will skip the test
		$this->useTestDatabase();
		$this->importStdDB();
		$this->importExtensions(array('mksearch'));
	}

	/**
     * Tears down the fixture, for example, close a network connection.
     * This method is called after a test is executed.
     * 
	 * tearDown() = destroy DB etc.
	 */
	protected function tearDown () {
		$this->cleanDatabase();
		$this->dropDatabase();
		$GLOBALS['TYPO3_DB']->sql_select_db(TYPO3_db);
	}
	
	function testAddRecordToIndex() {
		$srv = tx_mksearch_util_ServiceRegistry::getIntIndexService();
		$srv->addRecordToIndex('tx_mktest_table', 50);
		$res = tx_rnbase_util_DB::doSelect('*', 'tx_mksearch_queue', array('enablefieldsoff' => true));
		
		$this->assertEquals(1, count($res), 'Wrong row count in queue.');
		
		$first = &$res[0];
		$this->assertEquals('tx_mktest_table', $first['tablename'], 'Wrong tablename in first table.');
		$this->assertEquals(50, $first['recid'], 'Wrong uid in first table.');
		$this->assertEquals('', $first['data'], 'Wrong data in first table.');
		$this->assertEquals('', $first['resolver'], 'Wrong resolver in first table.');
	}

	/**
	 * muss nach den tests mit dem resolver laufen, da dieser sonnst immer genutzt wird
	 */
	function testAddRecordToIndexWithRegisteredResolver() {
		tx_mksearch_util_Config::registerResolver('tx_mksearch_resolver_Test', array('tx_mktest_table'));
		$srv = tx_mksearch_util_ServiceRegistry::getIntIndexService();
		$srv->addRecordToIndex('tx_mktest_table', 50);
		$res = tx_rnbase_util_DB::doSelect('*', 'tx_mksearch_queue', array('enablefieldsoff' => true));
		
		$this->assertEquals(1, count($res), 'Wrong row count in queue.');
		
		$first = &$res[0];	
		$this->assertEquals('tx_mktest_table', $first['tablename'], 'Wrong tablename in first table.');
		$this->assertEquals(50, $first['recid'], 'Wrong uid in first table.');
		$this->assertEquals('', $first['data'], 'Wrong data in first table.');
		$this->assertEquals('tx_mksearch_resolver_Test', $first['resolver'], 'Wrong resolver in first table.');
		
		// resolver wieder deaktivieren
		tx_mksearch_util_Config::registerResolver(false, array('tx_mktest_table'));
	}
	
	function testAddRecordsToIndex() {
		$srv = tx_mksearch_util_ServiceRegistry::getIntIndexService();
		$records = array(
			array('tablename' => 'tx_mktest_table', 'uid' => '50'),
			array('tablename' => 'tx_mktest_table', 'uid' => '51', 'preferer' => '1'),
			array('tablename' => 'tx_mktest_table', 'uid' => '52', 'preferer' => '1', 'resolver' => 'tx_mksearch_resolver_Test'),
			array('tablename' => 'tx_mktest_table', 'uid' => '53', 'preferer' => '1', 'resolver' => 'tx_mksearch_resolver_Test', 'data' => 'test index'),
		);
		$options = array('checkExisting' => false);
		$srv->addRecordsToIndex($records, $options);
		$res = tx_rnbase_util_DB::doSelect('*', 'tx_mksearch_queue', array('enablefieldsoff' => true));
		
		$this->assertEquals(4, count($res), 'Wrong row count in queue.');
		
		foreach($records as $key => $row) {
			$this->assertTrue(array_key_exists($key, $res), $key.': Record not found.');
			$this->assertEquals($row['tablename'], $res[$key]['tablename'], $key.': Wrong tablename.');
			$this->assertEquals($row['uid'], $res[$key]['recid'], $key.': Wrong uid.');
			$this->assertEquals($row['data'], $res[$key]['data'], $key.': Wrong data.');
			$this->assertEquals($row['resolver'], $res[$key]['resolver'], $key.': Wrong resolver.');
		}
	}
	function testAddRecordsToIndexWith10000() {
		$srv = tx_mksearch_util_ServiceRegistry::getIntIndexService();
		$records = array();
		for($i=1;$i<=10000;$i++){
			$records[] = array('tablename' => 'tx_mktest_table', 'uid' => $i);
		}
		$options = array('checkExisting' => false);
		$srv->addRecordsToIndex($records, $options);
		
		$this->assertEquals(10000, $srv->countItemsInQueue('tx_mktest_table'), 'Wrong queue count.');
	}
}
if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mksearch/tests/service/internal/class.tx_mksearch_tests_service_internal_Index_testcase.php']) {
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mksearch/tests/service/internal/class.tx_mksearch_tests_service_internal_Index_testcase.php']);
}

?>