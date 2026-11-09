<?php

namespace nyu\Data;

/**
 * A QR code (ISO/IEC 18004) of a text: an authenticator address, a link.
 *
 * The text is encoded as it is, byte by byte (byte mode), in the smallest
 * version that holds it, up to version 10 (57 x 57 modules, 213 bytes at
 * level M). The level of error correction is L (7 % of the code can be
 * lost), M (15 %), Q (25 %) or H (30 %). The mask is the one the standard
 * scores best.
 *
 * The code is given as a grid, as a drawing (SVG), or as text for a
 * terminal. No extension of PHP is needed.
 */
class QrCode {

	// The largest version made
	const LAST_VERS = 10;
	// The levels of error correction, and the two bits the format tells them by
	const ECCS_LEVL = Array('L'=>1, 'M'=>0, 'Q'=>3, 'H'=>2);
	// For each version and level: error correction codewords per block, then the blocks of the first group and their data codewords, then those of the second group
	const BLCK_LIST = Array(
		1=>Array('L'=>Array(7, 1, 19, 0, 0), 'M'=>Array(10, 1, 16, 0, 0), 'Q'=>Array(13, 1, 13, 0, 0), 'H'=>Array(17, 1, 9, 0, 0)),
		2=>Array('L'=>Array(10, 1, 34, 0, 0), 'M'=>Array(16, 1, 28, 0, 0), 'Q'=>Array(22, 1, 22, 0, 0), 'H'=>Array(28, 1, 16, 0, 0)),
		3=>Array('L'=>Array(15, 1, 55, 0, 0), 'M'=>Array(26, 1, 44, 0, 0), 'Q'=>Array(18, 2, 17, 0, 0), 'H'=>Array(22, 2, 13, 0, 0)),
		4=>Array('L'=>Array(20, 1, 80, 0, 0), 'M'=>Array(18, 2, 32, 0, 0), 'Q'=>Array(26, 2, 24, 0, 0), 'H'=>Array(16, 4, 9, 0, 0)),
		5=>Array('L'=>Array(26, 1, 108, 0, 0), 'M'=>Array(24, 2, 43, 0, 0), 'Q'=>Array(18, 2, 15, 2, 16), 'H'=>Array(22, 2, 11, 2, 12)),
		6=>Array('L'=>Array(18, 2, 68, 0, 0), 'M'=>Array(16, 4, 27, 0, 0), 'Q'=>Array(24, 4, 19, 0, 0), 'H'=>Array(28, 4, 15, 0, 0)),
		7=>Array('L'=>Array(20, 2, 78, 0, 0), 'M'=>Array(18, 4, 31, 0, 0), 'Q'=>Array(18, 2, 14, 4, 15), 'H'=>Array(26, 4, 13, 1, 14)),
		8=>Array('L'=>Array(24, 2, 97, 0, 0), 'M'=>Array(22, 2, 38, 2, 39), 'Q'=>Array(22, 4, 18, 2, 19), 'H'=>Array(26, 4, 14, 2, 15)),
		9=>Array('L'=>Array(30, 2, 116, 0, 0), 'M'=>Array(22, 3, 36, 2, 37), 'Q'=>Array(20, 4, 16, 4, 17), 'H'=>Array(24, 4, 12, 4, 13)),
		10=>Array('L'=>Array(18, 2, 68, 2, 69), 'M'=>Array(26, 4, 43, 1, 44), 'Q'=>Array(24, 6, 19, 2, 20), 'H'=>Array(28, 6, 15, 2, 16))
	);
	// The rows and columns the alignment patterns are centred on, by version
	const ALGN_LIST = Array(1=>Array(), 2=>Array(6, 18), 3=>Array(6, 22), 4=>Array(6, 26), 5=>Array(6, 30), 6=>Array(6, 34),
		7=>Array(6, 22, 38), 8=>Array(6, 24, 42), 9=>Array(6, 26, 46), 10=>Array(6, 28, 50));

