<?php

namespace nyu\Data;

/**
 * A zip archive, read entry by entry: nothing is ever written to disk
 * from what it holds. This is how the office documents and the books
 * that are zip files are opened. An archive is also made from its
 * entries, to write them.
 */
class Archv {

	protected $zipHandler;
	protected $sourceFlux;
	protected $entryNames = Array();

	/**
	 * Private: an archive is opened through openFluxdArchv().
	 *
	 * @param \ZipArchive $archiveInUse The open archive.
	 * @param Fluxd       $sourceStream The source it was opened from, kept as long as the archive is.
	 * @param Array       $itemNamesLst The entries it holds, name => size once unpacked.
	 */
	private function __construct(\ZipArchive $archiveInUse, Fluxd $sourceStream, Array $itemNamesLst){
		$this->zipHandler = $archiveInUse;
		$this->sourceFlux = $sourceStream;
		$this->entryNames = $itemNamesLst;
	}

	/**
	 * Releases the archive when the object is no longer used.
	 */
	public function __destruct(){
		$this->releaseArchive();
	}

	/**
	 * Opens a zip archive from a source. A source that is not a file of
	 * the server is copied to a temporary one first, deleted when the
	 * source is closed.
	 *
	 * @param Fluxd $sourceStream The source.
	 * @param Int   $entriesLimit Greatest number of entries accepted.
	 * @return Archv The opened archive.
	 *
	 * @throws \RuntimeException When the source is not a zip archive, holds too many entries, or holds an entry whose name leaves the archive.
	 */
	public static function openFluxdArchv(Fluxd $sourceStream, Int $entriesLimit=10000): Archv {
		if( !class_exists('ZipArchive') ){
			throw new \RuntimeException('Archives need the zip extension of PHP');
		}
		$archInst = new \ZipArchive();
		if( $archInst->open($sourceStream->setLocalString(), \ZipArchive::RDONLY)!==true ){
			throw new \RuntimeException('Not a zip archive');
		}
		if( $archInst->numFiles>$entriesLimit ){
			$archInst->close();
			throw new \RuntimeException('Archive holding more than the '.$entriesLimit.' entries allowed');
		}
		$nameList = Array();
		for($zipInc=0; $zipInc<$archInst->numFiles; $zipInc++){
			$zipEntry = $archInst->statIndex($zipInc);
			$itemName = strval($zipEntry['name'] ?? '');
			// A name that would leave the archive is never a mistake
			if( $itemName==='' || $itemName[0]=='/' || strpos($itemName, '\\')!==false || strpos($itemName, ':')!==false || in_array('..', explode('/', $itemName), true) ){
				$archInst->close();
				throw new \RuntimeException('Archive holding an entry whose name is not safe');
			}
			// Folders are not entries to read
			if( substr($itemName, -1)!='/' ){
				$nameList[$itemName] = intval($zipEntry['size'] ?? 0);
			}
		}
		return new Archv($archInst, $sourceStream, $nameList);
	}

	/**
	 * Makes a zip archive from its entries, without the zip extension of
	 * PHP: this is how the office documents and the books are written.
	 * The entries are packed when PHP can pack them, kept as they are
	 * otherwise.
	 *
	 * @param Array $entriesArray The entries, name with its folders => content, in the order they are written.
	 * @param Array $unpackedList Names of the entries never packed, as the first entry of a book must be.
	 * @return String The bytes of the archive.
	 *
	 * @throws \RuntimeException When a name is not safe, or the archive would be larger than a plain zip can be.
	 */
	public static function newBytesString(Array $entriesArray, Array $unpackedList=Array()): String {
		$bodyText = '';
		$listText = '';
		// The date of the entries, as archives write it: two seconds at a time, from 1980
		$timeList = getdate();
		$timeVals = ($timeList['hours']<<11) | ($timeList['minutes']<<5) | intdiv($timeList['seconds'], 2);
		$dateVals = (max(0, $timeList['year']-1980)<<9) | ($timeList['mon']<<5) | $timeList['mday'];
		foreach($entriesArray as $entNme=>$entTxt){
			$entNme = strval($entNme);
			$entTxt = strval($entTxt);
			if( $entNme==='' || $entNme[0]=='/' || strpos($entNme, '\\')!==false || strpos($entNme, ':')!==false || in_array('..', explode('/', $entNme), true) || strlen($entNme)>65535 ){
				throw new \RuntimeException('Archive given an entry whose name is not safe');
			}
			$packText = (function_exists('gzdeflate') && $entTxt!=='' && !in_array($entNme, $unpackedList, true)) ? gzdeflate($entTxt, 6) : false;
			$isPacked = ($packText!==false && strlen($packText)<strlen($entTxt));
			$dataText = $isPacked ? $packText : $entTxt;
			if( strlen($bodyText)+strlen($dataText)>0x7FFFFFFF || count($entriesArray)>65535 ){
				throw new \RuntimeException('Archive too large to be written');
			}
			// Names are written in UTF-8, and say so
			$headText = pack('vvvvvVVVvv', 20, 0x0800, $isPacked ? 8 : 0, $timeVals, $dateVals, crc32($entTxt), strlen($dataText), strlen($entTxt), strlen($entNme), 0);
			$listText .= "PK\x01\x02".pack('v', 20).$headText.pack('vvvVV', 0, 0, 0, 0, strlen($bodyText)).$entNme;
			$bodyText .= "PK\x03\x04".$headText.$entNme.$dataText;
		}
		return $bodyText.$listText."PK\x05\x06".pack('vvvvVVv', 0, 0, count($entriesArray), count($entriesArray), strlen($listText), strlen($bodyText), 0);
	}
	/**
	 * Gives the open archive, or refuses one that was closed.
	 *
	 * @return \ZipArchive The open archive.
	 *
	 * @throws \RuntimeException When the archive is closed.
	 */
	protected function getOpenArchive(): \ZipArchive {
		if( $this->zipHandler===null ){
			throw new \RuntimeException('The archive is closed');
		}
		return $this->zipHandler;
	}

