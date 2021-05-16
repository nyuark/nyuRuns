<?php

// Runs the self-checking test scripts and prints a summary.
//
//   php test/run.php              runs everything
//   php test/run.php unit data    runs only the named groups
//   php test/run.php Variable     runs only the scripts whose name contains the word
//
// Each *Test.php script runs in its own PHP process and exits non-zero
// when one of its checks fails; so does this runner.

$tstRoot = __DIR__;
$grpList = Array('unit', 'data', 'http', 'audit');
$filters = array_slice($argv, 1);

$totPass = 0;
$totFail = 0;
$totSkip = 0;
$badList = Array();

foreach( $grpList as $grpNme ){
	$tstList = glob($tstRoot.DIRECTORY_SEPARATOR.$grpNme.DIRECTORY_SEPARATOR.'*Test.php');
	sort($tstList);
	foreach( $tstList as $tstFle ){
		$tstName = $grpNme.'/'.basename($tstFle, '.php');
		if( !empty($filters) ){
			$isAsked = false;
			foreach( $filters as $fltWrd ){
				if( $fltWrd==$grpNme || stripos($tstName, $fltWrd)!==false ){
					$isAsked = true;
				}
			}
			if( !$isAsked ){
				continue;
			}
		}

		echo $tstName."\n";
		$pipeArr = Array();
		$process = proc_open( Array(PHP_BINARY, $tstFle), Array(0=>Array('pipe','r'), 1=>Array('pipe','w'), 2=>Array('redirect',1)), $pipeArr, dirname($tstRoot) );
		fclose($pipeArr[0]);
		$outText = stream_get_contents($pipeArr[1]);
		fclose($pipeArr[1]);
		$exitNum = proc_close($process);
		echo $outText;

		if( preg_match('/(\d+) passed, (\d+) failed(?:, (\d+) skipped)?\s*$/', $outText, $sumVals) ){
			$totPass += intval($sumVals[1]);
			$totFail += intval($sumVals[2]);
			$totSkip += intval($sumVals[3] ?? 0);
			if( $exitNum!=0 || intval($sumVals[2])>0 ){
				$badList[] = $tstName;
			}
		}else{
			// The script died before printing its summary
			echo '  FAIL  the script ended without a summary (exit code '.$exitNum.')'."\n";
			$totFail++;
			$badList[] = $tstName;
		}
	}
}

echo "\n".$totPass.' checks passed, '.$totFail.' failed'.($totSkip>0 ? ', '.$totSkip.' skipped' : '')."\n";
if( !empty($badList) ){
	echo 'Failing scripts: '.implode(', ', $badList)."\n";
	exit(1);
}
exit(0);
