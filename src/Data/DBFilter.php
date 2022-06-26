<?php

namespace nyu\Data;

use nyu\Data\DBHs\Handler;

// Turns a filter array into the WHERE part of a query, for DBList and DBRecord
class DBFilter {

	/**
	 * Builds the WHERE part of a query from a filter array. A field name
	 * may end with '!' (negation), '<=', '>=', '<' or '>'. An array value
	 * gives IN/NOT IN, and a string holding a wildcard ('*' or '%')
	 * LIKE/NOT LIKE.
	 *
	 * Whether a field holds a value is asked by name, with the '?' suffix:
	 * 'field?'=>false gives IS NULL, 'field?'=>true gives IS NOT NULL. A
	 * null value never does: it matches nothing, so that an empty variable
	 * or request value cannot select the rows left unset.
	 *
	 * @param Array   $whereArray Map of field name => value (or array of values) to filter by.
	 * @param Handler $handlerObj Database handler, used to quote the field names.
	 * @return Array The clause under 'text', with ? placeholders, and its values under 'vals'.
	 *
	 * @throws \RuntimeException When a field name is not valid, or a '?' entry is not true or false.
	 */
	public static function makeWhere( Array $whereArray, Handler $handlerObj ): Array {
		$whrText = Array();
		$whrVals = Array();
		foreach($whereArray as $fldNme=>$fldVal){
			$operatr = '=';
			foreach(Array('<=', '>=', '!', '<', '>', '?') as $sfx){
				if( substr($fldNme, -strlen($sfx))==$sfx ){
					$operatr = ($sfx=='!') ? '!=' : $sfx;
					$fldNme = substr($fldNme, 0, -strlen($sfx));
					break;
				}
			}
			$fldNme = $handlerObj->quoteName($fldNme);
			$negated = ($operatr=='!=');
			if( $operatr=='?' ){
				// Asked for by name: whether the field holds a value
				if( !is_bool($fldVal) ){
					throw new \RuntimeException('The ? suffix takes true or false: '.$fldNme);
				}
				$whrText[] = $fldNme.($fldVal ? ' IS NOT NULL' : ' IS NULL');
			}elseif( is_array($fldVal) ){
				$lstVals = array_values(array_filter($fldVal, function($fieldValue){ return $fieldValue!==null && $fieldValue!==''; }));
				if( empty($lstVals) ){
					// An empty list matches nothing, its negation everything
					$whrText[] = $negated ? '1=1' : '1=0';
				}else{
					$whrText[] = $fldNme.($negated ? ' NOT IN (' : ' IN (').implode(',', array_fill(0, count($lstVals), '?')).')';
					$whrVals = array_merge($whrVals, $lstVals);
				}
			}elseif( $fldVal===null ){
				// A null value matches nothing, like a comparison with NULL in SQL
				$whrText[] = '1=0';
			}else{
				if( is_string($fldVal) && ($operatr=='=' || $negated) && strpbrk($fldVal, '*%')!==false ){
					$fldVal = str_replace('*', '%', $fldVal);
					$operatr = ($negated ? 'NOT ' : '').$handlerObj->likeOprtr();
				}
				$whrText[] = $fldNme.' '.$operatr.' ?';
				$whrVals[] = $fldVal;
			}
		}
		return Array('text'=>implode(' AND ', $whrText), 'vals'=>$whrVals);
	}
}
