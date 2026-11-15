<?php

// Data\QrCode : each code made is read back by a small decoder written
// here apart from the encoder: the format and the version are compared
// with the values the standard lists, the error correction is checked by
// its syndromes, and the text is read back from the modules.

require __DIR__.'/../lib/harness.php';

use nyu\Data\QrCode;
use nyu\Data\QrCodeError;

// The 15 bits of the format, by level and mask, as the standard lists them (most significant bit first)
const FMTTABLE = Array(
	'L'=>Array('111011111000100', '111001011110011', '111110110101010', '111100010011101', '110011000101111', '110001100011000', '110110001000001', '110100101110110'),
	'M'=>Array('101010000010010', '101000100100101', '101111001111100', '101101101001011', '100010111111001', '100000011001110', '100111110010111', '100101010100000'),
	'Q'=>Array('011010101011111', '011000001101000', '011111100110001', '011101000000110', '010010010110100', '010000110000011', '010111011011010', '010101111101101'),
	'H'=>Array('001011010001001', '001001110111110', '001110011100111', '001100111010000', '000011101100010', '000001001010101', '000110100001100', '000100000111011')
);
// The 18 bits of the version, from version 7 on (most significant bit first)
const VERTABLE = Array(7=>'000111110010010100', 8=>'001000010110111100', 9=>'001001101010011001', 10=>'001010010011010011');
// Alignment centres and blocks (ec per block, blocks and data of group 1, then of group 2), as the standard lists them
const ALGTABLE = Array(1=>Array(), 2=>Array(6, 18), 3=>Array(6, 22), 4=>Array(6, 26), 5=>Array(6, 30), 6=>Array(6, 34), 7=>Array(6, 22, 38), 8=>Array(6, 24, 42), 9=>Array(6, 26, 46), 10=>Array(6, 28, 50));

/**
 * Multiplies in GF(2^8), by logarithms this time.
 */
function gfTimes( Int $leftValue, Int $rightValue ): Int {
	static $expList = null, $logList = null;
	if( $expList===null ){
		$expList = Array();
		$logList = Array();
		$valNum = 1;
		for( $pow=0; $pow<255; $pow++ ){
			$expList[$pow] = $valNum;
			$logList[$valNum] = $pow;
			$valNum <<= 1;
			if( $valNum & 0x100 ){
				$valNum ^= 0x11D;
			}
		}
	}
	if( $leftValue==0 || $rightValue==0 ){
		return 0;
	}
	return $expList[ ($logList[$leftValue] + $logList[$rightValue]) % 255 ];
}

/**
 * Tells which modules of a code of the given side are not data: finders and
 * separators, timing, alignment, format and version.
 */
function fixedSet( Int $sideNumber ): Array {
	$verNmb = intdiv($sideNumber-17, 4);
	$fixMap = array_fill(0, $sideNumber, array_fill(0, $sideNumber, false));
	for( $row=0; $row<$sideNumber; $row++ ){
		for( $col=0; $col<$sideNumber; $col++ ){
			$inFndr = ($row<9 && $col<9) || ($row<9 && $col>=$sideNumber-8) || ($row>=$sideNumber-8 && $col<9);
			$inVers = $verNmb>=7 && (($row<6 && $col>=$sideNumber-11 && $col<$sideNumber-8) || ($col<6 && $row>=$sideNumber-11 && $row<$sideNumber-8));
			$fixMap[$row][$col] = $inFndr || $inVers || $row==6 || $col==6;
		}
	}
	$algLst = ALGTABLE[$verNmb];
	foreach( $algLst as $rowCtr ){
		foreach( $algLst as $colCtr ){
			if( $fixMap[$rowCtr][$colCtr] && !($rowCtr==6 || $colCtr==6) ){
				continue;
			}
			if( ($rowCtr<9 && $colCtr<9) || ($rowCtr<9 && $colCtr>=$sideNumber-8) || ($rowCtr>=$sideNumber-8 && $colCtr<9) ){
				continue;
			}
			for( $row=$rowCtr-2; $row<=$rowCtr+2; $row++ ){
				for( $col=$colCtr-2; $col<=$colCtr+2; $col++ ){
					$fixMap[$row][$col] = true;
				}
			}
		}
	}
	return $fixMap;
}

/**
 * Reads a code back: its format, its version bits, its codewords, its text.
 */
