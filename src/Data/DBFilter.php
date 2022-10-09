<?php

namespace nyu\Data;

use nyu\Data\DBHndlr\Handler;

// Reads a filter array, and writes it as the WHERE part of a query. The
// reading is the same for every kind of database; each store then writes
// the conditions in its own language.
class DBFilter {

	/**
	 * Reads a filter array into a list of conditions. A field name may end
	 * with '!' (negation), '<=', '>=', '<' or '>'. An array value asks for
	 * one of several values, and a string holding a wildcard ('*' or '%')
	 * for a match. A field name ending with '=' asks for the exact value:
	 * its wildcard characters are then plain text.
	 *
	 * Whether a field holds a value is asked by name, with the '?' suffix:
	 * 'field?'=>false for the rows where it is not set, 'field?'=>true for
	 * those where it is. A null value never does: it matches nothing, so
	 * that an empty variable or request value cannot select the rows left
	 * unset.
	 *
	 * Each condition holds its 'field', its 'opert' and its 'value'. The
	 * operations are: equal, diffr, under, undeq, above, aboeq (compared
	 * with the value), match and nmtch (the value is a pattern, with % for
	 * any text), among and nmong (the value is a list), unset and isset
	 * (no value), never and every (no field: always false, always true).
	 *
	 * @param Array $whereArray Map of field name => value (or array of values) to filter by.
	 * @return Array The conditions, all of which a row must meet.
	 *
	 * @throws \RuntimeException When a field name is not valid, or a '?' entry is not true or false.
	 */
	public static function readWhere( Array $whereArray ): Array {
		$oprtLst = Array('<='=>'undeq', '>='=>'aboeq', '!'=>'diffr', '<'=>'under', '>'=>'above', '?'=>'isset', '='=>'equal');
		$cndList = Array();
		foreach($whereArray as $fldNme=>$fldVal){
			$fldNme = strval($fldNme);
			$operatr = 'equal';
			$exactly = false;
			foreach($oprtLst as $sfx=>$oprCod){
				if( substr($fldNme, -strlen($sfx))==$sfx ){
					$operatr = $oprCod;
					// A written '=' asks for the exact value, wildcard characters included
					$exactly = ($sfx=='=');
					$fldNme = substr($fldNme, 0, -strlen($sfx));
					break;
				}
			}
			if( !preg_match('/^\w+(\.\w+)?$/D', $fldNme) ){
				throw new \RuntimeException('Invalid table or field name: '.$fldNme);
			}
			$negated = ($operatr=='diffr');
			if( $operatr=='isset' ){
				// Asked for by name: whether the field holds a value
				if( !is_bool($fldVal) ){
					throw new \RuntimeException('The ? suffix takes true or false: '.$fldNme);
				}
				$cndList[] = Array('field'=>$fldNme, 'opert'=>($fldVal ? 'isset' : 'unset'), 'value'=>null);
			}elseif( is_array($fldVal) ){
				$lstVals = array_values(array_filter($fldVal, function($fieldValue){ return $fieldValue!==null && $fieldValue!==''; }));
				if( empty($lstVals) ){
					// An empty list matches nothing, its negation everything
					$cndList[] = Array('field'=>'', 'opert'=>($negated ? 'every' : 'never'), 'value'=>null);
				}else{
					$cndList[] = Array('field'=>$fldNme, 'opert'=>($negated ? 'nmong' : 'among'), 'value'=>$lstVals);
				}
			}elseif( $fldVal===null ){
				// A null value matches nothing, like a comparison with NULL in SQL
				$cndList[] = Array('field'=>'', 'opert'=>'never', 'value'=>null);
			}else{
				if( is_string($fldVal) && !$exactly && ($operatr=='equal' || $negated) && strpbrk($fldVal, '*%')!==false ){
					$fldVal = str_replace('*', '%', $fldVal);
					$operatr = $negated ? 'nmtch' : 'match';
				}
				$cndList[] = Array('field'=>$fldNme, 'opert'=>$operatr, 'value'=>$fldVal);
			}
		}
		return $cndList;
	}

	/**
	 * Builds the WHERE part of an SQL query from a filter array, read by
	 * readWhere(). Names are quoted, and matches written, the way the
	 * database type of the handler expects them.
	 *
	 * @param Array   $whereArray Map of field name => value (or array of values) to filter by.
	 * @param Handler $handlerObj Database handler, used to quote the field names.
	 * @return Array The clause under 'text', with ? placeholders, and its values under 'vals'.
	 *
	 * @throws \RuntimeException When a field name is not valid, or a '?' entry is not true or false.
	 */
	public static function makeWhere( Array $whereArray, Handler $handlerObj ): Array {
		$signLst = Array('equal'=>'=', 'diffr'=>'!=', 'under'=>'<', 'undeq'=>'<=', 'above'=>'>', 'aboeq'=>'>=');
		$whrText = Array();
		$whrVals = Array();
		foreach(self::readWhere($whereArray) as $cndArr){
			$oprCode = $cndArr['opert'];
			$fldQutd = ($cndArr['field']!='') ? $handlerObj->quoteName($cndArr['field']) : '';
			if( $oprCode=='never' || $oprCode=='every' ){
				$whrText[] = ($oprCode=='every') ? '1=1' : '1=0';
			}elseif( $oprCode=='unset' || $oprCode=='isset' ){
				$whrText[] = $fldQutd.($oprCode=='isset' ? ' IS NOT NULL' : ' IS NULL');
			}elseif( $oprCode=='among' || $oprCode=='nmong' ){
				$whrText[] = $fldQutd.($oprCode=='nmong' ? ' NOT IN (' : ' IN (').implode(',', array_fill(0, count($cndArr['value']), '?')).')';
				$whrVals = array_merge($whrVals, $cndArr['value']);
			}elseif( $oprCode=='match' || $oprCode=='nmtch' ){
				$whrText[] = $fldQutd.' '.($oprCode=='nmtch' ? 'NOT ' : '').$handlerObj->likeOprtr().' ?';
				$whrVals[] = $cndArr['value'];
			}else{
				$whrText[] = $fldQutd.' '.$signLst[$oprCode].' ?';
				$whrVals[] = $cndArr['value'];
			}
		}
		return Array('text'=>implode(' AND ', $whrText), 'vals'=>$whrVals);
	}
}
