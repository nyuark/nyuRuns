<?php

// Resolves the application, action and parameter to run for a request,
// according to the 'route' block of the active preset

namespace nyu\Tools;

use nyu\Data\Dcmnt;

class Router{

	protected $routeCnf;	// 'route' block of the active preset
	protected $httpRqst;	// The shared HTTP request
	protected $pathText;	// Request path, without its leading slash and query string

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
	 * positionally from the URL path in autopath mode. A CORS preflight
	 * (an OPTIONS request carrying Access-Control-Request-Method) resolves
	 * to no application: it is answered with the preset's headers alone.
	 *
	 * @return Registry Registry holding the resolved 'app' and 'action' values, plus 'param' and any other mapped or captured key.
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
		$fndVals = ($dtcMode=='manual') ? $this->manuRoute($urlPath) : null;
		if( $fndVals===null ){
			$fndVals = $this->fileRoute($urlPath);
		}
		if( $fndVals!==null ){
			return new Registry( $fndVals );
		}

		// Automatic path detection (from url path)
		if( $dtcMode=='autopath' ){
			$rteVals = array_merge($rteVals, $this->autoRoute());
		}
		return new Registry( $rteVals );
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
			return $this->buildVals( $rteData[$lookupPath] );
		}
		foreach( $rteData as $rteKey => $rteSet ){
			$capVals = $this->matchPath( strval($rteKey), $lookupPath );
			if( $capVals!==null ){
				return $this->buildVals( $rteSet, $capVals );
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
		$rteData = Array();
		$dirPath = TOP_DIR.DS.($this->routeCnf['folder'] ?? 'conf'.DS.'routes');
		if( !is_dir($dirPath) ){
			return $rteData;
		}
		foreach( glob($dirPath.DS.'*.rte') as $rteFle ){
			$rteFile = new Dcmnt($rteFle);
			$rteFile->fileParse( 'json', false );
			$fleData = $rteFile->readValue('out');
			if( is_array($fleData) ){
				$rteData = $rteData + $fleData;
			}
		}
		return $rteData;
	}

	/**
	 * Compares a path with a route pattern, segment by segment: a segment
	 * written $name accepts any non-empty value and captures it, any other
	 * segment must be identical.
	 *
	 * @param String $routeShape Declared route path, such as profiles/$id.
	 * @param String $lookupPath Path of the request.
	 * @return ?Array The captured values by name (URL-decoded), or null when the path does not match.
	 */
	protected function matchPath( String $routeShape, String $lookupPath ): ?Array {
		$keyPart = explode('/', trim($routeShape, '/'));
		$urlPart = explode('/', $lookupPath);
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
	 * @param Array $routeEntry The declared route, with its 'target' block.
	 * @param Array $captureSet Values captured from the path, by name.
	 * @return ?Array The 'app', 'action', 'param' and 'pathVars' values, or 'statCode', 'statMssg' and 'allowed' when no action is to run; null when the route names no application.
	 */
	protected function buildVals( Array $routeEntry, Array $captureSet = Array() ): ?Array {
		$tgtVals = $routeEntry['target'] ?? Array();
		if( !is_array($tgtVals) ){
			return null;
		}
		$alwList = array_filter( array_map('trim', explode(',', strtoupper(strval($routeEntry['allows'] ?? '')))) );
		$xtrList = array_intersect($alwList, Array('HEAD', 'OPTIONS'));

		// Targets by method : a general target serves the allowed methods, GET when none is listed
		if( isset($tgtVals['app']) ){
			$mthList = array_diff($alwList, $xtrList);
			$tgtVals = array_fill_keys( empty($mthList) ? Array('GET') : $mthList, $tgtVals );
		}else{
			$tgtVals = array_filter( array_change_key_case($tgtVals, CASE_UPPER), 'is_array' );
		}
		if( empty($tgtVals) ){
			return null;
		}
		$alwList = array_unique( array_merge(array_map('strval', array_keys($tgtVals)), $xtrList) );

		$usedMth = null;
		foreach( $alwList as $mthNme ){
			if( $this->httpRqst->usedMethd($mthNme) ){
				$usedMth = $mthNme;
			}
		}
		$appVals = $tgtVals[$usedMth] ?? null;
		if( $appVals===null && $usedMth=='HEAD' ){
			$appVals = $tgtVals['GET'] ?? null;
		}
		if( $appVals===null ){
			// OPTIONS is answered with the accepted methods, anything else is refused
			$isOptns = ($usedMth=='OPTIONS');
			return Array(
				'app'=>		null,
				'action'=>	null,
				'statCode'=>$isOptns ? 204 : 405,
				'statMssg'=>$isOptns ? '' : 'Method Not Allowed',
				'allowed'=>	implode(', ', $alwList)
			);
		}
		if( empty($appVals['app']) ){
			return null;
		}
		return Array(
			'app'=>		$appVals['app'],
			'action'=>	$appVals['act'] ?? null,
			'param'=>	empty($captureSet) ? null : strval(reset($captureSet)),
			'pathVars'=>$captureSet
		);
	}
}