function readCode( Array $moduleGrid ): Array {
	$sideNb = count($moduleGrid);
	$verNmb = intdiv($sideNb-17, 4);
	$bitOf = function( Int $rowNumber, Int $colNumber ) use ( $moduleGrid ): String { return $moduleGrid[$rowNumber][$colNumber] ? '1' : '0'; };
	// Format, top left : row 8 from the left, then column 8 going up ; and its copy
	$fmtOne = '';
	foreach( Array(0, 1, 2, 3, 4, 5, 7, 8) as $col ){
		$fmtOne .= $bitOf(8, $col);
	}
	foreach( Array(7, 5, 4, 3, 2, 1, 0) as $row ){
		$fmtOne .= $bitOf($row, 8);
	}
	$fmtTwo = '';
	for( $row=$sideNb-1; $row>=$sideNb-7; $row-- ){
		$fmtTwo .= $bitOf($row, 8);
	}
	for( $col=$sideNb-8; $col<$sideNb; $col++ ){
		$fmtTwo .= $bitOf(8, $col);
	}
	$fmtLvl = null;
	$fmtMsk = null;
	foreach( FMTTABLE as $lvlKey => $mskLst ){
		$mskPos = array_search($fmtOne, $mskLst, true);
		if( $mskPos!==false ){
			$fmtLvl = $lvlKey;
			$fmtMsk = $mskPos;
		}
	}
	// Version, top right : bit i at row i/3, column side-11+i%3, least significant first
	$verTxt = '';
	if( $verNmb>=7 ){
		for( $idx=17; $idx>=0; $idx-- ){
			$verTxt .= $bitOf(intdiv($idx, 3), $sideNb-11+$idx%3);
		}
	}
	$result = Array('side'=>$sideNb, 'format'=>$fmtOne, 'copy'=>$fmtTwo, 'level'=>$fmtLvl, 'mask'=>$fmtMsk, 'version'=>$verTxt, 'dark'=>$bitOf($sideNb-8, 8));
	if( $fmtLvl===null ){
		return $result;
	}
	// Data bits, read in zigzag, unmasked
	$fixMap = fixedSet($sideNb);
	$bitStr = '';
	$upward = true;
	for( $rgt=$sideNb-1; $rgt>0; $rgt-=2 ){
		if( $rgt==6 ){
			$rgt--;
		}
		$rowSeq = $upward ? range($sideNb-1, 0) : range(0, $sideNb-1);
		foreach( $rowSeq as $row ){
			foreach( Array($rgt, $rgt-1) as $col ){
				if( $fixMap[$row][$col] ){
					continue;
				}
				$flips = Array(($row+$col)%2==0, $row%2==0, $col%3==0, ($row+$col)%3==0, (intdiv($row, 2)+intdiv($col, 3))%2==0,
					($row*$col)%2+($row*$col)%3==0, ((($row*$col)%2)+(($row*$col)%3))%2==0, ((($row+$col)%2)+(($row*$col)%3))%2==0);
				$bitStr .= ($moduleGrid[$row][$col] xor $flips[$fmtMsk]) ? '1' : '0';
			}
		}
		$upward = !$upward;
	}
	$allWrd = array_map('bindec', str_split(substr($bitStr, 0, 8*intdiv(strlen($bitStr), 8)), 8));
	$result['bits'] = strlen($bitStr);
	$result['words'] = $allWrd;
	return $result;
}

/**
 * Splits codewords back into blocks, checks each by its syndromes, and
 * reads the text of the data.
 */
