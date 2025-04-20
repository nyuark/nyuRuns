<?php

// Client registry : the values read off the server registry (address, host,
// agent, system, device, language, human or not), and how a request gives them.

require __DIR__.'/../lib/harness.php';

use nyu\Tools\ClientReg;

/**
 * Reads the client values an agent string yields.
 *
 * @param String $agentStrng The User-Agent of the request.
 * @return Array The system, the device, and whether the client looks human.
 */
function agentVals( String $agentStrng ): Array {
	$clntReg = new ClientReg( fakeServr(Array('HTTP_USER_AGENT'=>$agentStrng)) );
	return Array($clntReg->readValue('clSystem'), $clntReg->readValue('clDevice'), $clntReg->readValue('humanSys'));
}

// Address, host and language
$clntReg = new ClientReg( fakeServr(Array('REMOTE_ADDR'=>'203.0.113.7', 'HTTP_ACCEPT_LANGUAGE'=>'fr-CH, fr;q=0.9, en;q=0.8', 'HTTP_X_FORWARDED_FOR'=>'198.51.100.1')) );
checkSame('the address is the one of the connection, whatever a forwarding header says', $clntReg->readValue('userAddr'), '203.0.113.7');
checkSame('without a host name, the host is the address', $clntReg->readValue('userHost'), '203.0.113.7');
checkSame('the language is the first one accepted, in lower case', $clntReg->readValue('language'), 'fr-ch');
$clntReg = new ClientReg( fakeServr(Array('REMOTE_ADDR'=>'203.0.113.7', 'REMOTE_HOST'=>'client.example.org', 'HTTP_ACCEPT_LANGUAGE'=>'en;q=0.8')) );
checkSame('the host name is kept when the server tells one', $clntReg->readValue('userHost'), 'client.example.org');
checkSame('a single language with a weight', $clntReg->readValue('language'), 'en');

// System, device and human or not, by agent
$winAgnt = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
$macAgnt = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15';
$linAgnt = 'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0';
$crsAgnt = 'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
$iphAgnt = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
$ipdAgnt = 'Mozilla/5.0 (iPad; CPU OS 16_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1';
$andAgnt = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36';
$tabAgnt = 'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
$botAgnt = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

$gptAgnt = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)';
$pplAgnt = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)';
$hdlAgnt = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/126.0.0.0 Safari/537.36';

checkSame('a Windows browser',     agentVals($winAgnt), Array('Windows', 'dsktop', true));
checkSame('a Mac browser',         agentVals($macAgnt), Array('macOS', 'dsktop', true));
checkSame('a Linux browser',       agentVals($linAgnt), Array('Linux', 'dsktop', true));
checkSame('a ChromeOS browser',    agentVals($crsAgnt), Array('ChromeOS', 'dsktop', true));
checkSame('an iPhone is iOS, not macOS',          agentVals($iphAgnt), Array('iOS', 'mobile', true));
checkSame('an iPad is a tablet',                  agentVals($ipdAgnt), Array('iOS', 'tablet', true));
checkSame('an Android phone is Android, not Linux', agentVals($andAgnt), Array('Android', 'mobile', true));
checkSame('an Android agent without "Mobile" is a tablet', agentVals($tabAgnt), Array('Android', 'tablet', true));
checkSame('a search robot is a crawler, not human', agentVals($botAgnt), Array('', 'crawlr', false));
checkSame('an AI crawler is an AI agent, though its name reads as a robot', agentVals($gptAgnt), Array('', 'aiagnt', false));
checkSame('another AI crawler', agentVals($pplAgnt), Array('', 'aiagnt', false));
checkSame('an AI agent acting for a user', agentVals('Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot'), Array('', 'aiagnt', false));
checkSame('a scripted client is a script, not human', agentVals('curl/8.7.1'), Array('', 'script', false));
checkSame('an HTTP library is a script', agentVals('python-requests/2.32.3'), Array('', 'script', false));
checkSame('a headless browser is a script, whatever system it runs on', agentVals($hdlAgnt), Array('Linux', 'script', false));
checkSame('no agent : a shadow, not human', agentVals(''), Array('', 'shadow', false));
checkSame('every device code has six characters', array_unique(array_map(function( String $agentStrng ): Int { return strlen(agentVals($agentStrng)[1]); }, Array($winAgnt, $iphAgnt, $ipdAgnt, $botAgnt, $gptAgnt, 'curl/8.7.1', ''))), Array(6));

