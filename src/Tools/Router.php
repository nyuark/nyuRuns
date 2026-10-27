<?php

namespace nyu\Tools;

use nyu\Data\Dcmnt;
use nyu\Output\Response;

/**
 * Resolves the application, action and parameter to run for a request,
 * according to the 'route' block of the active preset.
 */
class Router{

	// Statuses a redirection can be sent with
	const MOVELIST = Array(301, 302, 303, 307, 308);
	// The keys a declared route may hold, and those of a target block
	const KEYSLIST = Array('allows', 'target');
	const TRGTKEYS = Array('app', 'act', 'url', 'cod', 'fle', 'dir', 'pge');

	protected $fromAuto = false;	// Whether the route was found from the path, by position
	protected $routeCnf;	// 'route' block of the active preset
	protected $httpRqst;	// The shared HTTP request
	protected $pathText;	// Request path, without its leading slash and query string
	protected $rteFiles;	// Declared routes, once read

	/**
	 * Prepares the router for a request: keeps the route configuration and
	 * extracts the path to resolve from the request URI.
	 *
	 * @param Array   $routeConfg The 'route' config block of the active preset.
	 * @param Request $requestObj The shared, already-parsed HTTP request.
	 */
	public function __construct( Array $routeConfg, Request $requestObj ){
		$this->routeCnf = $routeConfg;
		$this->httpRqst = $requestObj;
		$this->pathText = $this->cleanPath( strval($requestObj->getURLVal('reqstURI')) );
	}

	/**
	 * Resolves the route of the request: from the declared routes first (the
	 * entry point's manual routes, then the files of conf/routes), otherwise
	 * positionally from the URL path in autopath mode. The entry point's
	 * routes are only read in manually mode. A CORS preflight
	 * (an OPTIONS request carrying Access-Control-Request-Method) resolves
	 * to no application: it is answered with the preset's headers alone.
	 * A declared route can also answer without an application, with a
	 * redirection, a static file or an HTML page (see buildVals).
	 *
	 * @return Registry Registry holding the resolved 'app' and 'action' values, plus 'param' and any other mapped or captured key.
	 *
	 * @throws \RuntimeException When the route found is not properly declared.
	 */
	public function findRoute(): Registry {
		// A CORS preflight is answered by the route itself, on any path : no action runs
		if( $this->httpRqst->usedMethd('OPTIONS') && $this->httpRqst->getHeader('Access-Control-Request-Method')!==null ){
			return new Registry( Array('app'=>null, 'action'=>null, 'statCode'=>204, 'statMssg'=>'') );
		}
		$rteVals = Array(
			'app'=>null,
			'action'=>null
		);
		$dtcMode = $this->routeCnf['detect'] ?? '';

		// Declared routes come first : entry point file, then routes folder
		$urlPath = str_replace('.php', '', $this->pathText);
		$fndVals = ($dtcMode=='manually') ? $this->manuRoute($urlPath) : null;
		if( $fndVals===null ){
			$fndVals = $this->fileRoute($urlPath);
		}
		if( $fndVals!==null ){
			return new Registry( $fndVals );
		}

		// Automatic path detection (from url path)
		if( $dtcMode=='autopath' ){
			$rteVals = array_merge($rteVals, $this->autoRoute());
			$this->fromAuto = true;
		}
		return new Registry( $rteVals );
	}

	/**
	 * Tells whether an action found from the path is closed: a declared
	 * route limiting its methods ('allows', or one target per method)
	 * answers with it, so it is only reached through that route, with
	 * the methods the route allows. The names are compared whatever
	 * their case. An action reached through its declared route, or called
	 * without a route being looked for, is never closed.
	 *
	 * @param String $runAppName Name of the application.
	 * @param String $runAppActn Name of the action that would run.
	 * @param Bool   $ranDefault Whether that action is the one the application runs when none is named.
	 * @return Bool True when the action must not run.
	 */
	public function shutsActn( String $runAppName, String $runAppActn, Bool $ranDefault=false ): Bool {
		if( !$this->fromAuto ){
			return false;
		}
		foreach( $this->allRoutes() as $rteRow ){
			if( $rteRow['rteKind']!='action' || !$rteRow['rteLock'] || strcasecmp(strval($rteRow['app']), $runAppName)!=0 ){
				continue;
			}
			// A route naming no action answers with the default one
			if( strcasecmp(strval($rteRow['action']), $runAppActn)==0 || (strval($rteRow['action'])==='' && $ranDefault) ){
				return true;
			}
		}
		return false;
	}

