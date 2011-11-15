<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2011 das Medienkombinat
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
tx_rnbase::load('tx_rnbase_configurations');
tx_rnbase::load('tx_mksearch_util_ServiceRegistry');

// mögliche operatoren für das verbinden von wörtern
define('MKSEARCH_OP_OR', 'or');
define('MKSEARCH_OP_AND', 'and');
define('MKSEARCH_OP_EXACT', 'exact');
define('MKSEARCH_OP_FREE', 'free');
define('MKSEARCH_OP_NONE', 'none');

/**
 * Methods for accessing configuration options etc.
 */
class tx_mksearch_util_SearchBuilder {

	/**
	 * Setzt jedes Wort in Anführungszeichen.
	 * Dabei werden operatoren wie + und - beachtet
	 * 
	 * @param  	mixed  	$terms
	 * @return 	array
	 */
	private static function quoteTerms($terms) {
		if(!is_array($terms))
			$terms = t3lib_div::trimExplode(' ', $terms, 1);
		
		foreach($terms as &$term) {
			// minus und plus dürfen nicht mit in die quotes
			$operator = $term{0};
			switch($operator) {
				case '-':
				case '+':
					$term = ($removeOperators ? '' : $operator).'"'.substr($term,1).'"';
					break;
				default:
					$term = '"'.$term.'"';
					break;
			} 
		}
		
		return $terms;
	}
	
	/**
	 * Setzt hinter jedes Wort eine tilde für die fuzzy search.
	 * 
	 * @param  	mixed  	$terms
	 * @return 	array
	 */
	private static function fuzzyTerms($terms, $slop='0.2') {
		if(!is_array($terms))
			$terms = t3lib_div::trimExplode(' ', $terms, 1);
		
		foreach($terms as &$term) {
			$term = $term.'~'.$slop;
		}
		
		return $terms;
	}
	
	/**
	 * Removes all solr control characters
	 * 
	 * @param  	mixed  	$terms
	 * @return 	array
	 */
	private static function sanitizeTerms($terms) {
		if(empty($terms)) return $terms;//nothing to do
		tx_rnbase::load('tx_mksearch_util_Misc');
		if(!is_array($terms))
			$terms = t3lib_div::trimExplode(' ', $terms, 1);
		
		foreach($terms as $key => &$term) {
			//remove empty strings. can happen when the term contains only control chars
			if(!$term = tx_mksearch_util_Misc::sanitizeTerm($term))
				unset($terms[$key]);
		}
		
		return $terms;
	}

