<?php

namespace nyu\Data\DBStore;

/**
 * Reads a filter array into plain conditions. The reading is the same for
 * every kind of database; each store then writes the conditions in its own
 * language: the SQL handlers through their makeWhereArray(), a document store
 * with its own query.
 */
class Filter {

	/**
	 * Reads a filter array into a list of conditions. A field name may end
	 * with '!' (negation), '<=', '>=', '<' or '>'. An array value asks for
	 * one of several values. A value is compared as it is: none of its
	 * characters is a wildcard, so that a value taken from a request
	 * cannot widen a search. A match is asked for by the name of the
	 * field, ending with '~' (or '!~' for what does not match): '*' then
	 * stands for any text, and is the only wildcard; '\*' is a star and '\\'
	 * a backslash. A value taken from a request goes into a match through
	 * quoteMatchText(), which keeps it from being read as a pattern. A field name ending
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
	 * with the value), match and nmtch (the value is the list of the literal
	 * pieces of the text, any text standing between two of them; each store
	 * writes it its own way), among and nmong (the value is a list), unset and isset
	 * (no value), never and every (no field: always false, always true).
	 *
	 * @param Array $filterFields Map of field name => value (or array of values) to filter by.
	 * @return Array The conditions, all of which a row must meet.
	 *
	 * @throws \RuntimeException When a field name is not valid, or a '?' entry is not true or false.
	 */
	public static function readWhereArray( Array $filterFields ): Array {
		// The longer suffixes are looked for first
		$opCodes = Array('!~'=>'nmtch', '<='=>'undeq', '>='=>'aboeq', '~'=>'match', '!'=>'diffr', '<'=>'under', '>'=>'above', '?'=>'isset', '='=>'equal');
		$clauses = Array();
		foreach($filterFields as $fldNme=>$fldVal){
			$fldNme = strval($fldNme);
			$operatr = 'equal';
			foreach($opCodes as $sfxInc=>$opCode){
				if( substr($fldNme, -strlen($sfxInc))==$sfxInc ){
					$operatr = $opCode;
					$fldNme = substr($fldNme, 0, -strlen($sfxInc));
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
				$clauses[] = Array('field'=>$fldNme, 'opert'=>($fldVal ? 'isset' : 'unset'), 'value'=>null);
			}elseif( is_array($fldVal) ){
				$listVls = array_values(array_filter($fldVal, function($fieldContent){ return $fieldContent!==null && $fieldContent!==''; }));
				if( empty($listVls) ){
					// An empty list matches nothing, its negation everything
					$clauses[] = Array('field'=>'', 'opert'=>($negated ? 'every' : 'never'), 'value'=>null);
				}else{
					$clauses[] = Array('field'=>$fldNme, 'opert'=>($negated ? 'nmong' : 'among'), 'value'=>$listVls);
				}
			}elseif( $fldVal===null ){
				// A null value matches nothing, like a comparison with NULL in SQL
				$clauses[] = Array('field'=>'', 'opert'=>'never', 'value'=>null);
			}else{
				// A match is read here, once : a * alone is any text, \* a star and \\ a backslash, nothing else a wildcard.
				// The stores get the literal pieces, to join with their own wildcard (see quoteMatchText)
				if( $operatr=='match' || $operatr=='nmtch' ){
					$partLst = Array('');
					foreach( preg_split('/(\\\\[\\\\*]|\*)/', is_scalar($fldVal) ? strval($fldVal) : '', -1, PREG_SPLIT_DELIM_CAPTURE) as $oneTkn ){
						if( $oneTkn==='*' ){
							$partLst[] = '';
							continue;
						}
						$litPart = ($oneTkn==='\\*' || $oneTkn==='\\\\') ? substr($oneTkn, 1) : $oneTkn;
						$partLst[count($partLst)-1] .= $litPart;
					}
					$fldVal = $partLst;
				}
				$clauses[] = Array('field'=>$fldNme, 'opert'=>$operatr, 'value'=>$fldVal);
			}
		}
		return $clauses;
	}

	/**
	 * Escapes a text so that, in a match, it matches only itself: its stars
	 * and backslashes are no longer read as a pattern. A search taken from
	 * a request is written '*'.Filter::quoteMatchText($search).'*'.
	 *
	 * @param String $searchedText The text, as sent.
	 * @return String The text, its * and \ escaped.
	 */
	public static function quoteMatchText( String $searchedText ): String {
		return strtr( $searchedText, Array('\\'=>'\\\\', '*'=>'\\*') );
	}
}
