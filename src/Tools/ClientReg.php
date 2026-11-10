<?php

namespace nyu\Tools;

/**
 * Registry of what the request tells about its client: address, host,
 * scheme, agent, system, device and language. Everything but the addresses
 * is sent by the client: a hint, never to be used for an access decision.
 */
class ClientReg extends Registry{

	/**
	 * Builds the client registry: what the server registry tells about
	 * the client (address, host, scheme, agent, system, device, language).
	 * The address and the scheme are seen through the reverse proxies the
	 * config trusts.
	 *
	 * @param ServerReg $serverValues Registry of server values to derive client info from.
	 * @param Bool      $lowerCaseKey Whether to store keys in lowercase instead of uppercase.
	 * @param Array     $proxyConfArr Reverse proxy settings: the 'list' of trusted proxy addresses or CIDR ranges, the 'head'
	 *                              name of the header in which they give the client address, and the 'schm' name of the
	 *                              one in which they give the scheme the client used (see Loader::proxySetupConf).
	 */
	function __construct( ServerReg $serverValues, Bool $lowerCaseKey = true, Array $proxyConfArr = Array() ){
		parent::__construct( $this->clientSetArray($serverValues, $proxyConfArr), $lowerCaseKey );
	}

	/**
	 * Reads the client values off the server registry. The address is the
	 * one of the connection, unless that connection comes from a trusted
	 * proxy: the client is then the one the proxy tells. The same goes for
	 * the scheme.
	 *
	 * @param ServerReg $serverValues Registry of server values.
	 * @param Array     $proxyConfArr Reverse proxy settings ('list', 'head', 'schm').
	 * @return Array The client values, by name.
	 */
	protected function clientSetArray( ServerReg $serverValues, Array $proxyConfArr = Array() ): Array {
		$uaHeadr = strval($serverValues->readValueByKey('http_user_agent'));
		$peerAdr = strval($serverValues->readValueByKey('remote_addr'));
		$addrTxt = $this->findClientAddr($serverValues, $proxyConfArr);
		$hostTxt = strval($serverValues->readValueByKey('remote_host'));
		return Array(
			'userAddr'=>$addrTxt,
			'peerAddr'=>$peerAdr,
			// Through a proxy, the host name the server tells is the proxy's
			'userHost'=>($hostTxt!='' && $addrTxt==$this->unwrapIpv4Addr($peerAdr)) ? $hostTxt : $addrTxt,
			'userSchm'=>$this->proxySchemaTxt($serverValues, $proxyConfArr),
			'usrAgent'=>$uaHeadr,
			'clSystem'=>$this->findSystemText($uaHeadr),
			'clDevice'=>$this->clientKindText($uaHeadr),
			'language'=>$this->getLangsString( strval($serverValues->readValueByKey('http_accept_language')) ),
			'humanSys'=>$this->locateHumanFlg($uaHeadr)
		);
	}

	/**
	 * Finds the address of the client. The forwarding header is only read
	 * when the connection comes from a trusted proxy, since any client can
	 * send that header. Its addresses are read from the nearest proxy back
	 * to the client: the first one that is not a trusted proxy is the client.
	 *
	 * @param ServerReg $serverValues Registry of server values.
	 * @param Array     $proxyConfArr Reverse proxy settings ('list', 'head').
	 * @return String The address of the client; the one of the connection when no trusted proxy stands in front.
	 */
	protected function findClientAddr( ServerReg $serverValues, Array $proxyConfArr ): String {
		$peerAdr = $this->unwrapIpv4Addr( strval($serverValues->readValueByKey('remote_addr')) );
		$trstLst = $proxyConfArr['list'] ?? Array();
		if( empty($trstLst) || !$this->hasTrustedFlag($peerAdr, $trstLst) ){
			return $peerAdr;
		}
		// The header as the server registry names it : X-Forwarded-For is http_x_forwarded_for
		$headKey = 'http_'.str_replace('-', '_', strtolower(strval($proxyConfArr['head'] ?? 'X-Forwarded-For')));
		$fwdList = array_reverse( array_map('trim', explode(',', strval($serverValues->readValueByKey($headKey)))) );
		$addrTxt = $peerAdr;
		foreach( $fwdList as $fwdAdr ){
			// What is not an address ends the reading : the last address found is kept
			if( filter_var($fwdAdr, FILTER_VALIDATE_IP)===false ){
				break;
			}
			$addrTxt = $this->unwrapIpv4Addr($fwdAdr);
			if( !$this->hasTrustedFlag($addrTxt, $trstLst) ){
				break;
			}
		}
		return $addrTxt;
	}