	/**
	 * Wurde nach OR gesucht?
	 * Dann müssen wir beim DisMaxRequestHandler Min-should-match reduzieren.
	 * 
	 * @todo: tests schreiben
	 * 
	 * @param 	array 						$fields
	 * @param 	array 						$options
	 * @param 	tx_rnbase_IParameters 		$parameters
	 * @param 	tx_rnbase_configurations 	$configurations
	 * @param 	string 						$confId
	 */
	public static  function handleMinShouldMatch(&$fields, &$options, &$parameters, &$configurations, $confId) {
		// brauchen wir nur wen ein term angegeben wurde
		if(!empty($fields['term'])) {
			//@todo: in generiche methode auslagern, welche werte anhand combination und confid ausliest!
			$combination = $parameters->get('combination');
			if($combination) {
				// mm aus der config holen
				$mm = $configurations->get($confId.'mm.'.$combination);
				// default nutzen wir aus none, wenn gesetzt
				$mm = $mm ? $mm : $configurations->get($confId.'mm.'.MKSEARCH_OP_NONE);
				if($mm) {
					$options['mm'] = $mm;
				}
			}
//			switch($combination) {
//				case MKSEARCH_OP_NONE:
//				case MKSEARCH_OP_OR:
//					$options['mm'] = '20%';
//			}
		}
	}
	/**
	 * Bei aktiviertem DisMaxRequestHandler und fuzzy muss die query leer sein.
	 * Der term muss dann im filter query stehen ( fq=text:fuzzystring~0.2^1.0 )
	 * 
	 * @todo: tests schreiben
	 * 
	 * @param 	array 						$fields
	 * @param 	array 						$options
	 * @param 	tx_rnbase_IParameters 		$parameters
	 * @param 	tx_rnbase_configurations 	$configurations
	 * @param 	string 						$confId
	 */
	public static  function handleDismaxFuzzySearch(&$fields, &$options, &$parameters, &$configurations, $confId) {
		if(!empty($fields['term']) && is_array($params = $parameters->get('options')) && $params['fuzzy']) {
			switch($parameters->get('combination')) {
				case MKSEARCH_OP_FREE: break;
				default:
					$options['qf']  = $options['qf'] ? $options['qf'].' ' : '';
					//@TODO: das feld indem gesucht werden soll konfigurierbar machen
					// am besten generich wie handleMinShouldMatch umstellen
					$options['qf'] .= 'text:'.$fields['term'];
					$fields['term'] = '';
			}
		}
	}
	/**
	 * Setzt den Term entsprechend des combination parameters zusammen.
	 * Mögliche Werte: 'none', 'free', 'or', 'and', 'exact'.
	 * 
	 * Wurde nichts oder none übergeben wird die auswertung solr überlassen
	 * 
	 * @param 	string 	$content
	 * @param 	array 	$options
	 * @return 	string
	 */
	public static function searchSolrOptions($term='', $combination, $options = array()){
		if(empty($term)) {
			return '';
		}
		// die wörter aufsplitten
		$terms = t3lib_div::trimExplode(' ', $term, 1);
		if(empty($terms)) {
			return '';
		}
		
		$quote = intval($options['quote']);
		$dismax = intval($options['dismax']);
		$fuzzy = intval($options['fuzzy']) || !empty($options['fuzzy']);
		$bSanitize = intval($options['sanitize']);
		
		// sanitize term?
		if($bSanitize){
			switch($combination) {
				// sanitize nie bei free
				case MKSEARCH_OP_FREE: break;
				default: $terms = self::sanitizeTerms($terms); break;
			}
		}
		
		// quotes
		if($quote) {
			switch($combination) {
				// exact und free nicht quoten
				case MKSEARCH_OP_EXACT: case MKSEARCH_OP_FREE: break;
				default: $terms = self::quoteTerms($terms); break;
			}
		}
		
		// fuzzy
		if($fuzzy){
			$fuzzySlop = $options['fuzzySlop'] ? $options['fuzzySlop'] : '0.2';
			switch($combination) {
				// fuzzy nicht bei free, bei exact wird es später gesetzt!
				case MKSEARCH_OP_FREE: case MKSEARCH_OP_EXACT: break;
				default: $terms = self::fuzzyTerms($terms, $fuzzySlop); break;
			}
		}
		//ist ein Suchstring übrig geblieben?
		if(!empty($terms)){
			switch($combination) {
				case MKSEARCH_OP_AND:
					// alle mit einem plus verbinden
					// @TODO: wenn vor dem term bereits ein + steht und wir noch eines anfügen, bekommen wir eine exception von solr!
					$return = '+'.implode(' +', $terms);
					// wenn nicht dismax, klammern drum rum
					$return = $dismax ? $return : '('.$return.')'; 
					break;
				case MKSEARCH_OP_EXACT:
					// in anführungszeichen setzen, bei fuzzy auch die tilde anfügen!
					$return = '"'.implode(' ', $terms).'"'. ($fuzzy ? '~'.$fuzzySlop : '');
					// wenn nicht dismax, klammern drum rum
					$return = $dismax ? $return : '('.$return.')';
					break;
				case MKSEARCH_OP_FREE:
					$return = implode(' ', $terms);
					break;
				case MKSEARCH_OP_NONE:
				case MKSEARCH_OP_OR:
					// solr sucht default mit dem or operator!
				default:
					$return = implode(' ', $terms);
					// wenn nicht dismax, klammern drum rum
					$return = $dismax ? $return : '('.$return.')';
					break;
			}
		}else{
			$return = null;
		}
		
		return $return;
	}

}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mksearch/util/class.tx_mksearch_util_SearchBuilder.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mksearch/util/class.tx_mksearch_util_SearchBuilder.php']);
}