	/**
	 * Lists the entries of the archive, folders left out.
	 *
	 * @return Array The entries, name => size in bytes once unpacked, in the order of the archive.
	 *
	 * @throws \RuntimeException When the archive is closed.
	 */
	public function listEntryNames(): Array {
		$this->getOpenArchive();
		return $this->entryNames;
	}

	/**
	 * Tells whether the archive holds an entry.
	 *
	 * @param String $fullPathName Name of the entry, with its folders, as 'word/document.xml'.
	 * @return Bool True when it does.
	 *
	 * @throws \RuntimeException When the archive is closed.
	 */
	public function locateEntryFlg(String $fullPathName): Bool {
		$this->getOpenArchive();
		return isset($this->entryNames[$fullPathName]);
	}

	/**
	 * Reads an entry of the archive. With a limit, an entry larger than
	 * it once unpacked is refused instead of being loaded, whatever size
	 * the archive announces for it.
	 *
	 * @param String $fullPathName Name of the entry, with its folders.
	 * @param ?Int   $ceilingBytes Greatest size accepted once unpacked, null for no limit.
	 * @return String The content of the entry.
	 *
	 * @throws \RuntimeException When the entry is missing, protected by a password, larger than the limit, or cannot be read.
	 */
	public function getEntryString(String $fullPathName, ?Int $ceilingBytes=null): String {
		$archInst = $this->getOpenArchive();
		if( !isset($this->entryNames[$fullPathName]) ){
			throw new \RuntimeException('Entry not found in the archive: '.$fullPathName);
		}
		if( $ceilingBytes!==null && $this->entryNames[$fullPathName]>$ceilingBytes ){
			throw new \RuntimeException('Entry larger than the '.$ceilingBytes.' bytes allowed: '.$fullPathName);
		}
		$zipEntry = $archInst->statName($fullPathName);
		if( intval($zipEntry['encryption_method'] ?? 0)!==0 ){
			throw new \RuntimeException('Entry protected by a password: '.$fullPathName);
		}
		$entrySrm = @$archInst->getStream($fullPathName);
		if( $entrySrm===false ){
			throw new \RuntimeException('Entry that cannot be read: '.$fullPathName);
		}
		// The size an archive announces can be false: the reading itself stops one byte past the limit
		$itemText = '';
		while( !feof($entrySrm) && ($ceilingBytes===null || strlen($itemText)<=$ceilingBytes) ){
			$chunkTxt = fread($entrySrm, 65536);
			if( $chunkTxt===false || $chunkTxt==='' ){
				break;
			}
			$itemText .= $chunkTxt;
		}
		fclose($entrySrm);
		if( $ceilingBytes!==null && strlen($itemText)>$ceilingBytes ){
			throw new \RuntimeException('Entry larger than the '.$ceilingBytes.' bytes allowed: '.$fullPathName);
		}
		return $itemText;
	}

	/**
	 * Releases the archive. Closing an archive twice does nothing. The
	 * source it was opened from is left open: it belongs to whoever
	 * opened it.
	 */
	public function releaseArchive(): Void {
		if( $this->zipHandler!==null ){
			@$this->zipHandler->close();
		}
		$this->zipHandler = null;
		$this->sourceFlux = null;
		$this->entryNames = Array();
	}

}