	/**
	 * Logs, as warnings on the 'runner' channel, the keys of declared
	 * routes that nothing reads: a key left from another version, or
	 * misspelt, would otherwise be ignored without a word.
	 *
	 * @param Array  $routesList The routes of a file, by path.
	 * @param String $routesFile Name of the file, for the message.
	 */
	protected function warnsKeys( Array $routesList, String $routesFile ): Void {
		foreach( $routesList as $rteKey => $rteSet ){
			if( !is_array($rteSet) ){
				continue;
			}
			$badKeys = array_values( array_diff(array_map('strval', array_keys($rteSet)), self::KEYSLIST) );
			$tgtVals = $rteSet['target'] ?? null;
			if( is_array($tgtVals) ){
				// One block, or one per method
				$blkList = ($this->targtKind($tgtVals)!==null) ? Array($tgtVals) : array_filter($tgtVals, 'is_array');
				foreach( $blkList as $blkVal ){
					foreach( array_diff(array_map('strval', array_keys($blkVal)), self::TRGTKEYS) as $tgtKey ){
						$badKeys[] = 'target.'.$tgtKey;
					}
				}
			}
			foreach( array_unique($badKeys) as $badKey ){
				Logger::getLogger('runner')->warning( 'Route '.json_encode(strval($rteKey)).' of '.$routesFile.' holds an unknown key: '.$badKey );
			}
		}
	}

	/**
	 * Strips the leading slash and the query string from a request URI.
	 *
	 * @param String $requestURI The request URI, as received.
	 * @return String The path to resolve.
	 */
	protected function cleanPath( String $requestURI ): String {
		$quoMark = strpos($requestURI, '?');
		if( $quoMark!==false ){
			return substr($requestURI, 1, $quoMark-1);
		}
		return substr($requestURI, 1);
	}

	/**
	 * Maps the segments of the path onto the keys of the configured mapper
	 * (such as $app/$action/$param), by position.
	 *
	 * @return Array The mapper keys with their path segment, null when the path is shorter.
	 */
	protected function autoRoute(): Array {
		$urlPart = explode( '/', $this->pathText );
		$mapPath = str_replace( '$', '', $this->routeCnf['mapper'] ?? '' );
		$mapPart = explode( '/', $mapPath );
		$mapVals = Array();

		foreach( $mapPart as $mapIdx => $mapKey ){
			$mapVals[$mapKey] = $urlPart[$mapIdx] ?? null;
		}
		return $mapVals;
	}

	/**
	 * Looks the path up in the routes declared by the entry point file
	 * through $GLOBALS['manualRoute'].
	 *
	 * @param String $lookupPath Path to look up, without its .php extension.
	 * @return ?Array The 'app' and 'action' values, or null when no manual route matches.
	 */
	protected function manuRoute( String $lookupPath ): ?Array {
		$manRout = $GLOBALS['manualRoute'] ?? null;
		if( empty($manRout[$lookupPath]['command']) ){
			return null;
		}
		$manVals = $manRout[$lookupPath]['command'];
		return Array(
			'app'=>		$manVals['app'],
			'action'=>	$manVals['act']
		);
	}

	/**
	 * Looks the path up in the declared routes (conf/routes/*.rte): a key
	 * equal to the path wins, then the first pattern that matches it. An
	 * empty path is looked up as 'index'.
	 *
	 * @param String $lookupPath Path to look up, without its .php extension.
	 * @return ?Array The route values (see buildVals), or null when no route matches.
	 */
	protected function fileRoute( String $lookupPath ): ?Array {
		$lookupPath = trim($lookupPath, '/');
		if( $lookupPath=='' ){
			$lookupPath = 'index';
		}
		$rteData = $this->loadFiles();
		if( isset($rteData[$lookupPath]) ){
			return $this->buildVals( $rteData[$lookupPath], Array(), $lookupPath );
		}
		foreach( $rteData as $rteKey => $rteSet ){
			$capVals = $this->matchPath( strval($rteKey), $lookupPath );
			if( $capVals!==null ){
				return $this->buildVals( $rteSet, $capVals, strval($rteKey) );
			}
		}
		return null;
	}