// Nothing told at all
$clntReg = new ClientReg( fakeServr() );
checkSame('an empty server registry gives empty values', Array($clntReg->readValue('userAddr'), $clntReg->readValue('userHost'), $clntReg->readValue('usrAgent'), $clntReg->readValue('language')), Array('', '', '', ''));
checkSame('only the client values are held, not the server ones', array_keys($clntReg->readLines()), Array('useraddr', 'peeraddr', 'userhost', 'userschm', 'usragent', 'clsystem', 'cldevice', 'language', 'humansys'));

// Reverse proxies : the forwarding header is only read from a trusted proxy
/**
 * Reads the addresses and the host a client registry gives behind proxies.
 *
 * @param Array $serverVals Server variable => value pairs.
 * @param Array $proxyConfg Reverse proxy settings ('list', 'head').
 * @return Array The client address, the connection address and the host.
 */
function proxyVals( Array $serverVals, Array $proxyConfg ): Array {
	$clntReg = new ClientReg( fakeServr($serverVals), true, $proxyConfg );
	return Array($clntReg->readValue('userAddr'), $clntReg->readValue('peerAddr'), $clntReg->readValue('userHost'));
}
$onePrxy = Array('list'=>Array('10.0.0.5'), 'head'=>'X-Forwarded-For');
checkSame('no proxy trusted : the header is ignored', proxyVals(Array('REMOTE_ADDR'=>'10.0.0.5', 'HTTP_X_FORWARDED_FOR'=>'198.51.100.1'), Array('list'=>Array(), 'head'=>'X-Forwarded-For')), Array('10.0.0.5', '10.0.0.5', '10.0.0.5'));
checkSame('a client connecting directly cannot give itself an address', proxyVals(Array('REMOTE_ADDR'=>'203.0.113.7', 'HTTP_X_FORWARDED_FOR'=>'198.51.100.1'), $onePrxy), Array('203.0.113.7', '203.0.113.7', '203.0.113.7'));
checkSame('through a trusted proxy, the client is the one it tells', proxyVals(Array('REMOTE_ADDR'=>'10.0.0.5', 'HTTP_X_FORWARDED_FOR'=>'198.51.100.1'), $onePrxy), Array('198.51.100.1', '10.0.0.5', '198.51.100.1'));
checkSame('the host name of the proxy is not the one of the client', proxyVals(Array('REMOTE_ADDR'=>'10.0.0.5', 'REMOTE_HOST'=>'balancer.local', 'HTTP_X_FORWARDED_FOR'=>'198.51.100.1'), $onePrxy), Array('198.51.100.1', '10.0.0.5', '198.51.100.1'));
checkSame('an address the client wrote before the proxy added its own is not believed', proxyVals(Array('REMOTE_ADDR'=>'10.0.0.5', 'HTTP_X_FORWARDED_FOR'=>'192.0.2.99, 198.51.100.1'), $onePrxy), Array('198.51.100.1', '10.0.0.5', '198.51.100.1'));
checkSame('a chain of two trusted proxies is read back to the client', proxyVals(Array('REMOTE_ADDR'=>'10.0.0.5', 'HTTP_X_FORWARDED_FOR'=>'198.51.100.1, 10.0.0.6'), Array('list'=>Array('10.0.0.5', '10.0.0.6'), 'head'=>'X-Forwarded-For')), Array('198.51.100.1', '10.0.0.5', '198.51.100.1'));
checkSame('a header naming only trusted proxies gives the farthest one', proxyVals(Array('REMOTE_ADDR'=>'10.0.0.5', 'HTTP_X_FORWARDED_FOR'=>'10.0.0.7, 10.0.0.6'), Array('list'=>Array('10.0.0.0/24'), 'head'=>'X-Forwarded-For')), Array('10.0.0.7', '10.0.0.5', '10.0.0.7'));
checkSame('a proxy within a trusted range', proxyVals(Array('REMOTE_ADDR'=>'192.168.3.40', 'HTTP_X_FORWARDED_FOR'=>'198.51.100.1'), Array('list'=>Array('192.168.0.0/22'), 'head'=>'X-Forwarded-For')), Array('198.51.100.1', '192.168.3.40', '198.51.100.1'));
checkSame('a proxy just outside a trusted range', proxyVals(Array('REMOTE_ADDR'=>'192.168.4.1', 'HTTP_X_FORWARDED_FOR'=>'198.51.100.1'), Array('list'=>Array('192.168.0.0/22'), 'head'=>'X-Forwarded-For')), Array('192.168.4.1', '192.168.4.1', '192.168.4.1'));
checkSame('IPv6 proxies and clients', proxyVals(Array('REMOTE_ADDR'=>'fd00::5', 'HTTP_X_FORWARDED_FOR'=>'2001:db8::7'), Array('list'=>Array('fd00::/8'), 'head'=>'X-Forwarded-For')), Array('2001:db8::7', 'fd00::5', '2001:db8::7'));
checkSame('an IPv4 range does not trust an IPv6 proxy', proxyVals(Array('REMOTE_ADDR'=>'fd00::5', 'HTTP_X_FORWARDED_FOR'=>'2001:db8::7'), Array('list'=>Array('10.0.0.0/8'), 'head'=>'X-Forwarded-For')), Array('fd00::5', 'fd00::5', 'fd00::5'));
checkSame('a proxy the server gives as an IPv4 address carried in IPv6 is matched by its IPv4 range', proxyVals(Array('REMOTE_ADDR'=>'::ffff:10.0.0.5', 'HTTP_X_FORWARDED_FOR'=>'198.51.100.1'), Array('list'=>Array('10.0.0.0/8'), 'head'=>'X-Forwarded-For')), Array('198.51.100.1', '::ffff:10.0.0.5', '198.51.100.1'));
checkSame('a proxy listed as an IPv4 address carried in IPv6 is the IPv4 one', proxyVals(Array('REMOTE_ADDR'=>'10.0.0.5', 'HTTP_X_FORWARDED_FOR'=>'198.51.100.1'), Array('list'=>Array('::ffff:10.0.0.5'), 'head'=>'X-Forwarded-For')), Array('198.51.100.1', '10.0.0.5', '198.51.100.1'));
checkSame('a client address carried in IPv6 is given as the IPv4 address it is', proxyVals(Array('REMOTE_ADDR'=>'10.0.0.5', 'HTTP_X_FORWARDED_FOR'=>'::ffff:198.51.100.1'), $onePrxy), Array('198.51.100.1', '10.0.0.5', '198.51.100.1'));
checkSame('a direct connection carried in IPv6 keeps its host name', proxyVals(Array('REMOTE_ADDR'=>'::ffff:203.0.113.7', 'REMOTE_HOST'=>'client.example.org'), $onePrxy), Array('203.0.113.7', '::ffff:203.0.113.7', 'client.example.org'));
checkSame('a real IPv6 address is left as it is', proxyVals(Array('REMOTE_ADDR'=>'2001:db8::7'), $onePrxy), Array('2001:db8::7', '2001:db8::7', '2001:db8::7'));
checkSame('what is not an address in the header leaves the connection address', proxyVals(Array('REMOTE_ADDR'=>'10.0.0.5', 'HTTP_X_FORWARDED_FOR'=>'198.51.100.1, unknown'), $onePrxy), Array('10.0.0.5', '10.0.0.5', '10.0.0.5'));
checkSame('a trusted proxy that sends no header leaves the connection address', proxyVals(Array('REMOTE_ADDR'=>'10.0.0.5'), $onePrxy), Array('10.0.0.5', '10.0.0.5', '10.0.0.5'));
checkSame('a range that is not valid trusts nothing', proxyVals(Array('REMOTE_ADDR'=>'10.0.0.5', 'HTTP_X_FORWARDED_FOR'=>'198.51.100.1'), Array('list'=>Array('10.0.0.0/40', 'proxy', '10.0.0.5/x'), 'head'=>'X-Forwarded-For')), Array('10.0.0.5', '10.0.0.5', '10.0.0.5'));
checkSame('the header the config names is the one read', proxyVals(Array('REMOTE_ADDR'=>'10.0.0.5', 'HTTP_X_FORWARDED_FOR'=>'192.0.2.99', 'HTTP_X_REAL_IP'=>'198.51.100.1'), Array('list'=>Array('10.0.0.5'), 'head'=>'X-Real-IP')), Array('198.51.100.1', '10.0.0.5', '198.51.100.1'));

