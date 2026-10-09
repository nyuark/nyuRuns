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
	const LASTVERS = 10;
	// The levels of error correction, and the two bits the format tells them by
	const ECCLEVEL = Array('L'=>1, 'M'=>0, 'Q'=>3, 'H'=>2);
	// For each version and level: error correction codewords per block, then the blocks of the first group and their data codewords, then those of the second group
	const BLCKLIST = Array(
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
	const ALGNLIST = Array(1=>Array(), 2=>Array(6, 18), 3=>Array(6, 22), 4=>Array(6, 26), 5=>Array(6, 30), 6=>Array(6, 34),
		7=>Array(6, 22, 38), 8=>Array(6, 24, 42), 9=>Array(6, 26, 46), 10=>Array(6, 28, 50));

	protected $textData;
	protected $eccLevel;
	protected $versNmbr;
	protected $sideSize;
	protected $modulMap;
	protected $fixedMap;

	/**
	 * Encodes a text. The code is made at once: a text that does not fit
	 * is refused here.
	 *
	 * @param String $textString The text, taken byte by byte (UTF-8 stays UTF-8).
	 * @param String $errorLevel The level of error correction: 'L', 'M', 'Q' or 'H'.
	 *
	 * @throws QrCodeError When the level is unknown, or the text does not fit in a code of version 10.
	 */
	function __construct( String $textString, String $errorLevel='M' ){
		if( !isset(self::ECCLEVEL[$errorLevel]) ){
			throw new QrCodeError('Unknown level of error correction: '.json_encode($errorLevel));
		}
		$this->textData = $textString;
		$this->eccLevel = $errorLevel;
		$this->versNmbr = $this->pickVersn( strlen($textString) );
		$this->sideSize = 17 + 4*$this->versNmbr;
		$this->modulMap = array_fill(0, $this->sideSize, array_fill(0, $this->sideSize, false));
		$this->fixedMap = $this->modulMap;
		$this->drawFixed();
		$this->drawWords( $this->fullWords($this->dataWords()) );

		// The mask scored best is kept : each one is tried, then taken off
		$bestMsk = 0;
		$bestPts = PHP_INT_MAX;
		for( $msk=0; $msk<8; $msk++ ){
			$this->applyMask($msk);
			$this->drawFormt($msk);
			$mskPts = $this->maskPnlty();
			if( $mskPts<$bestPts ){
				$bestPts = $mskPts;
				$bestMsk = $msk;
			}
			$this->applyMask($msk);
		}
		$this->applyMask($bestMsk);
		$this->drawFormt($bestMsk);
	}

	/**
	 * Gives the version of the code: 1 to 10, its side being 17 + 4 times it.
	 *
	 * @return Int The version.
	 */
	public function versionOf(): Int {
		return $this->versNmbr;
	}

	/**
	 * Gives the code as a grid, without its quiet zone.
	 *
	 * @return Array The rows from the top, each a list of modules from the left: true for a dark one.
	 */
	public function makeMatrx(): Array {
		return $this->modulMap;
	}

	/**
	 * Gives the code as a drawing: dark modules on a white ground.
	 *
	 * @param Int $modulePixl The side of a module, in pixels (1 at least).
	 * @param Int $quietZones The light margin around the code, in modules (the standard asks for 4).
	 * @return String A standalone SVG document.
	 *
	 * @throws QrCodeError When a size is out of range.
	 */
	public function asSvgCode( Int $modulePixl=4, Int $quietZones=4 ): String {
		if( $modulePixl<1 || $quietZones<0 ){
			throw new QrCodeError('Invalid size of a module or of the quiet zone');
		}
		$fullSde = $this->sideSize + 2*$quietZones;
		$pathTxt = '';
		foreach( $this->modulMap as $rowNum => $rowMap ){
			// A run of dark modules on a row is one rectangle
			$colNum = 0;
			while( $colNum<$this->sideSize ){
				if( !$rowMap[$colNum] ){
					$colNum++;
					continue;
				}
				$runLen = 1;
				while( $colNum+$runLen<$this->sideSize && $rowMap[$colNum+$runLen] ){
					$runLen++;
				}
				$pathTxt .= 'M'.($colNum+$quietZones).','.($rowNum+$quietZones).'h'.$runLen.'v1h-'.$runLen.'z';
				$colNum += $runLen;
			}
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="'.($fullSde*$modulePixl).'" height="'.($fullSde*$modulePixl).'"'
			.' viewBox="0 0 '.$fullSde.' '.$fullSde.'" shape-rendering="crispEdges">'
			.'<rect width="'.$fullSde.'" height="'.$fullSde.'" fill="#fff"/>'
			.'<path fill="#000" d="'.$pathTxt.'"/></svg>';
	}

	/**
	 * Gives the code as text for a terminal, two rows of modules a line.
	 * The light modules and the quiet zone are the ones drawn with blocks
	 * (█ ▀ ▄): on a terminal of light text on a dark ground, the code then
	 * reads as it is printed.
	 *
	 * @param Int $quietZones The light margin around the code, in modules.
	 * @return String The lines, each ending with a line feed.
	 *
	 * @throws QrCodeError When the margin is negative.
	 */
	public function asTextArt( Int $quietZones=2 ): String {
		if( $quietZones<0 ){
			throw new QrCodeError('Invalid size of the quiet zone');
		}
		$fullSde = $this->sideSize + 2*$quietZones;
		// A module of the margin, or beyond the last row, is light
		$isLight = function( Int $rowIndexNb, Int $columnIndx ) use ( $quietZones ): Bool {
			$rowInd = $rowIndexNb - $quietZones;
			$colInd = $columnIndx - $quietZones;
			return !isset($this->modulMap[$rowInd][$colInd]) || !$this->modulMap[$rowInd][$colInd];
		};
		$artText = '';
		for( $row=0; $row<$fullSde; $row+=2 ){
			for( $col=0; $col<$fullSde; $col++ ){
				$topLgt = $isLight($row, $col);
				$lowLgt = ($row+1<$fullSde) ? $isLight($row+1, $col) : false;
				$artText .= $topLgt ? ($lowLgt ? '█' : '▀') : ($lowLgt ? '▄' : ' ');
			}
			$artText .= "\n";
		}
		return $artText;
	}

	/**
	 * Gives the smallest version holding the text at the level of the code.
	 *
	 * @param Int $byteLength The length of the text, in bytes.
	 * @return Int The version.
	 *
	 * @throws QrCodeError When even the largest version made cannot hold it.
	 */
	protected function pickVersn( Int $byteLength ): Int {
		for( $ver=1; $ver<=self::LASTVERS; $ver++ ){
			$blkDef = self::BLCKLIST[$ver][$this->eccLevel];
			$capBits = 8*($blkDef[1]*$blkDef[2] + $blkDef[3]*$blkDef[4]);
			if( 4 + ($ver<10 ? 8 : 16) + 8*$byteLength<=$capBits ){
				return $ver;
			}
		}
		throw new QrCodeError('Text too long for a QR code of version '.self::LASTVERS.' at level '.$this->eccLevel.': '.$byteLength.' bytes');
	}

	/**
	 * Gives the data codewords: the mode, the length, the text, then the
	 * end mark and the padding up to the capacity of the version.
	 *
	 * @return Array The codewords, as bytes.
	 */
	protected function dataWords(): Array {
		$blkDef = self::BLCKLIST[$this->versNmbr][$this->eccLevel];
		$capBits = 8*($blkDef[1]*$blkDef[2] + $blkDef[3]*$blkDef[4]);
		$bitText = '0100'.str_pad(decbin(strlen($this->textData)), ($this->versNmbr<10 ? 8 : 16), '0', STR_PAD_LEFT);
		foreach( str_split($this->textData) as $chrVal ){
			if( $chrVal!=='' ){
				$bitText .= str_pad(decbin(ord($chrVal)), 8, '0', STR_PAD_LEFT);
			}
		}
		$bitText .= str_repeat('0', min(4, $capBits - strlen($bitText)));
		$bitText .= str_repeat('0', (8 - strlen($bitText)%8)%8);
		$wordLst = array_map('bindec', str_split($bitText, 8));
		for( $pad=0; count($wordLst)<$capBits/8; $pad++ ){
			$wordLst[] = ($pad%2==0) ? 0xEC : 0x11;
		}
		return $wordLst;
	}

	/**
	 * Splits the data codewords into blocks, adds the error correction of
	 * each, and interleaves them as the code holds them.
	 *
	 * @param Array $dataBlocks The data codewords.
	 * @return Array All the codewords, in the order they are placed.
	 */
	protected function fullWords( Array $dataBlocks ): Array {
		$blkDef = self::BLCKLIST[$this->versNmbr][$this->eccLevel];
		$divList = $this->rsDivisor($blkDef[0]);
		$dataBlk = Array();
		$eccBlks = Array();
		$wordPos = 0;
		foreach( Array(Array($blkDef[1], $blkDef[2]), Array($blkDef[3], $blkDef[4])) as $grpDef ){
			for( $blk=0; $blk<$grpDef[0]; $blk++ ){
				$blkData = array_slice($dataBlocks, $wordPos, $grpDef[1]);
				$wordPos += $grpDef[1];
				$dataBlk[] = $blkData;
				$eccBlks[] = $this->rsRemaind($blkData, $divList);
			}
		}
		$allWrds = Array();
		$longest = max(array_map('count', $dataBlk));
		for( $pos=0; $pos<$longest; $pos++ ){
			foreach( $dataBlk as $blkDat ){
				if( isset($blkDat[$pos]) ){
					$allWrds[] = $blkDat[$pos];
				}
			}
		}
		for( $pos=0; $pos<$blkDef[0]; $pos++ ){
			foreach( $eccBlks as $eccDat ){
				$allWrds[] = $eccDat[$pos];
			}
		}
		return $allWrds;
	}

	/**
	 * Gives the generator polynomial of a Reed-Solomon code, its leading
	 * term left out.
	 *
	 * @param Int $degreeNmbr The number of error correction codewords.
	 * @return Array The coefficients, from the highest power down.
	 */
	protected function rsDivisor( Int $degreeNmbr ): Array {
		$divList = array_fill(0, $degreeNmbr, 0);
		$divList[$degreeNmbr-1] = 1;
		$rootVal = 1;
		for( $deg=0; $deg<$degreeNmbr; $deg++ ){
			for( $pos=0; $pos<$degreeNmbr; $pos++ ){
				$divList[$pos] = $this->gfMultply($divList[$pos], $rootVal);
				if( $pos+1<$degreeNmbr ){
					$divList[$pos] ^= $divList[$pos+1];
				}
			}
			$rootVal = $this->gfMultply($rootVal, 0x02);
		}
		return $divList;
	}

	/**
	 * Gives the error correction codewords of a block: the remainder of its
	 * division by the generator polynomial.
	 *
	 * @param Array $dataBlocks The data codewords of the block.
	 * @param Array $divisorLst The generator polynomial (see rsDivisor).
	 * @return Array The error correction codewords.
	 */
	protected function rsRemaind( Array $dataBlocks, Array $divisorLst ): Array {
		$remList = array_fill(0, count($divisorLst), 0);
		foreach( $dataBlocks as $bytVal ){
			$factor = $bytVal ^ array_shift($remList);
			$remList[] = 0;
			foreach( $divisorLst as $pos => $coeffs ){
				$remList[$pos] ^= $this->gfMultply($coeffs, $factor);
			}
		}
		return $remList;
	}

	/**
	 * Multiplies two elements of the Galois field GF(2^8) of QR codes
	 * (polynomial 0x11D).
	 *
	 * @param Int $leftFactor A byte.
	 * @param Int $rightFactr Another byte.
	 * @return Int Their product, a byte.
	 */
	protected function gfMultply( Int $leftFactor, Int $rightFactr ): Int {
		$product = 0;
		for( $bit=7; $bit>=0; $bit-- ){
			$product = ($product<<1) ^ (($product>>7)*0x11D);
			$product ^= (($rightFactr>>$bit) & 1)*$leftFactor;
		}
		return $product;
	}

	/**
	 * Sets a module, and tells whether it belongs to the fixed patterns,
	 * which the data and the mask leave alone.
	 *
	 * @param Int  $columnIndx The column, from the left.
	 * @param Int  $rowIndexNb The row, from the top.
	 * @param Bool $darkModule Whether the module is dark.
	 * @param Bool $fixedPlace Whether it belongs to a fixed pattern.
	 */
	protected function setModule( Int $columnIndx, Int $rowIndexNb, Bool $darkModule, Bool $fixedPlace=true ): Void {
		$this->modulMap[$rowIndexNb][$columnIndx] = $darkModule;
		if( $fixedPlace ){
			$this->fixedMap[$rowIndexNb][$columnIndx] = true;
		}
	}

	/**
	 * Draws the fixed patterns: timing, finders, alignment, and the room
	 * of the format and of the version.
	 */
	protected function drawFixed(): Void {
		for( $pos=0; $pos<$this->sideSize; $pos++ ){
			$this->setModule(6, $pos, $pos%2==0);
			$this->setModule($pos, 6, $pos%2==0);
		}
		$this->drawFindr(3, 3);
		$this->drawFindr($this->sideSize-4, 3);
		$this->drawFindr(3, $this->sideSize-4);
		$algList = self::ALGNLIST[$this->versNmbr];
		$lastPos = count($algList) - 1;
		foreach( $algList as $rowPos => $rowCtr ){
			foreach( $algList as $colPos => $colCtr ){
				// Not over the finders
				if( ($rowPos==0 && $colPos==0) || ($rowPos==0 && $colPos==$lastPos) || ($rowPos==$lastPos && $colPos==0) ){
					continue;
				}
				$this->drawAlign($colCtr, $rowCtr);
			}
		}
		$this->drawFormt(0);
		$this->drawVersn();
	}

	/**
	 * Draws a finder pattern and its light separator.
	 *
	 * @param Int $centreColm The column of its centre.
	 * @param Int $centreRowN The row of its centre.
	 */
	protected function drawFindr( Int $centreColm, Int $centreRowN ): Void {
		for( $rdt=-4; $rdt<=4; $rdt++ ){
			for( $cdt=-4; $cdt<=4; $cdt++ ){
				$colInd = $centreColm + $cdt;
				$rowInd = $centreRowN + $rdt;
				if( $colInd>=0 && $colInd<$this->sideSize && $rowInd>=0 && $rowInd<$this->sideSize ){
					$distNb = max(abs($cdt), abs($rdt));
					$this->setModule($colInd, $rowInd, $distNb!=2 && $distNb!=4);
				}
			}
		}
	}

	/**
	 * Draws an alignment pattern.
	 *
	 * @param Int $centreColm The column of its centre.
	 * @param Int $centreRowN The row of its centre.
	 */
	protected function drawAlign( Int $centreColm, Int $centreRowN ): Void {
		for( $rdt=-2; $rdt<=2; $rdt++ ){
			for( $cdt=-2; $cdt<=2; $cdt++ ){
				$this->setModule($centreColm+$cdt, $centreRowN+$rdt, max(abs($cdt), abs($rdt))!=1);
			}
		}
	}

	/**
	 * Draws the two copies of the format: the level and the mask, with
	 * their BCH error correction.
	 *
	 * @param Int $maskNumber The mask, 0 to 7.
	 */
	protected function drawFormt( Int $maskNumber ): Void {
		$fmtData = (self::ECCLEVEL[$this->eccLevel]<<3) | $maskNumber;
		$remVal = $fmtData;
		for( $bit=0; $bit<10; $bit++ ){
			$remVal = ($remVal<<1) ^ (($remVal>>9)*0x537);
		}
		$fmtBits = (($fmtData<<10) | $remVal) ^ 0x5412;
		$sideNb = $this->sideSize;
		// Around the finder of the top left corner
		for( $bit=0; $bit<=5; $bit++ ){
			$this->setModule(8, $bit, (($fmtBits>>$bit) & 1)==1);
		}
		$this->setModule(8, 7, (($fmtBits>>6) & 1)==1);
		$this->setModule(8, 8, (($fmtBits>>7) & 1)==1);
		$this->setModule(7, 8, (($fmtBits>>8) & 1)==1);
		for( $bit=9; $bit<15; $bit++ ){
			$this->setModule(14-$bit, 8, (($fmtBits>>$bit) & 1)==1);
		}
		// Along the two other finders
		for( $bit=0; $bit<8; $bit++ ){
			$this->setModule($sideNb-1-$bit, 8, (($fmtBits>>$bit) & 1)==1);
		}
		for( $bit=8; $bit<15; $bit++ ){
			$this->setModule(8, $sideNb-15+$bit, (($fmtBits>>$bit) & 1)==1);
		}
		// The module that is always dark
		$this->setModule(8, $sideNb-8, true);
	}

	/**
	 * Draws the two copies of the version, from version 7 on.
	 */
	protected function drawVersn(): Void {
		if( $this->versNmbr<7 ){
			return;
		}
		$remVal = $this->versNmbr;
		for( $bit=0; $bit<12; $bit++ ){
			$remVal = ($remVal<<1) ^ (($remVal>>11)*0x1F25);
		}
		$verBits = ($this->versNmbr<<12) | $remVal;
		for( $bit=0; $bit<18; $bit++ ){
			$isDark = (($verBits>>$bit) & 1)==1;
			$farPos = $this->sideSize - 11 + $bit%3;
			$nearPs = intdiv($bit, 3);
			$this->setModule($farPos, $nearPs, $isDark);
			$this->setModule($nearPs, $farPos, $isDark);
		}
	}

	/**
	 * Places the codewords: two columns at a time from the right, going up
	 * then down, around the fixed patterns. The modules left over stay light.
	 *
	 * @param Array $codeWordLs The codewords, in the order they are placed.
	 */
	protected function drawWords( Array $codeWordLs ): Void {
		$bitTotl = 8*count($codeWordLs);
		$bitIndx = 0;
		for( $rgt=$this->sideSize-1; $rgt>=1; $rgt-=2 ){
			// The vertical timing pattern is skipped
			if( $rgt==6 ){
				$rgt = 5;
			}
			$goingUp = (($rgt+1) & 2)==0;
			for( $vrt=0; $vrt<$this->sideSize; $vrt++ ){
				$rowInd = $goingUp ? $this->sideSize-1-$vrt : $vrt;
				for( $sid=0; $sid<2; $sid++ ){
					$colInd = $rgt - $sid;
					if( !$this->fixedMap[$rowInd][$colInd] && $bitIndx<$bitTotl ){
						$wordVl = $codeWordLs[$bitIndx>>3];
						$this->modulMap[$rowInd][$colInd] = (($wordVl>>(7-($bitIndx & 7))) & 1)==1;
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
	 * @param Int $maskNumber The mask, 0 to 7.
	 */
	protected function applyMask( Int $maskNumber ): Void {
		for( $row=0; $row<$this->sideSize; $row++ ){
			for( $col=0; $col<$this->sideSize; $col++ ){
				if( $this->fixedMap[$row][$col] ){
					continue;
				}
				switch( $maskNumber ){
					case 0: $doFlip = ($col+$row)%2==0; break;
					case 1: $doFlip = $row%2==0; break;
					case 2: $doFlip = $col%3==0; break;
					case 3: $doFlip = ($col+$row)%3==0; break;
					case 4: $doFlip = (intdiv($col, 3)+intdiv($row, 2))%2==0; break;
					case 5: $doFlip = ($col*$row)%2 + ($col*$row)%3==0; break;
					case 6: $doFlip = (($col*$row)%2 + ($col*$row)%3)%2==0; break;
					default: $doFlip = (($col+$row)%2 + ($col*$row)%3)%2==0;
				}
				if( $doFlip ){
					$this->modulMap[$row][$col] = !$this->modulMap[$row][$col];
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
	protected function maskPnlty(): Int {
		$penalty = 0;
		$sideNb = $this->sideSize;
		$darkNb = 0;
		for( $pos=0; $pos<$sideNb; $pos++ ){
			$penalty += $this->lineScore( $this->modulMap[$pos] );
			$penalty += $this->lineScore( array_column($this->modulMap, $pos) );
			$darkNb += count( array_filter($this->modulMap[$pos]) );
		}
		for( $row=0; $row<$sideNb-1; $row++ ){
			for( $col=0; $col<$sideNb-1; $col++ ){
				$modVal = $this->modulMap[$row][$col];
				if( $this->modulMap[$row][$col+1]===$modVal && $this->modulMap[$row+1][$col]===$modVal && $this->modulMap[$row+1][$col+1]===$modVal ){
					$penalty += 3;
				}
			}
		}
		$allMods = $sideNb*$sideNb;
		$penalty += 10*( intdiv(abs($darkNb*20 - $allMods*10) + $allMods - 1, $allMods) - 1 );
		return $penalty;
	}

	/**
	 * Scores one row or column: each run of five modules of one colour or
	 * more, and each pattern looking like a finder.
	 *
	 * @param Array $moduleLine The modules of the line, true for a dark one.
	 * @return Int The penalty of the line.
	 */
	protected function lineScore( Array $moduleLine ): Int {
		$lineTxt = implode('', array_map(function( Bool $darkModule ): String { return $darkModule ? '1' : '0'; }, $moduleLine));
		$linePts = 0;
		preg_match_all('/0{5,}|1{5,}/', $lineTxt, $runList);
		foreach( $runList[0] as $runTxt ){
			$linePts += 3 + strlen($runTxt) - 5;
		}
		$linePts += 40*preg_match_all('/(?=10111010000|00001011101)/', $lineTxt);
		return $linePts;
	}
}