	/**
	 * Reads every route file of the routes folder (conf/routes, or the one
	 * named by the 'folder' key of the route configuration), in file name
	 * order. When a path is declared twice, its first declaration is kept.
	 *
	 * @return Array The declared routes, by path or path pattern.
	 */
	protected function loadFiles(): Array {
		if( $this->rteFiles!==null ){
			return $this->rteFiles;
		}
		$rteData = Array();
		$this->rteFiles = Array();
		$dirPath = TOP_DIR.DS.($this->routeCnf['folder'] ?? 'conf'.DS.'routes');
		if( !is_dir($dirPath) ){
			return $rteData;
		}
		foreach( glob($dirPath.DS.'*.rte') as $rteFle ){
			// Plain JSON (Parsers\Index), the keys kept as written
			$rteFile = new Dcmnt($rteFle, false);
			$fleData = $rteFile->readValue('out');
			if( is_array($fleData) ){
				$this->warnsKeys( $fleData, basename(strval($rteFle)) );
				$rteData = $rteData + $fleData;
			}
		}
		$this->rteFiles = $rteData;
		return $rteData;
	}

	/**
	 * Takes the declared routes already read (the config cache), in place
	 * of the files of the routes folder.
	 *
	 * @param Array $routesList The declared routes, by path or path pattern, as getRoutes() gives them.
	 */
	public function setRoutes( Array $routesList ): Void {
		$this->rteFiles = $routesList;
	}

	/**
	 * Gives the declared routes, reading the files of the routes folder
	 * when they are not read yet.
	 *
	 * @return Array The declared routes, by path or path pattern.
	 */
	public function getRoutes(): Array {
		return $this->loadFiles();
	}

	/**
	 * Lists the declared routes, for what they answer with: one entry for
	 * each target of a route, with the methods it serves.
	 *
	 * @return Array The entries, each with the path under 'rtePath', its 'methods', its 'rteKind' (action, redrct, static or htpage), what it answers with under 'rteGoal', whether the route limits its methods under 'rteLock' and, for an action, its 'app' and 'action'.
	 */
	public function allRoutes(): Array {
		$rowList = Array();
		foreach( $this->loadFiles() as $rteKey => $rteSet ){
			$tgtVals = is_array($rteSet) ? ($rteSet['target'] ?? null) : null;
			if( !is_array($tgtVals) ){
				continue;
			}
			$alwList = array_filter( array_map('trim', explode(',', strtoupper(strval($rteSet['allows'] ?? '')))) );
			// A route limiting its methods closes its action to the path (shutsActn)
			$rteLock = isset($rteSet['allows']) || $this->targtKind($tgtVals)===null;
			if( $this->targtKind($tgtVals)!==null ){
				$mthList = array_diff($alwList, Array('HEAD', 'OPTIONS'));
				$tgtVals = array_fill_keys( empty($mthList) ? Array('GET') : $mthList, $tgtVals );
			}else{
				$tgtVals = array_filter( array_change_key_case($tgtVals, CASE_UPPER), 'is_array' );
			}
			// The methods answered by the same target are told together
			$goalLst = Array();
			foreach( $tgtVals as $mthNme => $mthTgt ){
				$rteKind = $this->targtKind($mthTgt);
				if( $rteKind===null ){
					continue;
				}
				$goalTxt = strval($mthTgt['url'] ?? $mthTgt['pge'] ?? $mthTgt['fle'] ?? ( isset($mthTgt['dir']) ? rtrim(strval($mthTgt['dir']), '/').'/*' : '' ));
				if( $rteKind=='action' ){
					$goalTxt = strval($mthTgt['app']).'/'.strval($mthTgt['act'] ?? '');
				}
				$goalKey = $rteKind.' '.$goalTxt;
				if( !isset($goalLst[$goalKey]) ){
					$goalLst[$goalKey] = Array('rtePath'=>trim(strval($rteKey), '/'), 'methods'=>Array(), 'rteKind'=>$rteKind, 'rteGoal'=>$goalTxt, 'app'=>($rteKind=='action') ? strval($mthTgt['app']) : null, 'action'=>($rteKind=='action') ? strval($mthTgt['act'] ?? '') : null, 'rteLock'=>$rteLock);
				}
				$goalLst[$goalKey]['methods'][] = strval($mthNme);
			}
			foreach( $goalLst as $goalRw ){
				$rowList[] = $goalRw;
			}
		}
		return $rowList;
	}

