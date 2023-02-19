<?php


//include '../vendor/autoload.php';
// use Dice\Dice;

// $diceLoad = new Dice();

$httpMeth = $_SERVER['REQUEST_METHOD'];

foreach (apache_request_headers() as $name => $value) {
	$reqHeads[$name] =  $value ;
}

$inputFlx = getStreamIn();

if(empty($inputArr) && $httpMeth=='GET'){
	$inComing = $_GET;
}else{
	$inComing = $inputFlx;
}

//print_r($_POST); //empty!!! don't work ...
$inputArr = $inComing;

$response = Array(
	'method'=>$httpMeth,
	'inputs'=>$inputArr
);

die(json_encode($response));


//function servMethod
function getRawData(){
	// Fetch content and determine boundary
	$raw_data = file_get_contents('php://input');
	$raw_data = str_replace("\r",'',$raw_data);
	$boundPos = strpos($raw_data, "\n");
	if($boundPos!==false){
		$boundary = substr($raw_data, 0, $boundPos );
	}else{
		// No boundaries found
		return null;
	}

	// Fetch each part
	$flxParts = array_slice( explode($boundary, $raw_data), 1 );
	$prtHeads = array();
	$saveData = array();
	$saveFile = array();

	foreach ($flxParts as $partLn) {

		$saveFile[] = $partLn;
		// If this is the last part, break
		if ($partLn == "--\n") break;

		// Separate content from headers
		$partsArr = explode("\n\n",$partLn,2);
		$partHead = str_replace("\n",';',$partsArr[0]);
		$headrArr = explode(';',$partHead);
		$varNames = array();

		foreach($headrArr as $headLn){
			if(trim($headLn)==''){
				continue;
			}
			$headVals = explode(':',$headLn);
			if(count($headVals)>1){
				$headers[trim($headVals[0])] = trim($headVals[1]);
			}else{
				$headLine = str_replace('"','',$headLn);
				$nameVals = explode('=',$headLine);
				if(count($nameVals)>1){
					if(trim($nameVals[1])!=''){
						$varNames[trim($nameVals[0])] = trim($nameVals[1]);
					}
				}
			}
		}
		// Get part content and store in named array
		if(isset($varNames['filename'])){
			$fileData[trim($varNames['filename'])] = trim($partsArr[1]);
		}else{
			$saveData[trim($varNames['name'])] = trim($partsArr[1]);
		}
	}
	$saveFlux = array(
		'head'=>$headers,
		'vars'=>$saveData,
//		'file'=>$fileData,
//		'data'=>$raw_data
	);

	return $saveFlux;
}

/*
// read, write, seek
include_once('../autoloader.php');

//$preset = new nyu\Setup\Preset();
// TODO : DIContainer

use \nyu\Data\DBRec;

$GLOBALS['manualRoute'] = Array(
	'dev' => Array(
		'command' => Array(
			'app' => "client",
			'act' => "edit"
			)
		)
	);

$nyuRun = new nyu\Runner('manual');

$nyuRec = new DBRec( 'shows' );
$nyuArr = $nyuRec->loadRecrd(100);
$nyuRec->bindArray( Array('title'=>'LES PIEDS DANS LES NUAGES') );
// $nyuRec->bindArray( Array('title'=>'LES PIEDS DANS - LES - NUAGES') );
var_dump($nyuRec->saveRecrd());
$nyuArr = $nyuRec->loadRecrd(100);
print_R($nyuArr);
*/
