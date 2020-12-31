<?php

namespace nyu\Runs\Shows\mods;

use \nyu\Data\DBRecord;
use \nyu\Tools\Variable;

class Single extends DBRecord {
	
	public function __construct( ){
		$chldLst = Array(
			'events'=>Array('','','date ASC, time ASC'),
			'items'
		);
		parent::__construct( 'shows', $chldLst );
	}
	
	public function loadRels( ){
		parent::loadRels( );
		$itemLst = Array();
		if(!empty( $this->recArray['Items'] )){			
			foreach( $this->recArray['Items'] as $itmRec){
				if(isset($itmRec['type'])){				
					$itemLst[] = $itmRec['type'];
				}
			}
		}
		$this->recArray['Items'] = $itemLst;
		
		$this->recArray['evntSpan'] = $this->evntSpan();
	}
	
	public function hasCateg( String $hasItemTyp ){
		if( in_array($hasItemTyp, $this->recArray['Items'] ) ){
			return true;
		}else{
			return false;
		}
	}
	
	public function evntSpan( ){
		$evntLst = $this->recArray['Events'];
		if(is_array($evntLst)){
			$frstDay = $evntLst[0]['date'];
			$lastDay = $evntLst[count($evntLst)-1]['date'];
			$spanVar = new Variable($frstDay);
			$spanStr = $spanVar->get('DateSpan',$lastDay);			
			return $spanStr;
		}else{
			return false;
		}
	}
}