	/**
	 * Compares a path with a route pattern, segment by segment: a segment
	 * written $name accepts any non-empty value and captures it, any other
	 * segment must be identical. A final * takes the rest of the path,
	 * whatever its number of segments, and captures it under '*'.
	 *
	 * @param String $routeShape Declared route path, such as profiles/$id or assets/*.
	 * @param String $lookupPath Path of the request.
	 * @return ?Array The captured values by name (URL-decoded), or null when the path does not match.
	 */
	protected function matchPath( String $routeShape, String $lookupPath ): ?Array {
		$keyPart = explode('/', trim($routeShape, '/'));
		$urlPart = explode('/', $lookupPath);
		$restVal = null;
		if( end($keyPart)==='*' ){
			// The rest of the path may be empty : the folder itself is asked for
			array_pop($keyPart);
			if( count($urlPart)<count($keyPart) ){
				return null;
			}
			$restVal = implode('/', array_slice($urlPart, count($keyPart)));
			$urlPart = array_slice($urlPart, 0, count($keyPart));
		}
		if( count($keyPart)!=count($urlPart) ){
			return null;
		}
		$capVals = Array();
		foreach( $keyPart as $segIdx => $segKey ){
			$segVal = $urlPart[$segIdx];
			if( strlen($segKey)>1 && $segKey[0]=='$' ){
				if( $segVal==='' ){
					return null;
				}
				$capVals[substr($segKey, 1)] = rawurldecode($segVal);
			}elseif( $segKey!==$segVal ){
				return null;
			}
		}
		if( $restVal!==null ){
			$capVals['*'] = rawurldecode($restVal);
		}
		return $capVals;
	}