function readText( Array $codeWords, Array $blockDefin ): Array {
	list($eccNbr, $grpOne, $lenOne, $grpTwo, $lenTwo) = $blockDefin;
	$lenLst = array_merge(array_fill(0, $grpOne, $lenOne), array_fill(0, $grpTwo, $lenTwo));
	$blkLst = array_fill(0, count($lenLst), Array());
	$wrdPos = 0;
	for( $pos=0; $pos<max($lenLst); $pos++ ){
		foreach( $lenLst as $blk => $blkLen ){
			if( $pos<$blkLen ){
				$blkLst[$blk][] = $codeWords[$wrdPos++];
			}
		}
	}
	for( $pos=0; $pos<$eccNbr; $pos++ ){
		foreach( $lenLst as $blk => $blkLen ){
			$blkLst[$blk][] = $codeWords[$wrdPos++];
		}
	}
	// A block is right when its polynomial is zero at each of the first powers of 2
	$synOkay = true;
	foreach( $blkLst as $blkWds ){
		$rootVl = 1;
		for( $syn=0; $syn<$eccNbr; $syn++ ){
			$evalVl = 0;
			foreach( $blkWds as $wrdVal ){
				$evalVl = gfTimes($evalVl, $rootVl) ^ $wrdVal;
			}
			if( $evalVl!=0 ){
				$synOkay = false;
			}
			$rootVl = gfTimes($rootVl, 2);
		}
	}
	$datBit = '';
	foreach( $blkLst as $blk => $blkWds ){
		foreach( array_slice($blkWds, 0, $lenLst[$blk]) as $wrdVal ){
			$datBit .= str_pad(decbin($wrdVal), 8, '0', STR_PAD_LEFT);
		}
	}
	$modeTx = substr($datBit, 0, 4);
	$textLn = bindec(substr($datBit, 4, $blockDefin[5] ?? 8));
	$cntBit = $blockDefin[5] ?? 8;
	$textTx = '';
	for( $chr=0; $chr<$textLn; $chr++ ){
		$textTx .= chr(bindec(substr($datBit, 4+$cntBit+8*$chr, 8)));
	}
	return Array('mode'=>$modeTx, 'syndromes'=>$synOkay, 'text'=>$textTx, 'blocks'=>count($blkLst));
}

// Reed-Solomon : the example of a 1-M code the standard's tutorials use ("HELLO WORLD", alphanumeric)
$rsProbe = new class('') extends QrCode {
	public function remOfData( Array $dataBlocks, Int $degreeNmbr ): Array {
		return $this->makeCorrection($dataBlocks, $this->makeDivisorLst($degreeNmbr));
	}
};
checkIdentical('Reed-Solomon codewords of a known block',
	$rsProbe->remOfData(Array(32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17), 10),
	Array(196, 35, 39, 119, 235, 215, 231, 226, 93, 23));

// Codes of several sizes and levels, read back
$blkTbl = (new ReflectionClass(QrCode::class))->getConstant('BLCK_LIST');
// The bytes each version holds, by level, as the standard lists them
$capTbl = Array(
	'L'=>Array(17, 32, 53, 78, 106, 134, 154, 192, 230, 271),
	'M'=>Array(14, 26, 42, 62, 84, 106, 122, 152, 180, 213),
	'Q'=>Array(11, 20, 32, 46, 60, 74, 86, 108, 130, 151),
	'H'=>Array(7, 14, 24, 34, 44, 58, 64, 84, 98, 119)
);
$smpLst = Array(
	Array('', 'M'),
	Array('nyuRuns', 'L'),
	Array('https://app.example.ch/access?do=actvte&user=anna&code=K7QMXRPWDZ', 'M'),
	Array('otpauth://totp/nyuRuns:anna?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=nyuRuns', 'Q'),
	Array(str_repeat('0123456789abcdef', 7), 'H'),
	Array(str_repeat('x', 150), 'M'),
	Array("Zürich – ünïcødé ✓", 'H'),
	Array(str_repeat('a', 213), 'M'),
	Array(str_repeat('b', 151), 'Q'),
	Array(str_repeat('c', 100), 'L'),
	Array(str_repeat('d', 140), 'L'),
	Array(str_repeat('e', 119), 'H'),
	Array(str_repeat('f', 60), 'Q'),
	Array(str_repeat('g', 61), 'Q'),
	Array(str_repeat('h', 20), 'M'),
	Array(str_repeat('i', 30), 'M'),
	Array(str_repeat('j', 170), 'M')
);
$verSeen = Array();
foreach( $smpLst as $smpDef ){
	list($smpTxt, $smpLvl) = $smpDef;
	$smpVer = 1;
	while( $capTbl[$smpLvl][$smpVer-1]<strlen($smpTxt) ){
		$smpVer++;
	}
	$verSeen[$smpVer] = true;
	$qrcObj = new QrCode($smpTxt, $smpLvl);
	$smpLbl = $smpLvl.'/'.strlen($smpTxt).' bytes';
	checkIdentical($smpLbl.': smallest version', $qrcObj->getCodeVersion(), $smpVer);
	$readOf = readCode($qrcObj->makeModuleGrid());
	checkIdentical($smpLbl.': side', $readOf['side'], 17+4*$smpVer);
	checkIdentical($smpLbl.': format, from the standard\'s table', $readOf['level'], $smpLvl);
	checkIdentical($smpLbl.': copy of the format', $readOf['copy'], $readOf['format']);
	checkIdentical($smpLbl.': dark module', $readOf['dark'], '1');
	if( $smpVer>=7 ){
		checkIdentical($smpLbl.': version, from the standard\'s table', $readOf['version'], VERTABLE[$smpVer]);
	}
	$blkDef = $blkTbl[$smpVer][$smpLvl];
	$allCnt = $blkDef[1]*$blkDef[2] + $blkDef[3]*$blkDef[4] + ($blkDef[1]+$blkDef[3])*$blkDef[0];
	checkIdentical($smpLbl.': codewords read', count($readOf['words'] ?? Array()), $allCnt);
	$blkDef[5] = ($smpVer<10) ? 8 : 16;
	$txtOut = readText($readOf['words'] ?? Array(), $blkDef);
	checkIdentical($smpLbl.': byte mode', $txtOut['mode'], '0100');
	checkIdentical($smpLbl.': error correction of every block', $txtOut['syndromes'], true);
	checkIdentical($smpLbl.': text read back', $txtOut['text'], $smpTxt);
}