	/**
	 * Finds the scheme the client used. Behind a trusted proxy, it is the one
	 * the proxy tells, when it tells http or https: the proxy may speak plain
	 * HTTP to this server while the client spoke HTTPS to it.
	 *
	 * @param ServerReg $serverValues Registry of server values.
	 * @param Array     $proxyConfArr Reverse proxy settings ('list', 'schm').
	 * @return String The scheme; the one of the connection when no trusted proxy tells another.
	 */
	protected function proxySchemaTxt( ServerReg $serverValues, Array $proxyConfArr ): String {
		$schmStr = strval($serverValues->readValueByKey('request_scheme'));
		$trstLst = $proxyConfArr['list'] ?? Array();
		if( empty($trstLst) || !$this->hasTrustedFlag(strval($serverValues->readValueByKey('remote_addr')), $trstLst) ){
			return $schmStr;
		}
		// The header as the server registry names it : X-Forwarded-Proto is http_x_forwarded_proto
		$headKey = 'http_'.str_replace('-', '_', strtolower(strval($proxyConfArr['schm'] ?? 'X-Forwarded-Proto')));
		$fwdSchm = strtolower(trim(strval($serverValues->readValueByKey($headKey))));
		return in_array($fwdSchm, Array('http', 'https'), true) ? $fwdSchm : $schmStr;
	}

	/**
	 * Tells whether an address is one of the trusted proxies.
	 *
	 * @param String $searchedAddr The address to look for.
	 * @param Array  $proxyAddrArr Addresses or CIDR ranges of the trusted proxies.
	 * @return Bool True when the address is listed, or within a listed range.
	 */
	protected function hasTrustedFlag( String $searchedAddr, Array $proxyAddrArr ): Bool {
		foreach( $proxyAddrArr as $trsNet ){
			if( $this->isAddrInSubnet($searchedAddr, strval($trsNet)) ){
				return true;
			}
		}
		return false;
	}

	/**
	 * Tells whether an address is within a range, IPv4 or IPv6. A range
	 * written without its bits ('10.0.0.5') is that single address.
	 *
	 * @param String $searchedAddr The address to look for.
	 * @param String $subnetString The range, as an address or in CIDR form ('192.168.0.0/24', 'fd00::/8').
	 * @return Bool True when the address is within the range; false when either is not valid, or not of the same family.
	 */
	protected function isAddrInSubnet( String $searchedAddr, String $subnetString ): Bool {
		$netPart = explode('/', trim($subnetString), 2);
		// An IPv4 address carried in an IPv6 one is matched as the IPv4 address it is
		$searchedAddr = $this->unwrapIpv4Addr($searchedAddr);
		if( !isset($netPart[1]) ){
			$netPart[0] = $this->unwrapIpv4Addr($netPart[0]);
		}
		if( filter_var($searchedAddr, FILTER_VALIDATE_IP)===false || filter_var($netPart[0], FILTER_VALIDATE_IP)===false ){
			return false;
		}
		$addrRaw = inet_pton($searchedAddr);
		$subnBin = inet_pton($netPart[0]);
		if( strlen($addrRaw)!=strlen($subnBin) ){
			return false;
		}
		$maxBits = strlen($addrRaw)*8;
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
		if( substr($addrRaw, 0, $byteNum)!==substr($subnBin, 0, $byteNum) ){
			return false;
		}
		if( $restNum==0 ){
			return true;
		}
		$maskNum = (0xFF << (8-$restNum)) & 0xFF;
		return (ord($addrRaw[$byteNum]) & $maskNum)==(ord($subnBin[$byteNum]) & $maskNum);
	}