	/**
	 * Builds the route values of a declared route for the method of the
	 * request. Its 'target' block either names one application and action,
	 * served for GET or for the methods listed in 'allows', or holds one such
	 * block per method. 'allows' can also add HEAD, served by the GET target,
	 * and OPTIONS, answered with the accepted methods alone. Any other method
	 * gives a 405 status.
	 *
	 * A target block can name something else than an application: a
	 * redirection ('url', with its status in 'cod'), a static file ('fle')
	 * or folder ('dir'), or an HTML page ('pge'). Those are served for HEAD
	 * as they are for GET.
	 *
	 * @param Array  $routeEntry The declared route, with its 'target' block.
	 * @param Array  $captureSet Values captured from the path, by name.
	 * @param String $routeShape Path the route is declared with.
	 * @return ?Array The 'app', 'action', 'param' and 'pathVars' values, or 'statCode', 'statMssg' and 'allowed' when no action is to run, or the 'rteGoal' of another kind of reply; always with 'rteKind' and 'rtePath'. Null when the route names no target.
	 *
	 * @throws \RuntimeException When the target is not properly declared.
	 */
	protected function buildVals( Array $routeEntry, Array $captureSet = Array(), String $routeShape = '' ): ?Array {
		$tgtVals = $routeEntry['target'] ?? Array();
		if( !is_array($tgtVals) ){
			return null;
		}
		$alwList = array_filter( array_map('trim', explode(',', strtoupper(strval($routeEntry['allows'] ?? '')))) );
		$xtrList = array_intersect($alwList, Array('HEAD', 'OPTIONS'));

		// Targets by method : a general target serves the allowed methods, GET when none is listed
		if( $this->targtKind($tgtVals)!==null ){
			$mthList = array_diff($alwList, $xtrList);
			$tgtVals = array_fill_keys( empty($mthList) ? Array('GET') : $mthList, $tgtVals );
		}else{
			$tgtVals = array_filter( array_change_key_case($tgtVals, CASE_UPPER), 'is_array' );
		}
		if( empty($tgtVals) ){
			return null;
		}
		// What GET gets without running an action, HEAD gets too
		if( isset($tgtVals['GET']) && !in_array($this->targtKind($tgtVals['GET']), Array(null, 'action'), true) ){
			$xtrList[] = 'HEAD';
		}
		$alwList = array_unique( array_merge(array_map('strval', array_keys($tgtVals)), $xtrList) );
		$rteBase = Array('app'=>null, 'action'=>null, 'rtePath'=>trim($routeShape, '/'));

		$usedMth = null;
		foreach( $alwList as $mthNme ){
			if( $this->httpRqst->usedMethd($mthNme) ){
				$usedMth = $mthNme;
			}
		}
		$appVals = ($usedMth!==null) ? ($tgtVals[$usedMth] ?? null) : null;
		if( $appVals===null && $usedMth=='HEAD' ){
			$appVals = $tgtVals['GET'] ?? null;
		}
		if( $appVals===null ){
			// OPTIONS is answered with the accepted methods, anything else is refused
			$isOptns = ($usedMth=='OPTIONS');
			return Array(
				'statCode'=>$isOptns ? 204 : 405,
				'statMssg'=>$isOptns ? '' : 'Method Not Allowed',
				'allowed'=>	implode(', ', $alwList)
			) + $rteBase;
		}
		$rteKind = $this->targtKind($appVals);
		if( $rteKind=='redrct' ){
			return $this->redirVals($appVals, $captureSet) + $rteBase;
		}
		if( $rteKind=='static' || $rteKind=='htpage' ){
			return $this->statxVals($appVals, $captureSet) + $rteBase;
		}
		if( empty($appVals['app']) ){
			return null;
		}
		return Array(
			'app'=>		$appVals['app'],
			'action'=>	$appVals['act'] ?? null,
			'param'=>	empty($captureSet) ? null : strval(reset($captureSet)),
			'pathVars'=>$captureSet,
			'rteKind'=>	'action'
		) + $rteBase;
	}

	/**
	 * Tells what a target block names, from its keys.
	 *
	 * @param Array $targetVals One target block.
	 * @return ?String 'action', 'redrct', 'htpage' or 'static'; null when the block names none of them.
	 */
	protected function targtKind( Array $targetVals ): ?String {
		if( isset($targetVals['app']) ){
			return 'action';
		}
		if( isset($targetVals['url']) ){
			return 'redrct';
		}
		if( isset($targetVals['pge']) ){
			return 'htpage';
		}
		if( isset($targetVals['fle']) || isset($targetVals['dir']) ){
			return 'static';
		}
		return null;
	}

	/**
	 * Builds the route values of a redirection: its address, in which each
	 * $name is replaced by the value captured from the path (URL-encoded,
	 * so that it stays one part of the path), and its status.
	 *
	 * @param Array $targetVals The target block, with its 'url' and 'cod'.
	 * @param Array $captureSet Values captured from the path, by name.
	 * @return Array The 'rteKind', 'rteGoal' and 'rteCode' values.
	 *
	 * @throws \RuntimeException When the address or the status is not one of a redirection.
	 */
	protected function redirVals( Array $targetVals, Array $captureSet ): Array {
		$moveCod = intval($targetVals['cod'] ?? 302);
		$moveUrl = strval( preg_replace_callback('/\$(\w+)/', function( Array $matchedArr ) use ( $captureSet ): String {
			return isset($captureSet[$matchedArr[1]]) ? rawurlencode(strval($captureSet[$matchedArr[1]])) : $matchedArr[0];
		}, strval($targetVals['url'])) );
		if( !in_array($moveCod, self::MOVELIST, true) || !self::validAddr($moveUrl) ){
			throw new \RuntimeException('Route misconfigured: "'.strval($targetVals['url']).'" with the status '.$moveCod.' is not a redirection');
		}
		return Array('rteKind'=>'redrct', 'rteGoal'=>$moveUrl, 'rteCode'=>$moveCod);
	}