	protected $encodeText;
	protected $errorLevel;
	protected $versNumber;
	protected $sideLength;
	protected $moduleGrid;
	protected $fixedCells;

	/**
	 * Encodes a text. The code is made at once: a text that does not fit
	 * is refused here.
	 *
	 * @param String $templateText The text, taken byte by byte (UTF-8 stays UTF-8).
	 * @param String $errLevelNumb The level of error correction: 'L', 'M', 'Q' or 'H'.
	 *
	 * @throws QrCodeError When the level is unknown, or the text does not fit in a code of version 10.
	 */
	function __construct( String $templateText, String $errLevelNumb='M' ){
		if( !isset(self::ECCS_LEVL[$errLevelNumb]) ){
			throw new QrCodeError('Unknown level of error correction: '.json_encode($errLevelNumb));
		}
		$this->encodeText = $templateText;
		$this->errorLevel = $errLevelNumb;
		$this->versNumber = $this->pickVersionNum( strlen($templateText) );
		$this->sideLength = 17 + 4*$this->versNumber;
		$this->moduleGrid = array_fill(0, $this->sideLength, array_fill(0, $this->sideLength, false));
		$this->fixedCells = $this->moduleGrid;
		$this->drawFixedParts();
		$this->placeCodewords( $this->fullWordsArray($this->dataWordsArray()) );

		// The mask scored best is kept : each one is tried, then taken off
		$bstMask = 0;
		$bestPts = PHP_INT_MAX;
		for( $mskInc=0; $mskInc<8; $mskInc++ ){
			$this->maskDataModule($mskInc);
			$this->drawCodeFormat($mskInc);
			$maskPts = $this->maskPenaltyNum();
			if( $maskPts<$bestPts ){
				$bestPts = $maskPts;
				$bstMask = $mskInc;
			}
			$this->maskDataModule($mskInc);
		}
		$this->maskDataModule($bstMask);
		$this->drawCodeFormat($bstMask);
	}

	/**
	 * Gives the version of the code: 1 to 10, its side being 17 + 4 times it.
	 *
	 * @return Int The version.
	 */
	public function versionOfCount(): Int {
		return $this->versNumber;
	}

	/**
	 * Gives the code as a grid, without its quiet zone.
	 *
	 * @return Array The rows from the top, each a list of modules from the left: true for a dark one.
	 */
	public function generateMatrix(): Array {
		return $this->moduleGrid;
	}

