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
	 * @param Array     $proxyCfgList Reverse proxy settings: the 'list' of trusted proxy addresses or CIDR ranges, the 'head'
	 *                              name of the header in which they give the client address, and the 'schm' name of the
	 *                              one in which they give the scheme the client used (see Loader::findProxyConfg).
	 */
	function __construct( ServerReg $serverValues, Bool $lowerCaseKey = true, Array $proxyCfgList = Array() ){
		parent::__construct( $this->readClientVals($serverValues, $proxyCfgList), $lowerCaseKey );
	}

	/**
	 * Reads the client values off the server registry. The address is the
	 * one of the connection, unless that connection comes from a trusted
	 * proxy: the client is then the one the proxy tells. The same goes for
	 * the scheme.
	 *
	 * @param ServerReg $serverValues Registry of server values.
	 * @param Array     $proxyCfgList Reverse proxy settings ('list', 'head', 'schm').
	 * @return Array The client values, by name.
	 */
	protected function readClientVals( ServerReg $serverValues, Array $proxyCfgList = Array() ): Array {
		$agntHedr = strval($serverValues->readValueByKey('http_user_agent'));
		$peerAddr = strval($serverValues->readValueByKey('remote_addr'));
		$addrText = $this->findClientAddr($serverValues, $proxyCfgList);
		$hostText = strval($serverValues->readValueByKey('remote_host'));
		return Array(
			'userAddr'=>$addrText,
			'peerAddr'=>$peerAddr,
			// Through a proxy, the host name the server tells is the proxy's
			'userHost'=>($hostText!='' && $addrText==$this->unwrapIpv4Addr($peerAddr)) ? $hostText : $addrText,
			'userSchm'=>$this->findClntScheme($serverValues, $proxyCfgList),
			'usrAgent'=>$agntHedr,
			'clSystem'=>$this->findSystemText($agntHedr),
			'clDevice'=>$this->findClientCode($agntHedr),
			'language'=>$this->parseFirstLang( strval($serverValues->readValueByKey('http_accept_language')) ),
			'humanSys'=>$this->isHumanBrowser($agntHedr)
		);
	}

	/**
	 * Finds the address of the client. The forwarding header is only read
	 * when the connection comes from a trusted proxy, since any client can
	 * send that header. Its addresses are read from the nearest proxy back
	 * to the client: the first one that is not a trusted proxy is the client.
	 *
	 * @param ServerReg $serverValues Registry of server values.
	 * @param Array     $proxyCfgList Reverse proxy settings ('list', 'head').
	 * @return String The address of the client; the one of the connection when no trusted proxy stands in front.
	 */
	protected function findClientAddr( ServerReg $serverValues, Array $proxyCfgList ): String {
		$peerAddr = $this->unwrapIpv4Addr( strval($serverValues->readValueByKey('remote_addr')) );
		$trstList = $proxyCfgList['list'] ?? Array();
		if( empty($trstList) || !$this->isTrustedProxy($peerAddr, $trstList) ){
			return $peerAddr;
		}
		// The header as the server registry names it : X-Forwarded-For is http_x_forwarded_for
		$headClef = 'http_'.str_replace('-', '_', strtolower(strval($proxyCfgList['head'] ?? 'X-Forwarded-For')));
		$frwdList = array_reverse( array_map('trim', explode(',', strval($serverValues->readValueByKey($headClef)))) );
		$addrText = $peerAddr;
		foreach( $frwdList as $oneAdr ){
			// What is not an address ends the reading : the last address found is kept
			if( filter_var($oneAdr, FILTER_VALIDATE_IP)===false ){
				break;
			}
			$addrText = $this->unwrapIpv4Addr($oneAdr);
			if( !$this->isTrustedProxy($addrText, $trstList) ){
				break;
			}
		}
		return $addrText;
	}

	/**
	 * Finds the scheme the client used. Behind a trusted proxy, it is the one
	 * the proxy tells, when it tells http or https: the proxy may speak plain
	 * HTTP to this server while the client spoke HTTPS to it.
	 *
	 * @param ServerReg $serverValues Registry of server values.
	 * @param Array     $proxyCfgList Reverse proxy settings ('list', 'schm').
	 * @return String The scheme; the one of the connection when no trusted proxy tells another.
	 */
	protected function findClntScheme( ServerReg $serverValues, Array $proxyCfgList ): String {
		$scheText = strval($serverValues->readValueByKey('request_scheme'));
		$trstList = $proxyCfgList['list'] ?? Array();
		if( empty($trstList) || !$this->isTrustedProxy(strval($serverValues->readValueByKey('remote_addr')), $trstList) ){
			return $scheText;
		}
		// The header as the server registry names it : X-Forwarded-Proto is http_x_forwarded_proto
		$headClef = 'http_'.str_replace('-', '_', strtolower(strval($proxyCfgList['schm'] ?? 'X-Forwarded-Proto')));
		$frwdSche = strtolower(trim(strval($serverValues->readValueByKey($headClef))));
		return in_array($frwdSche, Array('http', 'https'), true) ? $frwdSche : $scheText;
	}

	/**
	 * Tells whether an address is one of the trusted proxies.
	 *
	 * @param String $searchedAddr The address to look for.
	 * @param Array  $proxyAdrList Addresses or CIDR ranges of the trusted proxies.
	 * @return Bool True when the address is listed, or within a listed range.
	 */
	protected function isTrustedProxy( String $searchedAddr, Array $proxyAdrList ): Bool {
		foreach( $proxyAdrList as $oneNet ){
			if( $this->isAddrInSubnet($searchedAddr, strval($oneNet)) ){
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
		$netParte = explode('/', trim($subnetString), 2);
		// An IPv4 address carried in an IPv6 one is matched as the IPv4 address it is
		$searchedAddr = $this->unwrapIpv4Addr($searchedAddr);
		if( !isset($netParte[1]) ){
			$netParte[0] = $this->unwrapIpv4Addr($netParte[0]);
		}
		if( filter_var($searchedAddr, FILTER_VALIDATE_IP)===false || filter_var($netParte[0], FILTER_VALIDATE_IP)===false ){
			return false;
		}
		$addrBrut = inet_pton($searchedAddr);
		$snetBnry = inet_pton($netParte[0]);
		if( strlen($addrBrut)!=strlen($snetBnry) ){
			return false;
		}
		$addrBits = strlen($addrBrut)*8;
		$bitsNumb = $addrBits;
		if( isset($netParte[1]) ){
			if( !preg_match('/^\d+$/', $netParte[1]) || intval($netParte[1])>$addrBits ){
				return false;
			}
			$bitsNumb = intval($netParte[1]);
		}
		// Whole bytes first, then the bits left of the next one
		$byteNumb = intdiv($bitsNumb, 8);
		$restNumb = $bitsNumb % 8;
		if( substr($addrBrut, 0, $byteNumb)!==substr($snetBnry, 0, $byteNumb) ){
			return false;
		}
		if( $restNumb==0 ){
			return true;
		}
		$maskNumb = (0xFF << (8-$restNumb)) & 0xFF;
		return (ord($addrBrut[$byteNumb]) & $maskNumb)==(ord($snetBnry[$byteNumb]) & $maskNumb);
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
		$addrBrut = inet_pton($searchedAddr);
		if( substr($addrBrut, 0, 12)!==str_repeat("\x00", 10)."\xFF\xFF" ){
			return $searchedAddr;
		}
		return strval(inet_ntop(substr($addrBrut, 12)));
	}

	/**
	 * Names the operating system an agent string tells.
	 *
	 * @param String $userAgentTxt The User-Agent of the request.
	 * @return String The system, or an empty string when none is recognised.
	 */
	protected function findSystemText( String $userAgentTxt ): String {
		// Order matters : Android agents also name Linux, iPhone ones also name Mac OS X
		$systDict = Array('Windows'=>'Windows', 'Android'=>'Android', 'iOS'=>'iPhone|iPad|iPod', 'macOS'=>'Macintosh|Mac OS X', 'ChromeOS'=>'CrOS', 'Linux'=>'Linux');
		foreach( $systDict as $oneNme => $oneRgx ){
			if( preg_match('/'.$oneRgx.'/i', $userAgentTxt) ){
				return $oneNme;
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
	protected function findClientCode( String $userAgentTxt ): String {
		if( $userAgentTxt=='' ){
			return 'shadowed';
		}
		// Names the AI crawlers and agents give themselves, to be completed as new ones appear
		$llmBrwsr = Array('GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'PerplexityBot', 'Perplexity-User', 'Google-Extended', 'CCBot', 'Bytespider', 'Amazonbot', 'meta-externalagent', 'cohere-ai', 'MistralAI-User', 'DuckAssistBot', 'YouBot', 'Diffbot');
		$llmPttrn = implode('|', array_map('preg_quote', $llmBrwsr));
		// Order matters : most AI names also read as robots
		if( preg_match('/'.$llmPttrn.'/i', $userAgentTxt) ){
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
	protected function isHumanBrowser( String $userAgentTxt ): Bool {
		return in_array( $this->findClientCode($userAgentTxt), Array('computer', 'handheld', 'tabletPc'), true );
	}

	/**
	 * Reads the client's first language off an Accept-Language header.
	 *
	 * @param String $langHeadrTxt The Accept-Language of the request.
	 * @return String The language tag in lower case ('fr-ch'), or an empty string.
	 */
	protected function parseFirstLang( String $langHeadrTxt ): String {
		$langText = trim( strval(strtok($langHeadrTxt, ',;')) );
		return strtolower($langText);
	}
}