	/**
	 * Tells whether an address can be redirected to: a path of this site,
	 * starting with one slash, or a full http(s) address. Spaces, control
	 * characters and backslashes are refused.
	 *
	 * @param String $targetAddr The address.
	 * @return Bool True when the address is accepted.
	 */
	public static function validAddr( String $targetAddr ): Bool {
		if( preg_match('/[\x00-\x20\x7F\\\\]/', $targetAddr) ){
			return false;
		}
		return preg_match('#^/(?!/)#', $targetAddr)===1 || preg_match('#^https?://[^/?\#]+#i', $targetAddr)===1;
	}

	/**
	 * Builds the route values of a static file, of a file of a static
	 * folder or of an HTML page. Everything lies in the folder the route
	 * configuration names as 'htroot': without it, nothing is served. The
	 * file asked for in a folder is the rest of the path; it cannot be
	 * outside that folder, nor be a hidden file, and its extension must
	 * have a content type (see Response::TYPELIST). A folder gives its
	 * index.html, never its listing.
	 *
	 * @param Array $targetVals The target block, with its 'fle', 'dir' or 'pge'.
	 * @param Array $captureSet Values captured from the path, by name.
	 * @return Array The 'rteKind', 'rteGoal' and 'rteType' values, or a 404 as 'statCode' and 'statMssg'.
	 *
	 * @throws \RuntimeException When the target is outside the 'htroot' folder, or a page is not an HTML file.
	 */
	protected function statxVals( Array $targetVals, Array $captureSet ): Array {
		$notFnd = Array('statCode'=>404, 'statMssg'=>'Not Found', 'rteKind'=>'static');
		$rootCnf = strval($this->routeCnf['htroot'] ?? '');
		$rootDir = ($rootCnf!=='') ? realpath(TOP_DIR.DS.$rootCnf) : false;
		if( $rootDir===false || !is_dir($rootDir) ){
			return $notFnd;
		}
		$forPage = isset($targetVals['pge']);
		$dclName = strval($targetVals['pge'] ?? $targetVals['fle'] ?? $targetVals['dir']);
		$fndPath = realpath($rootDir.DS.$dclName);
		if( $fndPath===false ){
			return $notFnd;
		}
		if( !$this->liesInDir($fndPath, $rootDir) ){
			throw new \RuntimeException('Route misconfigured: "'.$dclName.'" is outside the folder of the static files');
		}
		if( !$forPage && !isset($targetVals['fle']) ){
			// A folder : the file is named by the rest of the path
			$dclPath = $fndPath;
			$restPth = strval($captureSet['*'] ?? '');
			if( !is_dir($dclPath) || ($restPth!=='' && !$this->safeParts($restPth)) ){
				return $notFnd;
			}
			if( $restPth!=='' ){
				$fndPath = realpath($dclPath.DS.str_replace('/', DS, $restPth));
			}
			if( $fndPath!==false && is_dir($fndPath) ){
				$fndPath = realpath($fndPath.DS.'index.html');
			}
			// Checked on the real path : a link cannot lead out of the folder either
			if( $fndPath===false || !$this->liesInDir($fndPath, $dclPath) ){
				return $notFnd;
			}
		}
		$mimeStr = Response::typeOfExt( pathinfo($fndPath, PATHINFO_EXTENSION) );
		if( !is_file($fndPath) || $mimeStr===null ){
			return $notFnd;
		}
		if( $forPage ){
			if( strpos($mimeStr, 'text/html')!==0 ){
				throw new \RuntimeException('Route misconfigured: "'.$dclName.'" is not an HTML page');
			}
			return Array('rteKind'=>'htpage', 'rteGoal'=>$fndPath, 'pathVars'=>$captureSet);
		}
		return Array('rteKind'=>'static', 'rteGoal'=>$fndPath, 'rteType'=>$mimeStr);
	}

