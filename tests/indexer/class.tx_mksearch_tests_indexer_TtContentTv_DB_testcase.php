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
tx_rnbase::load('tx_mksearch_indexer_TtContent');
tx_rnbase::load('tx_mksearch_model_IndexerDocumentBase');
tx_rnbase::load('tx_mksearch_tests_Util');


require_once(t3lib_extMgm::extPath('mksearch') . 'lib/Apache/Solr/Document.php');

/**
 * Wir müssen in diesem Fall mit der DB testen da wir die pages
 * Tabelle benötigen. in diesen tests haben die elemente mehrere 
 * referenzen. die grundlegenden Funktionalitäten werden in 
 * tx_mksearch_tests_indexer_TtContent_testcase und 
 * tx_mksearch_tests_indexer_TtContent_DB_testcase geprüft
 * @author Hannes Bochmann
 */
class tx_mksearch_tests_indexer_TtContentTv_DB_testcase extends tx_phpunit_database_testcase {
	protected $workspaceIdAtStart;
	protected $db;

	/**
	 * Klassenkonstruktor
	 *
	 * @param string $name
	 */
	public function __construct ($name=null) {
		global $TYPO3_DB, $BE_USER;

		parent::__construct ($name);
		$TYPO3_DB->debugOutput = TRUE;

		$this->workspaceIdAtStart = $BE_USER->workspace;
		$BE_USER->setWorkspace(0);
	}

	/**
	 * setUp() = init DB etc.
	 */
	public function setUp() {
		$this->createDatabase();
		// assuming that test-database can be created otherwise PHPUnit will skip the test
		$this->db = $this->useTestDatabase();
		
		//das devlog stört nur bei der Testausführung im BE und ist da auch
		//vollkommen unnötig
		$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['devlog']['nolog'] = true;
		
		$this->importStdDB();
		$aExtensions = array('cms','mksearch','templavoila');
		//templavoila und realurl brauchen wir da es im BE sonst Warnungen hagelt
		//und man die Testergebnisse nicht sieht
		if(t3lib_extMgm::isLoaded('realurl')) $aExtensions[] = 'realurl';
		$this->importExtensions($aExtensions);
		
		$this->importDataSet(tx_mksearch_tests_Util::getFixturePath('db/pages_tv.xml'));
		$this->importDataSet(tx_mksearch_tests_Util::getFixturePath('db/tt_content_tv.xml'));
		$this->importDataSet(tx_mksearch_tests_Util::getFixturePath('db/sys_refindex.xml'));
	}

	/**
	 * tearDown() = destroy DB etc.
	 */
	public function tearDown () {
		$this->cleanDatabase();
		$this->dropDatabase();
		$GLOBALS['TYPO3_DB']->sql_select_db(TYPO3_db);

		$GLOBALS['BE_USER']->setWorkspace($this->workspaceIdAtStart);
	}
	
	public function testPrepareSearchSetsCorrectPidOfReference() {
		$options = $this->getDefaultConfig();
		
		//should not be deleted as everything is correct with the page it is on
		$indexer = new tx_mksearch_indexer_TtContent();
		list($extKey, $cType) = $indexer->getContentType();
		$indexDoc = new tx_mksearch_model_IndexerDocumentBase($extKey, $cType);
		//even though the page of one reference is excluded we have still one valid one
		$options['exclude.']['pageTrees.'] = array(3);
		//the element it self resides on a hidden page but we have references that are okay
		$record = array('uid'=> 1, 'pid' => 2, 'CType'=>'list', 'bodytext' => 'Test 1');
		$indexer->prepareSearchData('tt_content', $record, $indexDoc, $options);
		$this->assertFalse($indexDoc->getDeleted(), 'Wrong deleted state for uid '.$record['uid']);
		$aData = $indexDoc->getData();
		$this->assertEquals(1,$aData['pid']->getValue(),'the new pid has not been set for '.$record['uid']);
		
		//should not be deleted as the element it is referenced on is on a valid page
		$indexer = new tx_mksearch_indexer_TtContent();
		list($extKey, $cType) = $indexer->getContentType();
		$indexDoc = new tx_mksearch_model_IndexerDocumentBase($extKey, $cType);
		$record = array('uid'=> 5, 'pid' => 0, 'CType'=>'list', 'bodytext' => 'Test 1');
		$indexer->prepareSearchData('tt_content', $record, $indexDoc, $options);
		$this->assertFalse($indexDoc->getDeleted(), 'Wrong deleted state for uid '.$record['uid']);
		$aData = $indexDoc->getData();
		$this->assertEquals(1,$aData['pid']->getValue(),'the new pid has not been set for '.$record['uid']);
	}
	