// The scheme the client used : its header is only read from a trusted proxy too
/**
 * Reads the scheme a client registry gives behind proxies.
 *
 * @param Array $serverVals Server variable => value pairs.
 * @param Array $proxyConfg Reverse proxy settings ('list', 'schm').
 * @return String The scheme the client used.
 */
function readsSchm( Array $serverVals, Array $proxyConfg ): String {
	$clntReg = new ClientReg( fakeServr($serverVals), true, $proxyConfg );
	return strval($clntReg->readValue('userSchm'));
}
checkSame('no proxy trusted : the scheme is the one of the connection', readsSchm(Array('REMOTE_ADDR'=>'10.0.0.5', 'REQUEST_SCHEME'=>'http', 'HTTP_X_FORWARDED_PROTO'=>'https'), Array('list'=>Array())), 'http');
checkSame('a client connecting directly cannot give itself a scheme', readsSchm(Array('REMOTE_ADDR'=>'203.0.113.7', 'REQUEST_SCHEME'=>'http', 'HTTP_X_FORWARDED_PROTO'=>'https'), $onePrxy), 'http');
checkSame('through a trusted proxy, the scheme is the one it tells', readsSchm(Array('REMOTE_ADDR'=>'10.0.0.5', 'REQUEST_SCHEME'=>'http', 'HTTP_X_FORWARDED_PROTO'=>'https'), $onePrxy), 'https');
checkSame('the scheme a proxy tells is read whatever its case', readsSchm(Array('REMOTE_ADDR'=>'10.0.0.5', 'REQUEST_SCHEME'=>'http', 'HTTP_X_FORWARDED_PROTO'=>' HTTPS '), $onePrxy), 'https');
checkSame('a proxy given as an IPv4 address carried in IPv6 is trusted for the scheme too', readsSchm(Array('REMOTE_ADDR'=>'::ffff:10.0.0.5', 'REQUEST_SCHEME'=>'http', 'HTTP_X_FORWARDED_PROTO'=>'https'), $onePrxy), 'https');
checkSame('what is neither http nor https leaves the scheme of the connection', readsSchm(Array('REMOTE_ADDR'=>'10.0.0.5', 'REQUEST_SCHEME'=>'http', 'HTTP_X_FORWARDED_PROTO'=>'ftp'), $onePrxy), 'http');
checkSame('a list of schemes leaves the scheme of the connection', readsSchm(Array('REMOTE_ADDR'=>'10.0.0.5', 'REQUEST_SCHEME'=>'http', 'HTTP_X_FORWARDED_PROTO'=>'https, http'), $onePrxy), 'http');
checkSame('a trusted proxy that sends no header leaves the scheme of the connection', readsSchm(Array('REMOTE_ADDR'=>'10.0.0.5', 'REQUEST_SCHEME'=>'https'), $onePrxy), 'https');
checkSame('the scheme header the config names is the one read', readsSchm(Array('REMOTE_ADDR'=>'10.0.0.5', 'REQUEST_SCHEME'=>'http', 'HTTP_X_FORWARDED_PROTO'=>'http', 'HTTP_X_SCHEME'=>'https'), Array('list'=>Array('10.0.0.5'), 'schm'=>'X-Scheme')), 'https');

