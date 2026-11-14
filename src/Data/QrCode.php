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
		$bestMask = 0;
		$bestPrts = PHP_INT_MAX;
		for( $mskInc=0; $mskInc<8; $mskInc++ ){
			$this->maskDataModule($mskInc);
			$this->drawCodeFormat($mskInc);
			$maskPrts = $this->maskPenaltyNum();
			if( $maskPrts<$bestPrts ){
				$bestPrts = $maskPrts;
				$bestMask = $mskInc;
			}
			$this->maskDataModule($mskInc);
		}
		$this->maskDataModule($bestMask);
		$this->drawCodeFormat($bestMask);
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
		$sideSize = $this->sideLength + 2*$quietZonesNb;
		$pathText = '';
		foreach( $this->moduleGrid as $rowNum => $rowMap ){
			// A run of dark modules on a row is one rectangle
			$clmnNumb = 0;
			while( $clmnNumb<$this->sideLength ){
				if( !$rowMap[$clmnNumb] ){
					$clmnNumb++;
					continue;
				}
				$runBytes = 1;
				while( $clmnNumb+$runBytes<$this->sideLength && $rowMap[$clmnNumb+$runBytes] ){
					$runBytes++;
				}
				$pathText .= 'M'.($clmnNumb+$quietZonesNb).','.($rowNum+$quietZonesNb).'h'.$runBytes.'v1h-'.$runBytes.'z';
				$clmnNumb += $runBytes;
			}
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="'.($sideSize*$modulePixels).'" height="'.($sideSize*$modulePixels).'"'
			.' viewBox="0 0 '.$sideSize.' '.$sideSize.'" shape-rendering="crispEdges">'
			.'<rect width="'.$sideSize.'" height="'.$sideSize.'" fill="#fff"/>'
			.'<path fill="#000" d="'.$pathText.'"/></svg>';
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
		$sideSize = $this->sideLength + 2*$quietZonesNb;
		// A module of the margin, or beyond the last row, is light
		$isHollow = function( Int $rowIdxNumber, Int $columnIdxNum ) use ( $quietZonesNb ): Bool {
			$rowIndex = $rowIdxNumber - $quietZonesNb;
			$clmnIndt = $columnIdxNum - $quietZonesNb;
			return !isset($this->moduleGrid[$rowIndex][$clmnIndt]) || !$this->moduleGrid[$rowIndex][$clmnIndt];
		};
		$drawText = '';
		for( $rowInc=0; $rowInc<$sideSize; $rowInc+=2 ){
			for( $colInc=0; $colInc<$sideSize; $colInc++ ){
				$isTopLit = $isHollow($rowInc, $colInc);
				$lowerLte = ($rowInc+1<$sideSize) ? $isHollow($rowInc+1, $colInc) : false;
				$drawText .= $isTopLit ? ($lowerLte ? '█' : '▀') : ($lowerLte ? '▄' : ' ');
			}
			$drawText .= "\n";
		}
		return $drawText;
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
			$blockDef = self::BLCK_LIST[$verInc][$this->errorLevel];
			$bitsCapa = 8*($blockDef[1]*$blockDef[2] + $blockDef[3]*$blockDef[4]);
			if( 4 + ($verInc<10 ? 8 : 16) + 8*$byteLengthNb<=$bitsCapa ){
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
		$blockDef = self::BLCK_LIST[$this->versNumber][$this->errorLevel];
		$bitsCapa = 8*($blockDef[1]*$blockDef[2] + $blockDef[3]*$blockDef[4]);
		$bitStrng = '0100'.str_pad(decbin(strlen($this->encodeText)), ($this->versNumber<10 ? 8 : 16), '0', STR_PAD_LEFT);
		foreach( str_split($this->encodeText) as $chrVal ){
			if( $chrVal!=='' ){
				$bitStrng .= str_pad(decbin(ord($chrVal)), 8, '0', STR_PAD_LEFT);
			}
		}
		$bitStrng .= str_repeat('0', min(4, $bitsCapa - strlen($bitStrng)));
		$bitStrng .= str_repeat('0', (8 - strlen($bitStrng)%8)%8);
		$wordList = array_map('bindec', str_split($bitStrng, 8));
		for( $padInc=0; count($wordList)<$bitsCapa/8; $padInc++ ){
			$wordList[] = ($padInc%2==0) ? 0xEC : 0x11;
		}
		return $wordList;
	}

	/**
	 * Splits the data codewords into blocks, adds the error correction of
	 * each, and interleaves them as the code holds them.
	 *
	 * @param Array $dataWordList The data codewords.
	 * @return Array All the codewords, in the order they are placed.
	 */
	protected function fullWordsArray( Array $dataWordList ): Array {
		$blockDef = self::BLCK_LIST[$this->versNumber][$this->errorLevel];
		$diviList = $this->rsDivisorArray($blockDef[0]);
		$dataBloc = Array();
		$eccsList = Array();
		$blockPos = 0;
		foreach( Array(Array($blockDef[1], $blockDef[2]), Array($blockDef[3], $blockDef[4])) as $grpDef ){
			for( $blkInc=0; $blkInc<$grpDef[0]; $blkInc++ ){
				$blockDct = array_slice($dataWordList, $blockPos, $grpDef[1]);
				$blockPos += $grpDef[1];
				$dataBloc[] = $blockDct;
				$eccsList[] = $this->rsRemainderArr($blockDct, $diviList);
			}
		}
		$allWords = Array();
		$maxWords = max(array_map('count', $dataBloc));
		for( $posInc=0; $posInc<$maxWords; $posInc++ ){
			foreach( $dataBloc as $blkDat ){
				if( isset($blkDat[$posInc]) ){
					$allWords[] = $blkDat[$posInc];
				}
			}
		}
		for( $posInc=0; $posInc<$blockDef[0]; $posInc++ ){
			foreach( $eccsList as $eccDat ){
				$allWords[] = $eccDat[$posInc];
			}
		}
		return $allWords;
	}

	/**
	 * Gives the generator polynomial of a Reed-Solomon code, its leading
	 * term left out.
	 *
	 * @param Int $degreeNumber The number of error correction codewords.
	 * @return Array The coefficients, from the highest power down.
	 */
	protected function rsDivisorArray( Int $degreeNumber ): Array {
		$diviList = array_fill(0, $degreeNumber, 0);
		$diviList[$degreeNumber-1] = 1;
		$rootVals = 1;
		for( $degInc=0; $degInc<$degreeNumber; $degInc++ ){
			for( $posInc=0; $posInc<$degreeNumber; $posInc++ ){
				$diviList[$posInc] = $this->gfMultiplyNumb($diviList[$posInc], $rootVals);
				if( $posInc+1<$degreeNumber ){
					$diviList[$posInc] ^= $diviList[$posInc+1];
				}
			}
			$rootVals = $this->gfMultiplyNumb($rootVals, 0x02);
		}
		return $diviList;
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
		$remnList = array_fill(0, count($divisorTerms), 0);
		foreach( $dataWordList as $bytVal ){
			$coefVals = $bytVal ^ array_shift($remnList);
			$remnList[] = 0;
			foreach( $divisorTerms as $posInc => $coeffs ){
				$remnList[$posInc] ^= $this->gfMultiplyNumb($coeffs, $coefVals);
			}
		}
		return $remnList;
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
		$prodNumb = 0;
		for( $bitInc=7; $bitInc>=0; $bitInc-- ){
			$prodNumb = ($prodNumb<<1) ^ (($prodNumb>>7)*0x11D);
			$prodNumb ^= (($rgtFactorNum>>$bitInc) & 1)*$lftFactorNum;
		}
		return $prodNumb;
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
		$algoList = self::ALGN_LIST[$this->versNumber];
		$lastIndx = count($algoList) - 1;
		foreach( $algoList as $rowPos => $rowCtr ){
			foreach( $algoList as $colPos => $colCtr ){
				// Not over the finders
				if( ($rowPos==0 && $colPos==0) || ($rowPos==0 && $colPos==$lastIndx) || ($rowPos==$lastIndx && $colPos==0) ){
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
				$clmnIndt = $centreColumn + $cdtInc;
				$rowIndex = $centreRowNNb + $rdtInc;
				if( $clmnIndt>=0 && $clmnIndt<$this->sideLength && $rowIndex>=0 && $rowIndex<$this->sideLength ){
					$distNumb = max(abs($cdtInc), abs($rdtInc));
					$this->setModuleColor($clmnIndt, $rowIndex, $distNumb!=2 && $distNumb!=4);
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
		$frmtBits = (self::ECCS_LEVL[$this->errorLevel]<<3) | $codeMaskNumb;
		$remnValu = $frmtBits;
		for( $bitInc=0; $bitInc<10; $bitInc++ ){
			$remnValu = ($remnValu<<1) ^ (($remnValu>>9)*0x537);
		}
		$frmtByts = (($frmtBits<<10) | $remnValu) ^ 0x5412;
		$sideNumb = $this->sideLength;
		// Around the finder of the top left corner
		for( $bitInc=0; $bitInc<=5; $bitInc++ ){
			$this->setModuleColor(8, $bitInc, (($frmtByts>>$bitInc) & 1)==1);
		}
		$this->setModuleColor(8, 7, (($frmtByts>>6) & 1)==1);
		$this->setModuleColor(8, 8, (($frmtByts>>7) & 1)==1);
		$this->setModuleColor(7, 8, (($frmtByts>>8) & 1)==1);
		for( $bitInc=9; $bitInc<15; $bitInc++ ){
			$this->setModuleColor(14-$bitInc, 8, (($frmtByts>>$bitInc) & 1)==1);
		}
		// Along the two other finders
		for( $bitInc=0; $bitInc<8; $bitInc++ ){
			$this->setModuleColor($sideNumb-1-$bitInc, 8, (($frmtByts>>$bitInc) & 1)==1);
		}
		for( $bitInc=8; $bitInc<15; $bitInc++ ){
			$this->setModuleColor(8, $sideNumb-15+$bitInc, (($frmtByts>>$bitInc) & 1)==1);
		}
		// The module that is always dark
		$this->setModuleColor(8, $sideNumb-8, true);
	}

	/**
	 * Draws the two copies of the version, from version 7 on.
	 */
	protected function drawVersionNum(): Void {
		if( $this->versNumber<7 ){
			return;
		}
		$remnValu = $this->versNumber;
		for( $bitInc=0; $bitInc<12; $bitInc++ ){
			$remnValu = ($remnValu<<1) ^ (($remnValu>>11)*0x1F25);
		}
		$versByts = ($this->versNumber<<12) | $remnValu;
		for( $bitInc=0; $bitInc<18; $bitInc++ ){
			$isFilled = (($versByts>>$bitInc) & 1)==1;
			$farIndex = $this->sideLength - 11 + $bitInc%3;
			$nearIndx = intdiv($bitInc, 3);
			$this->setModuleColor($farIndex, $nearIndx, $isFilled);
			$this->setModuleColor($nearIndx, $farIndex, $isFilled);
		}
	}

	/**
	 * Places the codewords: two columns at a time from the right, going up
	 * then down, around the fixed patterns. The modules left over stay light.
	 *
	 * @param Array $codewordList The codewords, in the order they are placed.
	 */
	protected function placeCodewords( Array $codewordList ): Void {
		$bitsNumb = 8*count($codewordList);
		$bitIndex = 0;
		for( $rgtInc=$this->sideLength-1; $rgtInc>=1; $rgtInc-=2 ){
			// The vertical timing pattern is skipped
			if( $rgtInc==6 ){
				$rgtInc = 5;
			}
			$isUpward = (($rgtInc+1) & 2)==0;
			for( $vrtInc=0; $vrtInc<$this->sideLength; $vrtInc++ ){
				$rowIndex = $isUpward ? $this->sideLength-1-$vrtInc : $vrtInc;
				for( $sidInc=0; $sidInc<2; $sidInc++ ){
					$clmnIndt = $rgtInc - $sidInc;
					if( !$this->fixedCells[$rowIndex][$clmnIndt] && $bitIndex<$bitsNumb ){
						$wordVals = $codewordList[$bitIndex>>3];
						$this->moduleGrid[$rowIndex][$clmnIndt] = (($wordVals>>(7-($bitIndex & 7))) & 1)==1;
						$bitIndex++;
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
					case 0: $isToFlip = ($colInc+$rowInc)%2==0; break;
					case 1: $isToFlip = $rowInc%2==0; break;
					case 2: $isToFlip = $colInc%3==0; break;
					case 3: $isToFlip = ($colInc+$rowInc)%3==0; break;
					case 4: $isToFlip = (intdiv($colInc, 3)+intdiv($rowInc, 2))%2==0; break;
					case 5: $isToFlip = ($colInc*$rowInc)%2 + ($colInc*$rowInc)%3==0; break;
					case 6: $isToFlip = (($colInc*$rowInc)%2 + ($colInc*$rowInc)%3)%2==0; break;
					default: $isToFlip = (($colInc+$rowInc)%2 + ($colInc*$rowInc)%3)%2==0;
				}
				if( $isToFlip ){
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
		$pnltNumb = 0;
		$sideNumb = $this->sideLength;
		$darkNumb = 0;
		for( $posInc=0; $posInc<$sideNumb; $posInc++ ){
			$pnltNumb += $this->lineScoreCount( $this->moduleGrid[$posInc] );
			$pnltNumb += $this->lineScoreCount( array_column($this->moduleGrid, $posInc) );
			$darkNumb += count( array_filter($this->moduleGrid[$posInc]) );
		}
		for( $rowInc=0; $rowInc<$sideNumb-1; $rowInc++ ){
			for( $colInc=0; $colInc<$sideNumb-1; $colInc++ ){
				$modelVal = $this->moduleGrid[$rowInc][$colInc];
				if( $this->moduleGrid[$rowInc][$colInc+1]===$modelVal && $this->moduleGrid[$rowInc+1][$colInc]===$modelVal && $this->moduleGrid[$rowInc+1][$colInc+1]===$modelVal ){
					$pnltNumb += 3;
				}
			}
		}
		$modeList = $sideNumb*$sideNumb;
		$pnltNumb += 10*( intdiv(abs($darkNumb*20 - $modeList*10) + $modeList - 1, $modeList) - 1 );
		return $pnltNumb;
	}

	/**
	 * Scores one row or column: each run of five modules of one colour or
	 * more, and each pattern looking like a finder.
	 *
	 * @param Array $modulesInRow The modules of the line, true for a dark one.
	 * @return Int The penalty of the line.
	 */
	protected function lineScoreCount( Array $modulesInRow ): Int {
		$lineText = implode('', array_map(function( Bool $moduleIsDark ): String { return $moduleIsDark ? '1' : '0'; }, $modulesInRow));
		$linePrts = 0;
		preg_match_all('/0{5,}|1{5,}/', $lineText, $runsList);
		foreach( $runsList[0] as $runTxt ){
			$linePrts += 3 + strlen($runTxt) - 5;
		}
		$linePrts += 40*preg_match_all('/(?=10111010000|00001011101)/', $lineText);
		return $linePrts;
	}
}
