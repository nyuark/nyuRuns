<?php

namespace nyu\Tools;

class ClientReg extends Registry{

	/**
	 * Builds the client registry: what the server registry tells about
	 * the client (address, host, agent, system, device, language). The
	 * address is seen through the reverse proxies the config trusts.
	 *
	 * @param ServerReg $serverVals Registry of server values to derive client info from.
	 * @param Bool      $lowCaseKey Whether to store keys in lowercase instead of uppercase.
	 * @param Array     $proxyConfg Reverse proxy settings: the 'list' of trusted proxy addresses or CIDR ranges, and the
	 *                              'head' name of the header in which they give the client address (see Loader::prxConfig).
	 */
	function __construct( ServerReg $serverVals, Bool $lowCaseKey = true, Array $proxyConfg = Array() ){
		parent::__construct( $this->clientSet($serverVals, $proxyConfg), $lowCaseKey );
	}

	/**
	 * Reads the client values off the server registry. The address is the
	 * one of the connection, unless that connection comes from a trusted
	 * proxy: the client is then the one the proxy tells.
	 *
	 * @param ServerReg $serverVals Registry of server values.
	 * @param Array     $proxyConfg Reverse proxy settings ('list', 'head').
	 * @return Array The client values, by name.
	 */
	protected function clientSet( ServerReg $serverVals, Array $proxyConfg = Array() ): Array {
		$agntStr = strval($serverVals->readValue('http_user_agent'));
		$peerStr = strval($serverVals->readValue('remote_addr'));
		$addrStr = $this->proxyAddr($serverVals, $proxyConfg);
		$hostStr = strval($serverVals->readValue('remote_host'));
		return Array(
			'userAddr'=>$addrStr,
			'peerAddr'=>$peerStr,
			// Through a proxy, the host name the server tells is the proxy's
			'userHost'=>($hostStr!='' && $addrStr==$peerStr) ? $hostStr : $addrStr,
			'usrAgent'=>$agntStr,
			'clSystem'=>$this->findSystm($agntStr),
			'clDevice'=>$this->findDevce($agntStr),
			'language'=>$this->readLangs( strval($serverVals->readValue('http_accept_language')) ),
			'humanSys'=>$this->findHuman($agntStr)
		);
	}

	/**
	 * Finds the address of the client. The forwarding header is only read
	 * when the connection comes from a trusted proxy, since any client can
	 * send that header. Its addresses are read from the nearest proxy back
	 * to the client: the first one that is not a trusted proxy is the client.
	 *
	 * @param ServerReg $serverVals Registry of server values.
	 * @param Array     $proxyConfg Reverse proxy settings ('list', 'head').
	 * @return String The address of the client; the one of the connection when no trusted proxy stands in front.
	 */
	protected function proxyAddr( ServerReg $serverVals, Array $proxyConfg ): String {
		$peerStr = strval($serverVals->readValue('remote_addr'));
		$trstLst = $proxyConfg['list'] ?? Array();
		if( empty($trstLst) || !$this->isTrusted($peerStr, $trstLst) ){
			return $peerStr;
		}
		// The header as the server registry names it : X-Forwarded-For is http_x_forwarded_for
		$headKey = 'http_'.str_replace('-', '_', strtolower(strval($proxyConfg['head'] ?? 'X-Forwarded-For')));
		$fwdList = array_reverse( array_map('trim', explode(',', strval($serverVals->readValue($headKey)))) );
		$addrStr = $peerStr;
		foreach( $fwdList as $fwdAdr ){
			// What is not an address ends the reading : the last address found is kept
			if( filter_var($fwdAdr, FILTER_VALIDATE_IP)===false ){
				break;
			}
			$addrStr = $fwdAdr;
			if( !$this->isTrusted($fwdAdr, $trstLst) ){
				break;
			}
		}
		return $addrStr;
	}

	/**
	 * Tells whether an address is one of the trusted proxies.
	 *
	 * @param String $addrString The address to look for.
	 * @param Array  $trustdList Addresses or CIDR ranges of the trusted proxies.
	 * @return Bool True when the address is listed, or within a listed range.
	 */
	protected function isTrusted( String $addrString, Array $trustdList ): Bool {
		foreach( $trustdList as $trsNet ){
			if( $this->addrInNet($addrString, strval($trsNet)) ){
				return true;
			}
		}
		return false;
	}