	public function testPrepareSearchCheckDeleted() {
		$options = $this->getDefaultConfig();
		
		//should be deleted as there is no reference
		$indexer = new tx_mksearch_indexer_TtContent();
		list($extKey, $cType) = $indexer->getContentType();
		$indexDoc = new tx_mksearch_model_IndexerDocumentBase($extKey, $cType);
		$record = array('uid'=> 99, 'pid' => 0, 'CType'=>'list', 'bodytext' => 'Test 1');
		$indexer->prepareSearchData('tt_content', $record, $indexDoc, $options);
		$this->assertTrue($indexDoc->getDeleted(), 'Wrong deleted state for uid '.$record['uid']);
		
		//should be deleted as the page it is referenced on is hidden
		$options = $this->getDefaultConfig();
		$indexer = new tx_mksearch_indexer_TtContent();
		list($extKey, $cType) = $indexer->getContentType();
		$indexDoc = new tx_mksearch_model_IndexerDocumentBase($extKey, $cType);
		//the pid doesn't matter as it's taken from the reference to this element
		$record = array('uid'=> 2, 'pid' => 0, 'CType'=>'list', 'bodytext' => 'Test 1');
		$indexer->prepareSearchData('tt_content', $record, $indexDoc, $options);
		$this->assertTrue($indexDoc->getDeleted(), 'Wrong deleted state for uid '.$record['uid']);
		
		$options = $this->getDefaultConfig();
		//should be deleted as the element it is referenced on is on a invalid page
		$indexer = new tx_mksearch_indexer_TtContent();
		list($extKey, $cType) = $indexer->getContentType();
		$indexDoc = new tx_mksearch_model_IndexerDocumentBase($extKey, $cType);
		$record = array('uid'=> 4, 'pid' => 0, 'CType'=>'list', 'bodytext' => 'Test 1');
		$indexer->prepareSearchData('tt_content', $record, $indexDoc, $options);
		$this->assertTrue($indexDoc->getDeleted(), 'Wrong deleted state for uid '.$record['uid']);
	}
	
	public function testPrepareSearchSetsCorrectIsIndexable() {
		$options = $this->getDefaultConfig();
		
		//should return null as the page the element is referenced on is excluded for indexing
		$indexer = new tx_mksearch_indexer_TtContent();
		list($extKey, $cType) = $indexer->getContentType();
		$indexDoc = new tx_mksearch_model_IndexerDocumentBase($extKey, $cType);
		$record = array('uid'=> 3, 'pid' => 0, 'CType'=>'list', 'bodytext' => 'Test 1');
		$options['exclude.']['pageTrees.'] = array(4);//als array
		$indexDoc = $indexer->prepareSearchData('tt_content', $record, $indexDoc, $options);
		$this->assertNull($indexDoc, 'Index Doc not null for uid '.$record['uid']);
		
		//should return null as the page the element is referenced on is excluded for indexing
		$indexer = new tx_mksearch_indexer_TtContent();
		list($extKey, $cType) = $indexer->getContentType();
		$indexDoc = new tx_mksearch_model_IndexerDocumentBase($extKey, $cType);
		$record = array('uid'=> 6, 'pid' => 0, 'CType'=>'list', 'bodytext' => 'Test 1');
		$options['exclude.']['pageTrees.'] = array(4);//als array
		$indexDoc = $indexer->prepareSearchData('tt_content', $record, $indexDoc, $options);
		$this->assertNull($indexDoc, 'Wrong deleted state for uid '.$record['uid']);
	}
	
	/**
	 * @return array
	 */
	protected function getDefaultConfig() {
		$options = array();
		$options['includeCTypes.'] = array('list');
		$options['CType.']['_default_.']['indexedFields.'] = array('bodytext', 'imagecaption' , 'altText', 'titleText');
		return $options;
	}
}
if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mksearch/tests/indexer/class.tx_mksearch_tests_indexer_TtContent_testcase.php']) {
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mksearch/tests/indexer/class.tx_mksearch_tests_indexer_TtContent_testcase.php']);
}

?>