// The settings, as the loader reads them from the SYSTEM config
$loadObj = (new ReflectionClass('nyu\Setup\Loader'))->newInstanceWithoutConstructor();
$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array('system'=>Array('PRX_LIST'=>' 10.0.0.5, 192.168.0.0/24 ,, fd00::/8', 'PRX_HEAD'=>'X-Real-IP', 'PRX_SCHM'=>'X-Scheme')) );
checkSame('the proxies are listed from the config, the headers named', $loadObj->proxyConf(), Array('list'=>Array('10.0.0.5', '192.168.0.0/24', 'fd00::/8'), 'head'=>'X-Real-IP', 'schm'=>'X-Scheme'));
$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array('system'=>Array('PRX_LIST'=>'')) );
checkSame('without settings, no proxy is trusted and the usual headers are named', $loadObj->proxyConf(), Array('list'=>Array(), 'head'=>'X-Forwarded-For', 'schm'=>'X-Forwarded-Proto'));

// Through the request
$request = fakeReqst( Array(), Array('REMOTE_ADDR'=>'203.0.113.7', 'HTTP_USER_AGENT'=>$andAgnt) );
checkSame('a request gives its client registry', Array($request->getClient()->readValue('userAddr'), $request->getClient()->readValue('clDevice')), Array('203.0.113.7', 'mobile'));

// A request built in full writes its address with the scheme the client used
$servReg = fakeServr( Array('REQUEST_METHOD'=>'GET', 'REQUEST_URI'=>'/shows/index?page=2', 'SERVER_NAME'=>'api.example.org', 'REMOTE_ADDR'=>'10.0.0.5', 'REQUEST_SCHEME'=>'http', 'HTTP_X_FORWARDED_PROTO'=>'https') );
$request = new nyu\Tools\Request( $servReg, new nyu\Output\Response(), new ClientReg($servReg, true, $onePrxy) );
checkSame('behind a trusted proxy, the address of the request carries the scheme the client used', Array($request->getURLVal('htScheme'), $request->getURLVal('full_url')), Array('https', 'https://api.example.org/shows/index'));

endChecks();