	/**
	 * Tells whether an address is within a range, IPv4 or IPv6. A range
	 * written without its bits ('10.0.0.5') is that single address.
	 *
	 * @param String $addrString The address to look for.
	 * @param String $subnetText The range, as an address or in CIDR form ('192.168.0.0/24', 'fd00::/8').
	 * @return Bool True when the address is within the range; false when either is not valid, or not of the same family.
	 */
	protected function addrInNet( String $addrString, String $subnetText ): Bool {
		$netPart = explode('/', trim($subnetText), 2);
		if( filter_var($addrString, FILTER_VALIDATE_IP)===false || filter_var($netPart[0], FILTER_VALIDATE_IP)===false ){
			return false;
		}
		$addrBin = inet_pton($addrString);
		$subnBin = inet_pton($netPart[0]);
		if( strlen($addrBin)!=strlen($subnBin) ){
			return false;
		}
		$maxBits = strlen($addrBin)*8;
		$bitsNum = $maxBits;
		if( isset($netPart[1]) ){
			if( !preg_match('/^\d+$/', $netPart[1]) || intval($netPart[1])>$maxBits ){
				return false;
			}
			$bitsNum = intval($netPart[1]);
		}
		// Whole bytes first, then the bits left of the next one
		$byteNum = intdiv($bitsNum, 8);
		$restNum = $bitsNum % 8;
		if( substr($addrBin, 0, $byteNum)!==substr($subnBin, 0, $byteNum) ){
			return false;
		}
		if( $restNum==0 ){
			return true;
		}
		$maskNum = (0xFF << (8-$restNum)) & 0xFF;
		return (ord($addrBin[$byteNum]) & $maskNum)==(ord($subnBin[$byteNum]) & $maskNum);
	}

	/**
	 * Names the operating system an agent string tells.
	 *
	 * @param String $agentStrng The User-Agent of the request.
	 * @return String The system, or an empty string when none is recognised.
	 */
	protected function findSystm( String $agentStrng ): String {
		// Order matters : Android agents also name Linux, iPhone ones also name Mac OS X
		$sysList = Array('Windows'=>'Windows', 'Android'=>'Android', 'iOS'=>'iPhone|iPad|iPod', 'macOS'=>'Macintosh|Mac OS X', 'ChromeOS'=>'CrOS', 'Linux'=>'Linux');
		foreach( $sysList as $sysNme => $sysPat ){
			if( preg_match('/'.$sysPat.'/i', $agentStrng) ){
				return $sysNme;
			}
		}
		return '';
	}

	/**
	 * Names the kind of client an agent string tells: a person's browser
	 * ('dsktop', 'mobile', 'tablet'), an AI crawler or agent ('aiagnt'),
	 * a search or indexing robot ('crawlr'), a scripted client or HTTP
	 * library ('script'), or a client that sent no agent ('shadow').
	 * Only a client that names itself is told apart: an agent driving a
	 * real browser reads as that browser.
	 *
	 * @param String $agentStrng The User-Agent of the request.
	 * @return String The six-character code of the kind of client.
	 */
	protected function findDevce( String $agentStrng ): String {
		if( $agentStrng=='' ){
			return 'shadow';
		}
		// Names the AI crawlers and agents give themselves, to be completed as new ones appear
		$aiNames = Array('GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'PerplexityBot', 'Perplexity-User', 'Google-Extended', 'CCBot', 'Bytespider', 'Amazonbot', 'meta-externalagent', 'cohere-ai', 'MistralAI-User', 'DuckAssistBot', 'YouBot', 'Diffbot');
		$aiPattn = implode('|', array_map('preg_quote', $aiNames));
		// Order matters : most AI names also read as robots
		if( preg_match('/'.$aiPattn.'/i', $agentStrng) ){
			return 'aiagnt';
		}
		if( preg_match('/bot|crawl|spider|slurp/i', $agentStrng) ){
			return 'crawlr';
		}
		if( preg_match('/curl|wget|python|java\/|okhttp|go-http|libwww|httpclient|axios|node-fetch|guzzle|postman|insomnia|HeadlessChrome/i', $agentStrng) ){
			return 'script';
		}
		// An Android agent without "Mobile" is a tablet
		if( preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $agentStrng) ){
			return 'tablet';
		}
		if( preg_match('/Mobi|iPhone|iPod|Android/i', $agentStrng) ){
			return 'mobile';
		}
		return 'dsktop';
	}

	/**
	 * Tells whether an agent string looks like a person's browser rather
	 * than a robot, an AI agent or a scripted client. The agent is sent by
	 * the client: this is a hint, never a proof.
	 *
	 * @param String $agentStrng The User-Agent of the request.
	 * @return Bool True for a desktop, mobile or tablet browser.
	 */
	protected function findHuman( String $agentStrng ): Bool {
		return in_array( $this->findDevce($agentStrng), Array('dsktop', 'mobile', 'tablet'), true );
	}

	/**
	 * Reads the client's first language off an Accept-Language header.
	 *
	 * @param String $acceptLang The Accept-Language of the request.
	 * @return String The language tag in lower case ('fr-ch'), or an empty string.
	 */
	protected function readLangs( String $acceptLang ): String {
		$langStr = trim( strval(strtok($acceptLang, ',;')) );
		return strtolower($langStr);
	}
}
