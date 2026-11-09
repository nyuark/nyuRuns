<?php

namespace nyu\Runs\Shows\mods;

use \nyu\Data\DBRec;
use \nyu\Tools\Variable;

class Single extends DBRec {
	
	/**
	 * Binds the record to the 'shows' table and declares its child
	 * relations: 'events' (sorted by date and time) and 'items'.
	 */
	public function __construct( ){
		$chldLst = Array(
			'events'=>Array('','','date ASC, time ASC'),
			'items'
		);
		parent::__construct( 'shows', $chldLst );
	}
	
	/**
	 * Loads the declared relations, then flattens 'Items' to a list
	 * of item types and adds the 'evntSpan' date-span entry to the
	 * record.
	 */
	public function importRelation( ): Void {
		parent::importRelation( );
		$itemLst = Array();
		if(!empty( $this->recrdArray['Items'] )){			
			foreach( $this->recrdArray['Items'] as $itmRec){
				if(isset($itmRec['type'])){				
					$itemLst[] = $itmRec['type'];
				}
			}
		}
		$this->recrdArray['Items'] = $itemLst;
		
		$this->recrdArray['evntSpan'] = $this->eventSpnString();
	}
	
	/**
	 * Checks whether the show has an item of the given type.
	 *
	 * @param String $hasEntryType Item type to look for.
	 * @return Bool True if one of the show's items has that type.
	 */
	public function verifyCategory( String $hasEntryType ): Bool {
		if( in_array($hasEntryType, $this->recrdArray['Items'] ) ){
			return true;
		}else{
			return false;
		}
	}
	
	/**
	 * Formats the span between the show's first and last event
	 * dates.
	 *
	 * @return mixed The formatted date span, or false when the show has no events list.
	 */
	public function eventSpnString( ): String|Bool {
		$evntLst = $this->recrdArray['Events'] ?? null;
		if(is_array($evntLst) && !empty($evntLst)){
			$frstDay = $evntLst[0]['date'];
			$lastDay = $evntLst[count($evntLst)-1]['date'];
			$spnVrbl = new Variable($frstDay);
			$spanTxt = $spnVrbl->readFileDetail('DateSpn',$lastDay);			
			return $spanTxt;
		}else{
			return false;
		}
	}
}