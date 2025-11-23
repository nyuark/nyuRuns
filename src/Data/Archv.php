<?php

namespace nyu\Data;

/**
 * A zip archive, read entry by entry: nothing is ever written to disk
 * from what it holds. This is how the office documents and the books
 * that are zip files are opened. An archive is also made from its
 * entries, to write them.
 */
class Archv {

	protected $zipHndlr;
	protected $srceFlux;
	protected $nameList = Array();

	/**
	 * Private: an archive is opened through openFluxd().
	 *
	 * @param \ZipArchive $zipArchive The open archive.
	 * @param Fluxd       $sourceFlux The source it was opened from, kept as long as the archive is.
	 * @param Array       $entryNames The entries it holds, name => size once unpacked.
	 */
	private function __construct(\ZipArchive $zipArchive, Fluxd $sourceFlux, Array $entryNames){
		$this->zipHndlr = $zipArchive;
		$this->srceFlux = $sourceFlux;
		$this->nameList = $entryNames;
	}

	/**
	 * Releases the archive when the object is no longer used.
	 */
	public function __destruct(){
		$this->closeArch();
	}

	/**
	 * Opens a zip archive from a source. A source that is not a file of
	 * the server is copied to a temporary one first, deleted when the
	 * source is closed.
	 *
	 * @param Fluxd $sourceFlux The source.
	 * @param Int   $entriesMax Greatest number of entries accepted.
	 * @return Archv The opened archive.
	 *
	 * @throws \RuntimeException When the source is not a zip archive, holds too many entries, or holds an entry whose name leaves the archive.
	 */
	public static function openFluxd(Fluxd $sourceFlux, Int $entriesMax=10000): Archv {
		if( !class_exists('ZipArchive') ){
			throw new \RuntimeException('Archives need the zip extension of PHP');
		}
		$zipArch = new \ZipArchive();
		if( $zipArch->open($sourceFlux->saveLocal(), \ZipArchive::RDONLY)!==true ){
			throw new \RuntimeException('Not a zip archive');
		}
		if( $zipArch->numFiles>$entriesMax ){
			$zipArch->close();
			throw new \RuntimeException('Archive holding more than the '.$entriesMax.' entries allowed');
		}
		$nameLst = Array();
		for($entIdx=0; $entIdx<$zipArch->numFiles; $entIdx++){
			$entStat = $zipArch->statIndex($entIdx);
			$entName = strval($entStat['name'] ?? '');
			// A name that would leave the archive is never a mistake
			if( $entName==='' || $entName[0]=='/' || strpos($entName, '\\')!==false || strpos($entName, ':')!==false || in_array('..', explode('/', $entName), true) ){
				$zipArch->close();
				throw new \RuntimeException('Archive holding an entry whose name is not safe');
			}
			// Folders are not entries to read
			if( substr($entName, -1)!='/' ){
				$nameLst[$entName] = intval($entStat['size'] ?? 0);
			}
		}
		return new Archv($zipArch, $sourceFlux, $nameLst);
	}

	/**
	 * Makes a zip archive from its entries, without the zip extension of
	 * PHP: this is how the office documents and the books are written.
	 * The entries are packed when PHP can pack them, kept as they are
	 * otherwise.
	 *
	 * @param Array $entriesArr The entries, name with its folders => content, in the order they are written.
	 * @param Array $plainNames Names of the entries never packed, as the first entry of a book must be.
	 * @return String The bytes of the archive.
	 *
	 * @throws \RuntimeException When a name is not safe, or the archive would be larger than a plain zip can be.
	 */
	public static function makeBytes(Array $entriesArr, Array $plainNames=Array()): String {
		$bodyTxt = '';
		$listTxt = '';
		// The date of the entries, as archives write it: two seconds at a time, from 1980
		$timeArr = getdate();
		$timeVal = ($timeArr['hours']<<11) | ($timeArr['minutes']<<5) | intdiv($timeArr['seconds'], 2);
		$dateVal = (max(0, $timeArr['year']-1980)<<9) | ($timeArr['mon']<<5) | $timeArr['mday'];
		foreach($entriesArr as $entNme=>$entTxt){
			$entNme = strval($entNme);
			$entTxt = strval($entTxt);
			if( $entNme==='' || $entNme[0]=='/' || strpos($entNme, '\\')!==false || strpos($entNme, ':')!==false || in_array('..', explode('/', $entNme), true) || strlen($entNme)>65535 ){
				throw new \RuntimeException('Archive given an entry whose name is not safe');
			}
			$packTxt = (function_exists('gzdeflate') && $entTxt!=='' && !in_array($entNme, $plainNames, true)) ? gzdeflate($entTxt, 6) : false;
			$isPackd = ($packTxt!==false && strlen($packTxt)<strlen($entTxt));
			$dataTxt = $isPackd ? $packTxt : $entTxt;
			if( strlen($bodyTxt)+strlen($dataTxt)>0x7FFFFFFF || count($entriesArr)>65535 ){
				throw new \RuntimeException('Archive too large to be written');
			}
			// Names are written in UTF-8, and say so
			$headTxt = pack('vvvvvVVVvv', 20, 0x0800, $isPackd ? 8 : 0, $timeVal, $dateVal, crc32($entTxt), strlen($dataTxt), strlen($entTxt), strlen($entNme), 0);
			$listTxt .= "PK\x01\x02".pack('v', 20).$headTxt.pack('vvvVV', 0, 0, 0, 0, strlen($bodyTxt)).$entNme;
			$bodyTxt .= "PK\x03\x04".$headTxt.$entNme.$dataTxt;
		}
		return $bodyTxt.$listTxt."PK\x05\x06".pack('vvvvVVv', 0, 0, count($entriesArr), count($entriesArr), strlen($listTxt), strlen($bodyTxt), 0);
	}
	/**
	 * Gives the open archive, or refuses one that was closed.
	 *
	 * @return \ZipArchive The open archive.
	 *
	 * @throws \RuntimeException When the archive is closed.
	 */
	protected function checkOpen(): \ZipArchive {
		if( $this->zipHndlr===null ){
			throw new \RuntimeException('The archive is closed');
		}
		return $this->zipHndlr;
	}