ksort($verSeen);
checkIdentical('every version is made', array_keys($verSeen), range(1, 10));

// Every mask makes a code that reads back : the mask chosen is one of them
$mskSeen = Array();
foreach( Array('a', 'ab', 'abc', 'abcd', 'hello', 'nyuRuns 1.0', 'K7QM-3XRP-WD', '0', '00', 'zzzzzz', 'QR', 'mask?', 'xyz123', 'https://x.ch') as $smpTxt ){
	$mskSeen[ readCode( (new QrCode($smpTxt, 'M'))->makeModuleGrid() )['mask'] ] = true;
}
checkTrueValue('the mask changes with the text', count($mskSeen)>1);

// Capacity : 213 bytes at M in version 10, one more is refused
checkForThrows('a text beyond version 10 is refused', function(){ new QrCode(str_repeat('a', 214), 'M'); }, QrCodeError::class);
checkForThrows('an unknown level is refused', function(){ new QrCode('x', 'm'); }, QrCodeError::class);
checkIdentical('the largest text at L', (new QrCode(str_repeat('a', 271), 'L'))->getCodeVersion(), 10);

// Outputs
$qrcObj = new QrCode('nyuRuns', 'M');
$svgTxt = $qrcObj->renderSvgImage(3, 4);
checkPregMatch('SVG: a standalone drawing of the right size', $svgTxt, '/^<svg xmlns="http:\/\/www\.w3\.org\/2000\/svg" version="1\.1" width="87" height="87" viewBox="0 0 29 29"/');
$svgXml = @simplexml_load_string($svgTxt);
checkTrueValue('SVG: well formed', $svgXml!==false);
$drkCnt = 0;
foreach( $qrcObj->makeModuleGrid() as $rowArr ){
	$drkCnt += count(array_filter($rowArr));
}
preg_match_all('/h(\d+)v1/', $svgTxt, $runMtc);
checkIdentical('SVG: every dark module drawn once', array_sum(array_map('intval', $runMtc[1])), $drkCnt);
checkForThrows('SVG: a module of no size is refused', function() use ( $qrcObj ){ $qrcObj->renderSvgImage(0); }, QrCodeError::class);

$artTxt = $qrcObj->renderShellTxt(2);
$artLns = explode("\n", rtrim($artTxt, "\n"));
checkIdentical('text: two rows of modules a line', count($artLns), intdiv(21+4+1, 2));
checkIdentical('text: every line as wide as the code and its margin', array_unique(array_map('mb_strlen', $artLns)), Array(25));
checkIdentical('text: the margin is light (blocks)', $artLns[0], str_repeat('█', 25));
// Back from the text to the grid : a block is light
$artGrd = Array();
foreach( $artLns as $lneTxt ){
	$topRow = Array();
	$lowRow = Array();
	foreach( mb_str_split($lneTxt) as $chrTxt ){
		$topRow[] = !in_array($chrTxt, Array('█', '▀'), true);
		$lowRow[] = !in_array($chrTxt, Array('█', '▄'), true);
	}
	$artGrd[] = $topRow;
	$artGrd[] = $lowRow;
}
$inGrid = array_map(function( Array $gridOfRow ): Array { return array_slice($gridOfRow, 2, 21); }, array_slice($artGrd, 2, 21));
checkIdentical('text: the modules are those of the grid', $inGrid, $qrcObj->makeModuleGrid());
checkForThrows('text: a negative margin is refused', function() use ( $qrcObj ){ $qrcObj->renderShellTxt(-1); }, QrCodeError::class);

finishChecking();
