<?php

namespace nyu\Data\DBStore;

// Reads a filter array into plain conditions. The reading is the same for
// every kind of database; each store then writes the conditions in its own
// language: the SQL handlers through their makeWhere(), a document store
// with its own query.
class Filter {

	/**
	 * Reads a filter array into a list of conditions. A field name may end
	 * with '!' (negation), '<=', '>=', '<' or '>'. An array value asks for
	 * one of several values. A value is compared as it is: none of its
	 * characters is a wildcard, so that a value taken from a request
	 * cannot widen a search. A match is asked for by the name of the
	 * field, ending with '~' (or '!~' for what does not match): '*' then
	 * stands for any text, and is the only wildcard. A field name ending
	 * with '=' asks for the exact value, as no suffix does.
	 *
	 * Whether a field holds a value is asked by name, with the '?' suffix:
	 * 'field?'=>false for the rows where it is not set, 'field?'=>true for
	 * those where it is. A null value never does: it matches nothing, so
	 * that an empty variable or request value cannot select the rows left
	 * unset.
	 *
	 * Each condition holds its 'field', its 'opert' and its 'value'. The
	 * operations are: equal, diffr, under, undeq, above, aboeq (compared
	 * with the value), match and nmtch (the value is a text in which * is
	 * any text; each store writes it its own way), among and nmong (the value is a list), unset and isset
	 * (no value), never and every (no field: always false, always true).
	 *
	 * @param Array $whereArray Map of field name => value (or array of values) to filter by.
	 * @return Array The conditions, all of which a row must meet.
	 *
	 * @throws \RuntimeException When a field name is not valid, or a '?' entry is not true or false.
	 */
	public static function readWhere( Array $whereArray ): Array {
		// The longer suffixes are looked for first
		$oprtLst = Array('!~'=>'nmtch', '<='=>'undeq', '>='=>'aboeq', '~'=>'match', '!'=>'diffr', '<'=>'under', '>'=>'above', '?'=>'isset', '='=>'equal');
		$cndList = Array();
		foreach($whereArray as $fldNme=>$fldVal){
			$fldNme = strval($fldNme);
			$operatr = 'equal';
			foreach($oprtLst as $sfx=>$oprCod){
				if( substr($fldNme, -strlen($sfx))==$sfx ){
					$operatr = $oprCod;
					$fldNme = substr($fldNme, 0, -strlen($sfx));
					break;
				}
			}
			if( !preg_match('/^\w+(\.\w+)?$/D', $fldNme) ){
				throw new \RuntimeException('Invalid table or field name: '.$fldNme);
			}
			$negated = ($operatr=='diffr' || $operatr=='nmtch');
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
				// A match is made on a text : its * is any text, and nothing else in it is a wildcard
				if( $operatr=='match' || $operatr=='nmtch' ){
					$fldVal = is_scalar($fldVal) ? strval($fldVal) : '';
				}
				$cndList[] = Array('field'=>$fldNme, 'opert'=>$operatr, 'value'=>$fldVal);
			}
		}
		return $cndList;
	}
}