	/**
	 * Gives the code as a drawing: dark modules on a white ground.
	 *
	 * @param Int $modulePixels The side of a module, in pixels (1 at least).
	 * @param Int $quietZonesNb The light margin around the code, in modules (the standard asks for 4).
	 * @return String A standalone SVG document.
	 *
	 * @throws QrCodeError When a size is out of range.
	 */
	public function drawAsSvgImage( Int $modulePixels=4, Int $quietZonesNb=4 ): String {
		if( $modulePixels<1 || $quietZonesNb<0 ){
			throw new QrCodeError('Invalid size of a module or of the quiet zone');
		}
		$sideLen = $this->sideLength + 2*$quietZonesNb;
		$pathTxt = '';
		foreach( $this->moduleGrid as $rowNum => $rowMap ){
			// A run of dark modules on a row is one rectangle
			$clmnNum = 0;
			while( $clmnNum<$this->sideLength ){
				if( !$rowMap[$clmnNum] ){
					$clmnNum++;
					continue;
				}
				$runSize = 1;
				while( $clmnNum+$runSize<$this->sideLength && $rowMap[$clmnNum+$runSize] ){
					$runSize++;
				}
				$pathTxt .= 'M'.($clmnNum+$quietZonesNb).','.($rowNum+$quietZonesNb).'h'.$runSize.'v1h-'.$runSize.'z';
				$clmnNum += $runSize;
			}
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="'.($sideLen*$modulePixels).'" height="'.($sideLen*$modulePixels).'"'
			.' viewBox="0 0 '.$sideLen.' '.$sideLen.'" shape-rendering="crispEdges">'
			.'<rect width="'.$sideLen.'" height="'.$sideLen.'" fill="#fff"/>'
			.'<path fill="#000" d="'.$pathTxt.'"/></svg>';
	}

	/**
	 * Gives the code as text for a terminal, two rows of modules a line.
	 * The light modules and the quiet zone are the ones drawn with blocks
	 * (█ ▀ ▄): on a terminal of light text on a dark ground, the code then
	 * reads as it is printed.
	 *
	 * @param Int $quietZonesNb The light margin around the code, in modules.
	 * @return String The lines, each ending with a line feed.
	 *
	 * @throws QrCodeError When the margin is negative.
	 */
	public function drawAsTerminal( Int $quietZonesNb=2 ): String {
		if( $quietZonesNb<0 ){
			throw new QrCodeError('Invalid size of the quiet zone');
		}
		$sideLen = $this->sideLength + 2*$quietZonesNb;
		// A module of the margin, or beyond the last row, is light
		$isLight = function( Int $rowIdxNumber, Int $columnIdxNum ) use ( $quietZonesNb ): Bool {
			$rowIndx = $rowIdxNumber - $quietZonesNb;
			$clmnInd = $columnIdxNum - $quietZonesNb;
			return !isset($this->moduleGrid[$rowIndx][$clmnInd]) || !$this->moduleGrid[$rowIndx][$clmnInd];
		};
		$artText = '';
		for( $rowInc=0; $rowInc<$sideLen; $rowInc+=2 ){
			for( $colInc=0; $colInc<$sideLen; $colInc++ ){
				$topLite = $isLight($rowInc, $colInc);
				$lowLite = ($rowInc+1<$sideLen) ? $isLight($rowInc+1, $colInc) : false;
				$artText .= $topLite ? ($lowLite ? '█' : '▀') : ($lowLite ? '▄' : ' ');
			}
			$artText .= "\n";
		}
		return $artText;
	}

	/**
	 * Gives the smallest version holding the text at the level of the code.
	 *
	 * @param Int $byteLengthNb The length of the text, in bytes.
	 * @return Int The version.
	 *
	 * @throws QrCodeError When even the largest version made cannot hold it.
	 */
	protected function pickVersionNum( Int $byteLengthNb ): Int {
		for( $verInc=1; $verInc<=self::LAST_VERS; $verInc++ ){
			$blckDef = self::BLCK_LIST[$verInc][$this->errorLevel];
			$capBits = 8*($blckDef[1]*$blckDef[2] + $blckDef[3]*$blckDef[4]);
			if( 4 + ($verInc<10 ? 8 : 16) + 8*$byteLengthNb<=$capBits ){
				return $verInc;
			}
		}
		throw new QrCodeError('Text too long for a QR code of version '.self::LAST_VERS.' at level '.$this->errorLevel.': '.$byteLengthNb.' bytes');
	}

	/**
	 * Gives the data codewords: the mode, the length, the text, then the
	 * end mark and the padding up to the capacity of the version.
	 *
	 * @return Array The codewords, as bytes.
	 */
	protected function dataWordsArray(): Array {
		$blckDef = self::BLCK_LIST[$this->versNumber][$this->errorLevel];
		$capBits = 8*($blckDef[1]*$blckDef[2] + $blckDef[3]*$blckDef[4]);
		$bitText = '0100'.str_pad(decbin(strlen($this->encodeText)), ($this->versNumber<10 ? 8 : 16), '0', STR_PAD_LEFT);
		foreach( str_split($this->encodeText) as $chrVal ){
			if( $chrVal!=='' ){
				$bitText .= str_pad(decbin(ord($chrVal)), 8, '0', STR_PAD_LEFT);
			}
		}
		$bitText .= str_repeat('0', min(4, $capBits - strlen($bitText)));
		$bitText .= str_repeat('0', (8 - strlen($bitText)%8)%8);
		$wordLst = array_map('bindec', str_split($bitText, 8));
		for( $padInc=0; count($wordLst)<$capBits/8; $padInc++ ){
			$wordLst[] = ($padInc%2==0) ? 0xEC : 0x11;
		}
		return $wordLst;
	}

	/**
	 * Splits the data codewords into blocks, adds the error correction of
	 * each, and interleaves them as the code holds them.
	 *
	 * @param Array $dataWordList The data codewords.
	 * @return Array All the codewords, in the order they are placed.
	 */
	protected function fullWordsArray( Array $dataWordList ): Array {
		$blckDef = self::BLCK_LIST[$this->versNumber][$this->errorLevel];
		$dvsrLst = $this->rsDivisorArray($blckDef[0]);
		$dataBlk = Array();
		$eccList = Array();
		$wrdPstn = 0;
		foreach( Array(Array($blckDef[1], $blckDef[2]), Array($blckDef[3], $blckDef[4])) as $grpDef ){
			for( $blkInc=0; $blkInc<$grpDef[0]; $blkInc++ ){
				$blkData = array_slice($dataWordList, $wrdPstn, $grpDef[1]);
				$wrdPstn += $grpDef[1];
				$dataBlk[] = $blkData;
				$eccList[] = $this->rsRemainderArr($blkData, $dvsrLst);
			}
		}
		$allWrds = Array();
		$longest = max(array_map('count', $dataBlk));
		for( $posInc=0; $posInc<$longest; $posInc++ ){
			foreach( $dataBlk as $blkDat ){
				if( isset($blkDat[$posInc]) ){
					$allWrds[] = $blkDat[$posInc];
				}
			}
		}
		for( $posInc=0; $posInc<$blckDef[0]; $posInc++ ){
			foreach( $eccList as $eccDat ){
				$allWrds[] = $eccDat[$posInc];
			}
		}
		return $allWrds;
	}

	/**
	 * Gives the generator polynomial of a Reed-Solomon code, its leading
	 * term left out.
	 *
	 * @param Int $degreeNumber The number of error correction codewords.
	 * @return Array The coefficients, from the highest power down.
	 */
	protected function rsDivisorArray( Int $degreeNumber ): Array {
		$dvsrLst = array_fill(0, $degreeNumber, 0);
		$dvsrLst[$degreeNumber-1] = 1;
		$rootVal = 1;
		for( $degInc=0; $degInc<$degreeNumber; $degInc++ ){
			for( $posInc=0; $posInc<$degreeNumber; $posInc++ ){
				$dvsrLst[$posInc] = $this->gfMultiplyNumb($dvsrLst[$posInc], $rootVal);
				if( $posInc+1<$degreeNumber ){
					$dvsrLst[$posInc] ^= $dvsrLst[$posInc+1];
				}
			}
			$rootVal = $this->gfMultiplyNumb($rootVal, 0x02);
		}
		return $dvsrLst;
	}

	/**
	 * Gives the error correction codewords of a block: the remainder of its
	 * division by the generator polynomial.
	 *
	 * @param Array $dataWordList The data codewords of the block.
	 * @param Array $divisorTerms The generator polynomial (see rsDivisor).
	 * @return Array The error correction codewords.
	 */
	protected function rsRemainderArr( Array $dataWordList, Array $divisorTerms ): Array {
		$remList = array_fill(0, count($divisorTerms), 0);
		foreach( $dataWordList as $bytVal ){
			$coefVal = $bytVal ^ array_shift($remList);
			$remList[] = 0;
			foreach( $divisorTerms as $posInc => $coeffs ){
				$remList[$posInc] ^= $this->gfMultiplyNumb($coeffs, $coefVal);
			}
		}
		return $remList;
	}

	/**
	 * Multiplies two elements of the Galois field GF(2^8) of QR codes
	 * (polynomial 0x11D).
	 *
	 * @param Int $lftFactorNum A byte.
	 * @param Int $rgtFactorNum Another byte.
	 * @return Int Their product, a byte.
	 */
	protected function gfMultiplyNumb( Int $lftFactorNum, Int $rgtFactorNum ): Int {
		$product = 0;
		for( $bitInc=7; $bitInc>=0; $bitInc-- ){
			$product = ($product<<1) ^ (($product>>7)*0x11D);
			$product ^= (($rgtFactorNum>>$bitInc) & 1)*$lftFactorNum;
		}
		return $product;
	}

	/**
	 * Sets a module, and tells whether it belongs to the fixed patterns,
	 * which the data and the mask leave alone.
	 *
	 * @param Int  $columnIdxNum The column, from the left.
	 * @param Int  $rowIdxNumber The row, from the top.
	 * @param Bool $moduleIsDark Whether the module is dark.
	 * @param Bool $fixedPattern Whether it belongs to a fixed pattern.
	 */
	protected function setModuleColor( Int $columnIdxNum, Int $rowIdxNumber, Bool $moduleIsDark, Bool $fixedPattern=true ): Void {
		$this->moduleGrid[$rowIdxNumber][$columnIdxNum] = $moduleIsDark;
		if( $fixedPattern ){
			$this->fixedCells[$rowIdxNumber][$columnIdxNum] = true;
		}
	}

	/**
	 * Draws the fixed patterns: timing, finders, alignment, and the room
	 * of the format and of the version.
	 */
	protected function drawFixedParts(): Void {
		for( $posInc=0; $posInc<$this->sideLength; $posInc++ ){
			$this->setModuleColor(6, $posInc, $posInc%2==0);
			$this->setModuleColor($posInc, 6, $posInc%2==0);
		}
		$this->drawCodeFinder(3, 3);
		$this->drawCodeFinder($this->sideLength-4, 3);
		$this->drawCodeFinder(3, $this->sideLength-4);
		$algoLst = self::ALGN_LIST[$this->versNumber];
		$lastPos = count($algoLst) - 1;
		foreach( $algoLst as $rowPos => $rowCtr ){
			foreach( $algoLst as $colPos => $colCtr ){
				// Not over the finders
				if( ($rowPos==0 && $colPos==0) || ($rowPos==0 && $colPos==$lastPos) || ($rowPos==$lastPos && $colPos==0) ){
					continue;
				}
				$this->drawAlignShape($colCtr, $rowCtr);
			}
		}
		$this->drawCodeFormat(0);
		$this->drawVersionNum();
	}

	/**
	 * Draws a finder pattern and its light separator.
	 *
	 * @param Int $centreColumn The column of its centre.
	 * @param Int $centreRowNNb The row of its centre.
	 */
	protected function drawCodeFinder( Int $centreColumn, Int $centreRowNNb ): Void {
		for( $rdtInc=-4; $rdtInc<=4; $rdtInc++ ){
			for( $cdtInc=-4; $cdtInc<=4; $cdtInc++ ){
				$clmnInd = $centreColumn + $cdtInc;
				$rowIndx = $centreRowNNb + $rdtInc;
				if( $clmnInd>=0 && $clmnInd<$this->sideLength && $rowIndx>=0 && $rowIndx<$this->sideLength ){
					$distNum = max(abs($cdtInc), abs($rdtInc));
					$this->setModuleColor($clmnInd, $rowIndx, $distNum!=2 && $distNum!=4);
				}
			}
		}
	}

	/**
	 * Draws an alignment pattern.
	 *
	 * @param Int $centreColumn The column of its centre.
	 * @param Int $centreRowNNb The row of its centre.
	 */
	protected function drawAlignShape( Int $centreColumn, Int $centreRowNNb ): Void {
		for( $rdtInc=-2; $rdtInc<=2; $rdtInc++ ){
			for( $cdtInc=-2; $cdtInc<=2; $cdtInc++ ){
				$this->setModuleColor($centreColumn+$cdtInc, $centreRowNNb+$rdtInc, max(abs($cdtInc), abs($rdtInc))!=1);
			}
		}
	}

	/**
	 * Draws the two copies of the format: the level and the mask, with
	 * their BCH error correction.
	 *
	 * @param Int $codeMaskNumb The mask, 0 to 7.
	 */
	protected function drawCodeFormat( Int $codeMaskNumb ): Void {
		$fmtBits = (self::ECCS_LEVL[$this->errorLevel]<<3) | $codeMaskNumb;
		$remValu = $fmtBits;
		for( $bitInc=0; $bitInc<10; $bitInc++ ){
			$remValu = ($remValu<<1) ^ (($remValu>>9)*0x537);
		}
		$frmtBts = (($fmtBits<<10) | $remValu) ^ 0x5412;
		$sideNum = $this->sideLength;
		// Around the finder of the top left corner
		for( $bitInc=0; $bitInc<=5; $bitInc++ ){
			$this->setModuleColor(8, $bitInc, (($frmtBts>>$bitInc) & 1)==1);
		}
		$this->setModuleColor(8, 7, (($frmtBts>>6) & 1)==1);
		$this->setModuleColor(8, 8, (($frmtBts>>7) & 1)==1);
		$this->setModuleColor(7, 8, (($frmtBts>>8) & 1)==1);
		for( $bitInc=9; $bitInc<15; $bitInc++ ){
			$this->setModuleColor(14-$bitInc, 8, (($frmtBts>>$bitInc) & 1)==1);
		}
		// Along the two other finders
		for( $bitInc=0; $bitInc<8; $bitInc++ ){
			$this->setModuleColor($sideNum-1-$bitInc, 8, (($frmtBts>>$bitInc) & 1)==1);
		}
		for( $bitInc=8; $bitInc<15; $bitInc++ ){
			$this->setModuleColor(8, $sideNum-15+$bitInc, (($frmtBts>>$bitInc) & 1)==1);
		}
		// The module that is always dark
		$this->setModuleColor(8, $sideNum-8, true);
	}

	/**
	 * Draws the two copies of the version, from version 7 on.
	 */
	protected function drawVersionNum(): Void {
		if( $this->versNumber<7 ){
			return;
		}
		$remValu = $this->versNumber;
		for( $bitInc=0; $bitInc<12; $bitInc++ ){
			$remValu = ($remValu<<1) ^ (($remValu>>11)*0x1F25);
		}
		$versBts = ($this->versNumber<<12) | $remValu;
		for( $bitInc=0; $bitInc<18; $bitInc++ ){
			$isBlack = (($versBts>>$bitInc) & 1)==1;
			$farPstn = $this->sideLength - 11 + $bitInc%3;
			$nearPos = intdiv($bitInc, 3);
			$this->setModuleColor($farPstn, $nearPos, $isBlack);
			$this->setModuleColor($nearPos, $farPstn, $isBlack);
		}
	}

	/**
	 * Places the codewords: two columns at a time from the right, going up
	 * then down, around the fixed patterns. The modules left over stay light.
	 *
	 * @param Array $codewordList The codewords, in the order they are placed.
	 */
	protected function placeCodewords( Array $codewordList ): Void {
		$bitTotl = 8*count($codewordList);
		$bitIndx = 0;
		for( $rgtInc=$this->sideLength-1; $rgtInc>=1; $rgtInc-=2 ){
			// The vertical timing pattern is skipped
			if( $rgtInc==6 ){
				$rgtInc = 5;
			}
			$goingUp = (($rgtInc+1) & 2)==0;
			for( $vrtInc=0; $vrtInc<$this->sideLength; $vrtInc++ ){
				$rowIndx = $goingUp ? $this->sideLength-1-$vrtInc : $vrtInc;
				for( $sidInc=0; $sidInc<2; $sidInc++ ){
					$clmnInd = $rgtInc - $sidInc;
					if( !$this->fixedCells[$rowIndx][$clmnInd] && $bitIndx<$bitTotl ){
						$wordVal = $codewordList[$bitIndx>>3];
						$this->moduleGrid[$rowIndx][$clmnInd] = (($wordVal>>(7-($bitIndx & 7))) & 1)==1;
						$bitIndx++;
					}
				}
			}
		}
	}

	/**
	 * Turns over the data modules a mask selects. Applied twice, a mask
	 * is taken off.
	 *
	 * @param Int $codeMaskNumb The mask, 0 to 7.
	 */
	protected function maskDataModule( Int $codeMaskNumb ): Void {
		for( $rowInc=0; $rowInc<$this->sideLength; $rowInc++ ){
			for( $colInc=0; $colInc<$this->sideLength; $colInc++ ){
				if( $this->fixedCells[$rowInc][$colInc] ){
					continue;
				}
				switch( $codeMaskNumb ){
					case 0: $flipNow = ($colInc+$rowInc)%2==0; break;
					case 1: $flipNow = $rowInc%2==0; break;
					case 2: $flipNow = $colInc%3==0; break;
					case 3: $flipNow = ($colInc+$rowInc)%3==0; break;
					case 4: $flipNow = (intdiv($colInc, 3)+intdiv($rowInc, 2))%2==0; break;
					case 5: $flipNow = ($colInc*$rowInc)%2 + ($colInc*$rowInc)%3==0; break;
					case 6: $flipNow = (($colInc*$rowInc)%2 + ($colInc*$rowInc)%3)%2==0; break;
					default: $flipNow = (($colInc+$rowInc)%2 + ($colInc*$rowInc)%3)%2==0;
				}
				if( $flipNow ){
					$this->moduleGrid[$rowInc][$colInc] = !$this->moduleGrid[$rowInc][$colInc];
				}
			}
		}
	}

	/**
	 * Scores the code as it is masked, as the standard does: runs of one
	 * colour, squares of one colour, patterns looking like a finder, and
	 * the balance of dark and light. The lower, the better.
	 *
	 * @return Int The penalty.
	 */
	protected function maskPenaltyNum(): Int {
		$penalty = 0;
		$sideNum = $this->sideLength;
		$darkNum = 0;
		for( $posInc=0; $posInc<$sideNum; $posInc++ ){
			$penalty += $this->lineScoreCount( $this->moduleGrid[$posInc] );
			$penalty += $this->lineScoreCount( array_column($this->moduleGrid, $posInc) );
			$darkNum += count( array_filter($this->moduleGrid[$posInc]) );
		}
		for( $rowInc=0; $rowInc<$sideNum-1; $rowInc++ ){
			for( $colInc=0; $colInc<$sideNum-1; $colInc++ ){
				$mdlValu = $this->moduleGrid[$rowInc][$colInc];
				if( $this->moduleGrid[$rowInc][$colInc+1]===$mdlValu && $this->moduleGrid[$rowInc+1][$colInc]===$mdlValu && $this->moduleGrid[$rowInc+1][$colInc+1]===$mdlValu ){
					$penalty += 3;
				}
			}
		}
		$allMods = $sideNum*$sideNum;
		$penalty += 10*( intdiv(abs($darkNum*20 - $allMods*10) + $allMods - 1, $allMods) - 1 );
		return $penalty;
	}

	/**
	 * Scores one row or column: each run of five modules of one colour or
	 * more, and each pattern looking like a finder.
	 *
	 * @param Array $modulesInRow The modules of the line, true for a dark one.
	 * @return Int The penalty of the line.
	 */
	protected function lineScoreCount( Array $modulesInRow ): Int {
		$lineTxt = implode('', array_map(function( Bool $moduleIsDark ): String { return $moduleIsDark ? '1' : '0'; }, $modulesInRow));
		$linePts = 0;
		preg_match_all('/0{5,}|1{5,}/', $lineTxt, $runList);
		foreach( $runList[0] as $runTxt ){
			$linePts += 3 + strlen($runTxt) - 5;
		}
		$linePts += 40*preg_match_all('/(?=10111010000|00001011101)/', $lineTxt);
		return $linePts;
	}
}
