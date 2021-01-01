<?php

	// Base Dir Setup
	define('DS',DIRECTORY_SEPARATOR);
	define('TOP_DIR',__DIR__);
	define('WEB_DIR',TOP_DIR.DS.'www');
	define('RUN_DIR',TOP_DIR.DS.'runs');

	// Simple autoloader function 
    function autoloader($clsPath){
		$nyuIndx = strrpos($clsPath,'nyu');
		if($nyuIndx !== false){
			$clsPath = subStr($clsPath, $nyuIndx);
			$clsPath = str_replace('nyu\\', 'src\\', $clsPath);
			if(strrpos($clsPath,'src\\Runs')!==false){
				$clsPath = str_replace('src\\Runs', 'runs', $clsPath);			}
		}
        //Split the class name up if it contains backslashes.
        $clsPart = explode('\\', $clsPath);
        //The last piece of the array will be our class name.
        $clsName = array_pop($clsPart);
        //Include the class.
		include TOP_DIR.DS.implode($clsPart, DS) .DS. $clsName . '.php';
    }
    
    // Tell PHP what our autoloading function is.
    spl_autoload_Registry('autoloader');