	/**
	 * Gives the HTML page a site answers an error status with: the file
	 * errors/<status>.html of the folder the route configuration names as
	 * 'htroot', when it holds one.
	 *
	 * @param Int $statusCode The status of the reply.
	 * @return ?String The full path of the page, or null when the site has none for that status.
	 */
	public function errorPage( Int $statusCode ): ?String {
		$rootCnf = strval($this->routeCnf['htroot'] ?? '');
		$rootDir = ($rootCnf!=='') ? realpath(TOP_DIR.DS.$rootCnf) : false;
		if( $rootDir===false || !is_dir($rootDir) ){
			return null;
		}
		$pagePth = realpath($rootDir.DS.'errors'.DS.$statusCode.'.html');
		return ( $pagePth!==false && is_file($pagePth) && $this->liesInDir($pagePth, $rootDir) ) ? $pagePth : null;
	}

	/**
	 * Tells whether a path asked for inside a folder is made of plain
	 * names only: no empty part, none starting with a dot (which refuses
	 * '.', '..' and hidden files), no backslash, colon or control character.
	 *
	 * @param String $insidePath The path, URL-decoded, its parts separated by slashes.
	 * @return Bool True when every part is a plain name.
	 */
	protected function safeParts( String $insidePath ): Bool {
		foreach( explode('/', $insidePath) as $segVal ){
			if( $segVal==='' || $segVal[0]=='.' || preg_match('/[\\\\:\x00-\x1F]/', $segVal) ){
				return false;
			}
		}
		return true;
	}

	/**
	 * Tells whether a real path is a folder or lies in it.
	 *
	 * @param String $insidePath The path, as realpath() gives it.
	 * @param String $folderPath The folder, as realpath() gives it.
	 * @return Bool True when the path is the folder itself or inside it.
	 */
	protected function liesInDir( String $insidePath, String $folderPath ): Bool {
		return $insidePath===$folderPath || strpos($insidePath, rtrim($folderPath, '/\\').DS)===0;
	}

	/**
	 * Builds the link to a declared route, from the path it is declared
	 * with: each $name is replaced by its value, a final * by the value
	 * given as '*', all URL-encoded. In autopath mode, a path without
	 * such parts that no route declares is taken as it is.
	 *
	 * @param String $routeShape Path the route is declared with, such as profiles/$id.
	 * @param Array  $pathValues Values of the named parts of the path.
	 * @param Array  $queryParam Values to add as a query string.
	 * @return String The link, from the root of the site.
	 *
	 * @throws \RuntimeException When no such route is declared, or a named part has no value.
	 */
	public function buildLink( String $routeShape, Array $pathValues=Array(), Array $queryParam=Array() ): String {
		$rtePath = trim($routeShape, '/');
		$isKnown = isset($this->loadFiles()[$rtePath]);
		$hasVars = (strpos($rtePath, '$')!==false || strpos($rtePath, '*')!==false);
		if( !$isKnown && ($hasVars || ($this->routeCnf['detect'] ?? '')!='autopath') ){
			throw new \RuntimeException('No route is declared as "'.$routeShape.'"');
		}
		$segList = Array();
		foreach( explode('/', $rtePath) as $segKey ){
			if( $segKey==='*' ){
				$restVal = trim(strval($pathValues['*'] ?? ''), '/');
				$segList[] = implode('/', array_map('rawurlencode', explode('/', $restVal)));
			}elseif( strlen($segKey)>1 && $segKey[0]=='$' ){
				$segVal = $pathValues[substr($segKey, 1)] ?? null;
				if( !is_scalar($segVal) || strval($segVal)==='' ){
					throw new \RuntimeException('No value given for "'.$segKey.'" of the route "'.$routeShape.'"');
				}
				$segList[] = rawurlencode(strval($segVal));
			}else{
				$segList[] = $segKey;
			}
		}
		// The route declared as index is the root of the site
		$linkStr = ($isKnown && $rtePath=='index') ? '/' : '/'.implode('/', $segList);
		$qryText = http_build_query($queryParam, '', '&', PHP_QUERY_RFC3986);
		return $linkStr.($qryText!='' ? '?'.$qryText : '');
	}
}