	/**
	 * Writes an IPv4 address carried in an IPv6 one ('::ffff:10.0.0.5'), as
	 * a server listening on both families gives it, as the IPv4 address it is.
	 *
	 * @param String $searchedAddr The address.
	 * @return String The IPv4 address when it carries one; the address as given otherwise, valid or not.
	 */
	protected function unwrapIpv4Addr( String $searchedAddr ): String {
		if( filter_var($searchedAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)===false ){
			return $searchedAddr;
		}
		$addrRaw = inet_pton($searchedAddr);
		if( substr($addrRaw, 0, 12)!==str_repeat("\x00", 10)."\xFF\xFF" ){
			return $searchedAddr;
		}
		return strval(inet_ntop(substr($addrRaw, 12)));
	}

	/**
	 * Names the operating system an agent string tells.
	 *
	 * @param String $userAgentTxt The User-Agent of the request.
	 * @return String The system, or an empty string when none is recognised.
	 */
	protected function findSystemText( String $userAgentTxt ): String {
		// Order matters : Android agents also name Linux, iPhone ones also name Mac OS X
		$systems = Array('Windows'=>'Windows', 'Android'=>'Android', 'iOS'=>'iPhone|iPad|iPod', 'macOS'=>'Macintosh|Mac OS X', 'ChromeOS'=>'CrOS', 'Linux'=>'Linux');
		foreach( $systems as $osName => $osExpr ){
			if( preg_match('/'.$osExpr.'/i', $userAgentTxt) ){
				return $osName;
			}
		}
		return '';
	}

	/**
	 * Names the kind of client an agent string tells: a person's browser
	 * ('computer', 'handheld', 'tabletPc'), an AI crawler or agent ('llmAgent'),
	 * a search or indexing robot ('crawlers'), a scripted client or HTTP
	 * library ('scripted'), or a client that sent no agent ('shadowed').
	 * Only a client that names itself is told apart: an agent driving a
	 * real browser reads as that browser.
	 *
	 * @param String $userAgentTxt The User-Agent of the request.
	 * @return String The six-character code of the kind of client.
	 */
	protected function clientKindText( String $userAgentTxt ): String {
		if( $userAgentTxt=='' ){
			return 'shadowed';
		}
		// Names the AI crawlers and agents give themselves, to be completed as new ones appear
		$aiNames = Array('GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'PerplexityBot', 'Perplexity-User', 'Google-Extended', 'CCBot', 'Bytespider', 'Amazonbot', 'meta-externalagent', 'cohere-ai', 'MistralAI-User', 'DuckAssistBot', 'YouBot', 'Diffbot');
		$aiPttrn = implode('|', array_map('preg_quote', $aiNames));
		// Order matters : most AI names also read as robots
		if( preg_match('/'.$aiPttrn.'/i', $userAgentTxt) ){
			return 'llmAgent';
		}
		if( preg_match('/bot|crawl|spider|slurp/i', $userAgentTxt) ){
			return 'crawlers';
		}
		if( preg_match('/curl|wget|python|java\/|okhttp|go-http|libwww|httpclient|axios|node-fetch|guzzle|postman|insomnia|HeadlessChrome/i', $userAgentTxt) ){
			return 'scripted';
		}
		// An Android agent without "Mobile" is a tablet
		if( preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $userAgentTxt) ){
			return 'tabletPc';
		}
		if( preg_match('/Mobi|iPhone|iPod|Android/i', $userAgentTxt) ){
			return 'handheld';
		}
		return 'computer';
	}

	/**
	 * Tells whether an agent string looks like a person's browser rather
	 * than a robot, an AI agent or a scripted client. The agent is sent by
	 * the client: this is a hint, never a proof.
	 *
	 * @param String $userAgentTxt The User-Agent of the request.
	 * @return Bool True for a desktop, mobile or tablet browser.
	 */
	protected function locateHumanFlg( String $userAgentTxt ): Bool {
		return in_array( $this->clientKindText($userAgentTxt), Array('computer', 'handheld', 'tabletPc'), true );
	}

	/**
	 * Reads the client's first language off an Accept-Language header.
	 *
	 * @param String $acceptLngHdr The Accept-Language of the request.
	 * @return String The language tag in lower case ('fr-ch'), or an empty string.
	 */
	protected function getLangsString( String $acceptLngHdr ): String {
		$langTxt = trim( strval(strtok($acceptLngHdr, ',;')) );
		return strtolower($langTxt);
	}
}