	/**
	 * Lists the entries of the archive, folders left out.
	 *
	 * @return Array The entries, name => size in bytes once unpacked, in the order of the archive.
	 *
	 * @throws \RuntimeException When the archive is closed.
	 */
	public function entryList(): Array {
		$this->checkOpen();
		return $this->nameList;
	}

	/**
	 * Tells whether the archive holds an entry.
	 *
	 * @param String $insideName Name of the entry, with its folders, as 'word/document.xml'.
	 * @return Bool True when it does.
	 *
	 * @throws \RuntimeException When the archive is closed.
	 */
	public function findEntry(String $insideName): Bool {
		$this->checkOpen();
		return isset($this->nameList[$insideName]);
	}

	/**
	 * Reads an entry of the archive. With a limit, an entry larger than
	 * it once unpacked is refused instead of being loaded, whatever size
	 * the archive announces for it.
	 *
	 * @param String $insideName Name of the entry, with its folders.
	 * @param ?Int   $limitBytes Greatest size accepted once unpacked, null for no limit.
	 * @return String The content of the entry.
	 *
	 * @throws \RuntimeException When the entry is missing, protected by a password, larger than the limit, or cannot be read.
	 */
	public function readEntry(String $insideName, ?Int $limitBytes=null): String {
		$zipArch = $this->checkOpen();
		if( !isset($this->nameList[$insideName]) ){
			throw new \RuntimeException('Entry not found in the archive: '.$insideName);
		}
		if( $limitBytes!==null && $this->nameList[$insideName]>$limitBytes ){
			throw new \RuntimeException('Entry larger than the '.$limitBytes.' bytes allowed: '.$insideName);
		}
		$entStat = $zipArch->statName($insideName);
		if( intval($entStat['encryption_method'] ?? 0)!==0 ){
			throw new \RuntimeException('Entry protected by a password: '.$insideName);
		}
		$entStrm = @$zipArch->getStream($insideName);
		if( $entStrm===false ){
			throw new \RuntimeException('Entry that cannot be read: '.$insideName);
		}
		// The size an archive announces can be false: the reading itself stops one byte past the limit
		$entText = '';
		while( !feof($entStrm) && ($limitBytes===null || strlen($entText)<=$limitBytes) ){
			$chnkTxt = fread($entStrm, 65536);
			if( $chnkTxt===false || $chnkTxt==='' ){
				break;
			}
			$entText .= $chnkTxt;
		}
		fclose($entStrm);
		if( $limitBytes!==null && strlen($entText)>$limitBytes ){
			throw new \RuntimeException('Entry larger than the '.$limitBytes.' bytes allowed: '.$insideName);
		}
		return $entText;
	}

	/**
	 * Releases the archive. Closing an archive twice does nothing. The
	 * source it was opened from is left open: it belongs to whoever
	 * opened it.
	 */
	public function closeArch(): Void {
		if( $this->zipHndlr!==null ){
			@$this->zipHndlr->close();
		}
		$this->zipHndlr = null;
		$this->srceFlux = null;
		$this->nameList = Array();
	}

}
