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
	const MOVE_LIST = Array(301, 302, 303, 307, 308);
	// The keys a declared route may hold, and those of a target block
	const KEYS_LIST = Array('mthdList', 'pointsTo');
	const TRGT_KEYS = Array('appNamed', 'actNamed', 'redirect', 'httpCode', 'filePath', 'foldPath', 'pageFile');

	protected $routConfig;	// 'route' block of the active preset
	protected $httpRequst;	// The shared HTTP request
	protected $rtePathTxt;	// Request path, without its leading slash and query string
	protected $routeFiles;	// Declared routes, once read

	/**
	 * Prepares the router for a request: keeps the route configuration and
	 * extracts the path to resolve from the request URI.
	 *
	 * @param Array   $routSettings The 'route' config block of the active preset.
	 * @param Request $requstObject The shared, already-parsed HTTP request.
	 */
	public function __construct( Array $routSettings, Request $requstObject ){
		$this->routConfig = $routSettings;
		$this->httpRequst = $requstObject;
		$this->rtePathTxt = $this->cleanRoutePath( strval($requstObject->obtainUrlValue('reqstURI')) );
	}

	/**
	 * Resolves the route of the request: from the declared routes first (the
	 * site's, in the routes folder of the preset, then the Runs' own, in
	 * runs/<App>/routes.rte), otherwise positionally from the URL path in
	 * autopath mode. A Run declaring its routes is closed: nothing of it is
	 * reached by its path, a 404 logged on 'runner'. A CORS preflight
	 * (an OPTIONS request carrying Access-Control-Request-Method) resolves
	 * to no application: it is answered with the preset's headers alone.
	 * A declared route can also answer without an application, with a
	 * redirection, a static file or an HTML page (see buildVals).
	 *
	 * @return Registry Registry holding the resolved 'app' and 'action' values, plus 'param' and any other mapped or captured key.
	 *
	 * @throws \RuntimeException When the route found is not properly declared.
	 */
	public function locateRouteReg(): Registry {
		// A CORS preflight is answered by the route itself, on any path : no action runs
		if( $this->httpRequst->usedMethodFlag('OPTIONS') && $this->httpRequst->retrieveHeader('Access-Control-Request-Method')!==null ){
			return new Registry( Array('app'=>null, 'action'=>null, 'statCode'=>204, 'statMssg'=>'') );
		}
		$routVls = Array(
			'app'=>null,
			'action'=>null
		);
		$dtcMode = $this->routConfig['detect'] ?? '';

		// Declared routes come first : the site's, then the Runs'
		$urlPath = str_replace('.php', '', $this->rtePathTxt);
		$matched = $this->fileRouteArray($urlPath);
		if( $matched!==null ){
			return new Registry( $matched );
		}

		// Automatic path detection (from url path), for the Runs that declare no routes
		if( $dtcMode=='autopath' ){
			$routVls = array_merge($routVls, $this->autoRouteArray());
			$appName = strval($routVls['app'] ?? '');
			// A Run declaring its routes is closed : its actions are reached through them only
			if( preg_match('/^[A-Za-z0-9]+$/D', $appName) && is_file(RUN_DIR.DS.ucfirst($appName).DS.'routes.rte') ){
				Logger::retrieveLogger('runner')->info( 'Not found: '.json_encode($appName.'/'.strval($routVls['action'] ?? ''), JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES).': not listed in the routes of the application'.Logger::getActorSuffix() );
				return new Registry( Array('app'=>null, 'action'=>null, 'statCode'=>404, 'statMssg'=>'Not Found') );
			}
		}
		return new Registry( $routVls );
	}

	/**
	 * Logs, as warnings on the 'runner' channel, the keys of declared
	 * routes that nothing reads: a key left from another version, or
	 * misspelt, would otherwise be ignored without a word.
	 *
	 * @param Array  $rteRoutesLst The routes of a file, by path.
	 * @param String $fileOfRoutes Name of the file, for the message.
	 * @param Array  $goalKeysList The keys a target block of the file may hold: TRGT_KEYS for the site, 'actNamed' alone for a Run.
	 */
	protected function warnsRouteKeys( Array $rteRoutesLst, String $fileOfRoutes, Array $goalKeysList=self::TRGT_KEYS ): Void {
		foreach( $rteRoutesLst as $rteKey => $rteSet ){
			if( !is_array($rteSet) ){
				continue;
			}
			$badKeys = array_values( array_diff(array_map('strval', array_keys($rteSet)), self::KEYS_LIST) );
			$trgtVls = $rteSet['pointsTo'] ?? null;
			if( is_array($trgtVls) ){
				// One block, or one per method (a block of a Run names its action alone)
				$blckLst = ($this->targetKindText($trgtVls)!==null || isset($trgtVls['actNamed'])) ? Array($trgtVls) : array_filter($trgtVls, 'is_array');
				foreach( $blckLst as $blkVal ){
					foreach( array_diff(array_map('strval', array_keys($blkVal)), $goalKeysList) as $tgtKey ){
						$badKeys[] = 'pointsTo.'.$tgtKey;
					}
				}
			}
			foreach( array_unique($badKeys) as $badKey ){
				Logger::retrieveLogger('runner')->warning( 'Route '.json_encode(strval($rteKey)).' of '.$fileOfRoutes.' holds an unknown key: '.$badKey );
			}
		}
	}

	/**
	 * Strips the leading slash and the query string from a request URI.
	 *
	 * @param String $reqUriString The request URI, as received.
	 * @return String The path to resolve.
	 */
	protected function cleanRoutePath( String $reqUriString ): String {
		$quoMark = strpos($reqUriString, '?');
		if( $quoMark!==false ){
			return substr($reqUriString, 1, $quoMark-1);
		}
		return substr($reqUriString, 1);
	}

	/**
	 * Maps the segments of the path onto the keys of the configured mapper
	 * (such as $app/$action/$param), by position.
	 *
	 * @return Array The mapper keys with their path segment, null when the path is shorter.
	 */
	protected function autoRouteArray(): Array {
		$urlPart = explode( '/', $this->rtePathTxt );
		$mapPath = str_replace( '$', '', $this->routConfig['mapper'] ?? '' );
		$mapPart = explode( '/', $mapPath );
		$mapping = Array();

		foreach( $mapPart as $mapIdx => $mapKey ){
			$mapping[$mapKey] = $urlPart[$mapIdx] ?? null;
		}
		return $mapping;
	}

	/**
	 * Looks the path up in the declared routes: the site's first, then the
	 * Runs'; in each, a key equal to the path wins, then the first pattern
	 * that matches it. An empty path is looked up as 'index'.
	 *
	 * @param String $pathToLookUp Path to look up, without its .php extension.
	 * @return ?Array The route values (see buildVals), or null when no route matches.
	 */
	protected function fileRouteArray( String $pathToLookUp ): ?Array {
		$pathToLookUp = trim($pathToLookUp, '/');
		if( $pathToLookUp=='' ){
			$pathToLookUp = 'index';
		}
		$rteData = $this->loadFilesArray();
		foreach( Array(true, false) as $ofSite ){
			if( isset($rteData[$pathToLookUp]) && (strval($rteData[$pathToLookUp]['routOwnr'] ?? 'site')=='site')===$ofSite ){
				return $this->generateValues( $rteData[$pathToLookUp], Array(), $pathToLookUp );
			}
			foreach( $rteData as $rteKey => $rteSet ){
				if( (strval($rteSet['routOwnr'] ?? 'site')=='site')!==$ofSite ){
					continue;
				}
				$capture = $this->matchRoutePath( strval($rteKey), $pathToLookUp );
				if( $capture!==null ){
					return $this->generateValues( $rteSet, $capture, strval($rteKey) );
				}
			}
		}
		return null;
	}

	/**
	 * Reads the declared routes: those of the site, every route file of the
	 * routes folder (conf/routes, or the one named by the 'folder' key of the
	 * route configuration) in file name order, then those of the Runs (see
	 * runRoutes). Each is kept by its path without its slashes, its owner
	 * under 'routOwnr': 'site', or the Run's name. When the site declares a
	 * path twice, its first declaration is kept. A site route on the path of
	 * a Run route wins over it, with a warning on 'runner'; two Runs on one
	 * path, the first in name order answers, logged as an error.
	 *
	 * @return Array The declared routes, by path or path pattern.
	 */
	protected function loadFilesArray(): Array {
		if( $this->routeFiles!==null ){
			return $this->routeFiles;
		}
		$rteData = Array();
		$dirPath = TOP_DIR.DS.($this->routConfig['folder'] ?? 'conf'.DS.'routes');
		if( is_dir($dirPath) ){
			foreach( glob($dirPath.DS.'*.rte') as $rteFil ){
				// Plain JSON (Parsers\Index), the keys kept as written
				$rteFile = new Dcmnt($rteFil, false);
				$filData = $rteFile->readFileDetail('out');
				if( is_array($filData) ){
					$this->warnsRouteKeys( $filData, basename(strval($rteFil)) );
					$rteData = $rteData + $filData;
				}
			}
		}
		$siteRte = Array();
		foreach( $rteData as $rteKey => $rteSet ){
			$rtePath = trim(strval($rteKey), '/');
			if( is_array($rteSet) && !isset($siteRte[$rtePath]) ){
				$siteRte[$rtePath] = Array('routOwnr'=>'site') + $rteSet;
			}
		}
		foreach( $this->runRoutesArray() as $runRte ){
			$rtePath = strval($runRte['routPath']);
			if( isset($siteRte[$rtePath]) ){
				if( $siteRte[$rtePath]['routOwnr']=='site' ){
					Logger::retrieveLogger('runner')->warning( 'Route '.json_encode('/'.$rtePath, JSON_UNESCAPED_SLASHES).' of the application '.$runRte['routOwnr'].' is also declared by the site: the site\'s answers' );
				}else{
					Logger::retrieveLogger('runner')->error( 'Route '.json_encode('/'.$rtePath, JSON_UNESCAPED_SLASHES).' is declared by the applications '.$siteRte[$rtePath]['routOwnr'].' and '.$runRte['routOwnr'].': '.$siteRte[$rtePath]['routOwnr'].' answers' );
				}
				continue;
			}
			// A pattern of the site over it answers first : told as well
			foreach( array_keys(array_combine(array_keys($siteRte), array_column($siteRte, 'routOwnr')), 'site', true) as $rteKey ){
				if( $this->matchRoutePath(strval($rteKey), $rtePath)!==null ){
					Logger::retrieveLogger('runner')->warning( 'Route '.json_encode('/'.$rtePath, JSON_UNESCAPED_SLASHES).' of the application '.$runRte['routOwnr'].' falls under the site route '.json_encode('/'.$rteKey, JSON_UNESCAPED_SLASHES).': the site\'s answers' );
					break;
				}
			}
			unset($runRte['routPath']);
			$siteRte[$rtePath] = $runRte;
		}
		$this->routeFiles = $siteRte;
		return $siteRte;
	}

	/**
	 * Gives the final path of a route a Run declares in its own routes.rte,
	 * from the path written there (relative to its mount, or from the root
	 * with a leading slash): the route under the Run's current mount.
	 *
	 * @param String $appNameToRun Name of the Run, whatever its case.
	 * @param String $routShapeTxt Path of the route as the Run's file declares it, such as items/$id.
	 * @return ?String The final path, without its slashes; null when the Run declares no such route, or does not serve it.
	 */
	public function runsRouteShape( String $appNameToRun, String $routShapeTxt ): ?String {
		foreach( $this->loadFilesArray() as $rteKey => $rteSet ){
			if( strcasecmp(strval($rteSet['routOwnr'] ?? 'site'), $appNameToRun)==0 && ($rteSet['routDecl'] ?? null)===trim($routShapeTxt, '/') ){
				return strval($rteKey);
			}
		}
		return null;
	}

	/**
	 * Reads the routes the Runs declare, runs/<App>/routes.rte, in name
	 * order of the Runs: the .rte format, each target naming an action of
	 * that Run with 'actNamed' alone (its 'appNamed' is implied; any other key of a
	 * target is warned as unknown). A target naming a redirection, a file or
	 * a page is left out, not served: a whole route for one block, the
	 * method alone in a block per method. A path is moved under the mount of
	 * the Run (see mountPath), except one with a leading slash, from the root
	 * of the site. A Run the preset does not mount serves none.
	 *
	 * @return Array The routes, in a list: each its declaration with its final path under 'routPath' (without its slashes), its Run under 'routOwnr' and the path its file declares it with under 'routDecl' (without its slashes).
	 */
	public function runRoutesArray(): Array {
		$rowList = Array();
		if( !defined('RUN_DIR') ){
			return $rowList;
		}
		foreach( (array)glob(RUN_DIR.DS.'*'.DS.'routes.rte') as $rteFil ){
			$appName = basename(dirname(strval($rteFil)));
			$mntPath = $this->mountRoutePath($appName);
			if( $mntPath===null || !preg_match('/^[A-Za-z0-9]+$/D', $appName) ){
				continue;
			}
			$filData = (new Dcmnt(strval($rteFil), false))->readFileDetail('out');
			if( !is_array($filData) ){
				continue;
			}
			$this->warnsRouteKeys( $filData, $appName.'/routes.rte', Array('actNamed') );
			foreach( $filData as $rteKey => $rteSet ){
				if( !is_array($rteSet) ){
					continue;
				}
				$rteKey = strval($rteKey);
				$rtePath = (substr($rteKey, 0, 1)=='/') ? trim($rteKey, '/') : trim($mntPath.'/'.trim($rteKey, '/'), '/');
				$trgtVls = $rteSet['pointsTo'] ?? null;
				if( !is_array($trgtVls) ){
					continue;
				}
				// A Run serves its own actions only : a target naming a redirection, a file or a page is left out (warned by warnsKeys)
				if( !empty(array_intersect(array_map('strval', array_keys($trgtVls)), array_merge(self::TRGT_KEYS, Array('actNamed')))) ){
					// One block for every method
					if( !empty(array_intersect(array_keys($trgtVls), array_diff(self::TRGT_KEYS, Array('appNamed', 'actNamed')))) ){
						continue;
					}
					$trgtVls['appNamed'] = $appName;
				}else{
					// One block per method, each checked
					foreach( $trgtVls as $mthNme => $target ){
						if( !is_array($target) || !empty(array_intersect(array_keys($target), array_diff(self::TRGT_KEYS, Array('appNamed', 'actNamed')))) ){
							unset($trgtVls[$mthNme]);
							continue;
						}
						$trgtVls[$mthNme]['appNamed'] = $appName;
					}
					if( empty($trgtVls) ){
						continue;
					}
				}
				$rteSet['pointsTo'] = $trgtVls;
				$rowList[] = Array('routPath'=>$rtePath, 'routOwnr'=>$appName, 'routDecl'=>trim($rteKey, '/')) + $rteSet;
			}
		}
		return $rowList;
	}

	/**
	 * Gives the mount of a Run: the path its routes are moved under, from
	 * the 'mounts' key of the route configuration, { "<run>": false |
	 * "<prefix>" }, the Run named whatever its case. A Run it does not name
	 * is mounted under its name in lower case.
	 *
	 * @param String $appNameToRun Name of the Run.
	 * @return ?String The prefix, without its slashes (empty for the root of the site); null when the Run's routes are not served.
	 */
	public function mountRoutePath( String $appNameToRun ): ?String {
		$mntList = $this->routConfig['mounts'] ?? Array();
		foreach( (is_array($mntList) ? $mntList : Array()) as $mntKey => $mntVal ){
			if( strcasecmp(strval($mntKey), $appNameToRun)!=0 ){
				continue;
			}
			return ($mntVal===false) ? null : trim(strval($mntVal), '/');
		}
		return strtolower($appNameToRun);
	}

	/**
	 * Takes the declared routes already read (the settings clrCache), in place
	 * of the files of the routes folder.
	 *
	 * @param Array $rteRoutesLst The declared routes, by path or path pattern, as retrieveRoutes() gives them.
	 */
	public function setRouteRoutes( Array $rteRoutesLst ): Void {
		$this->routeFiles = $rteRoutesLst;
	}

	/**
	 * Gives the declared routes, reading the files of the routes folder
	 * when they are not read yet.
	 *
	 * @return Array The declared routes, by path or path pattern.
	 */
	public function retrieveRoutes(): Array {
		return $this->loadFilesArray();
	}

	/**
	 * Lists the declared routes, for what they answer with: one entry for
	 * each target of a route, with the methods it serves.
	 *
	 * @return Array The entries, each with the path under 'routPath', its 'methods', its 'routKind' (toAction, redirect, toStatic or htmlPage), what it answers with under 'routGoal', its owner under 'routOwnr' ('site' or a Run) and, for an action, its 'app' and 'action'.
	 */
	public function allRoutesArray(): Array {
		$rowList = Array();
		foreach( $this->loadFilesArray() as $rteKey => $rteSet ){
			$trgtVls = is_array($rteSet) ? ($rteSet['pointsTo'] ?? null) : null;
			if( !is_array($trgtVls) ){
				continue;
			}
			$allwLst = array_filter( array_map('trim', explode(',', strtoupper(strval($rteSet['mthdList'] ?? '')))) );
			if( $this->targetKindText($trgtVls)!==null ){
				$mthList = array_diff($allwLst, Array('HEAD', 'OPTIONS'));
				$trgtVls = array_fill_keys( empty($mthList) ? Array('GET') : $mthList, $trgtVls );
			}else{
				$trgtVls = array_filter( array_change_key_case($trgtVls, CASE_UPPER), 'is_array' );
			}
			// The methods answered by the same target are told together
			$goalLst = Array();
			foreach( $trgtVls as $mthNme => $target ){
				$rteKind = $this->targetKindText($target);
				if( $rteKind===null ){
					continue;
				}
				$goalTxt = strval($target['redirect'] ?? $target['pageFile'] ?? $target['filePath'] ?? ( isset($target['foldPath']) ? rtrim(strval($target['foldPath']), '/').'/*' : '' ));
				if( $rteKind=='toAction' ){
					$goalTxt = strval($target['appNamed']).'/'.strval($target['actNamed'] ?? '');
				}
				$goalKey = $rteKind.' '.$goalTxt;
				if( !isset($goalLst[$goalKey]) ){
					$goalLst[$goalKey] = Array('routPath'=>trim(strval($rteKey), '/'), 'methods'=>Array(), 'routKind'=>$rteKind, 'routGoal'=>$goalTxt, 'app'=>($rteKind=='toAction') ? strval($target['appNamed']) : null, 'action'=>($rteKind=='toAction') ? strval($target['actNamed'] ?? '') : null, 'routOwnr'=>strval($rteSet['routOwnr'] ?? 'site'));
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
	 * @param String $routShapeTxt Declared route path, such as profiles/$id or assets/*.
	 * @param String $pathToLookUp Path of the request.
	 * @return ?Array The captured values by name (URL-decoded), or null when the path does not match.
	 */
	public function matchRoutePath( String $routShapeTxt, String $pathToLookUp ): ?Array {
		$keyPart = explode('/', trim($routShapeTxt, '/'));
		$urlPart = explode('/', $pathToLookUp);
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
		$capture = Array();
		foreach( $keyPart as $segInc => $segKey ){
			$segText = $urlPart[$segInc];
			if( strlen($segKey)>1 && $segKey[0]=='$' ){
				if( $segText==='' ){
					return null;
				}
				$capture[substr($segKey, 1)] = rawurldecode($segText);
			}elseif( $segKey!==$segText ){
				return null;
			}
		}
		if( $restVal!==null ){
			$capture['*'] = rawurldecode($restVal);
		}
		return $capture;
	}

	/**
	 * Builds the route values of a declared route for the method of the
	 * request. Its 'pointsTo' block either names one application and action,
	 * served for GET or for the methods listed in 'mthdList', or holds one such
	 * block per method. 'mthdList' can also add HEAD, served by the GET target,
	 * and OPTIONS, answered with the accepted methods alone. Any other method
	 * gives a 405 status.
	 *
	 * A target block can name something else than an application: a
	 * redirection ('redirect', with its status in 'httpCode'), a static file ('filePath')
	 * or folder ('foldPath'), or an HTML page ('pageFile'). Those are served for HEAD
	 * as they are for GET.
	 *
	 * @param Array  $routEntryArr The declared route, with its 'pointsTo' block.
	 * @param Array  $capturedVals Values captured from the path, by name.
	 * @param String $routShapeTxt Path the route is declared with.
	 * @return ?Array The 'app', 'action', 'param' and 'pathVars' values, or 'statCode', 'statMssg' and 'allowed' when no action is to run, or the 'routGoal' of another kind of reply; always with 'routKind', 'routPath' and 'routOwnr' ('site' or the Run declaring it). Null when the route names no target.
	 *
	 * @throws \RuntimeException When the target is not properly declared.
	 */
	protected function generateValues( Array $routEntryArr, Array $capturedVals = Array(), String $routShapeTxt = '' ): ?Array {
		$trgtVls = $routEntryArr['pointsTo'] ?? Array();
		if( !is_array($trgtVls) ){
			return null;
		}
		$allwLst = array_filter( array_map('trim', explode(',', strtoupper(strval($routEntryArr['mthdList'] ?? '')))) );
		$extrLst = array_intersect($allwLst, Array('HEAD', 'OPTIONS'));

		// Targets by method : a general target serves the allowed methods, GET when none is listed
		if( $this->targetKindText($trgtVls)!==null ){
			$mthList = array_diff($allwLst, $extrLst);
			$trgtVls = array_fill_keys( empty($mthList) ? Array('GET') : $mthList, $trgtVls );
		}else{
			$trgtVls = array_filter( array_change_key_case($trgtVls, CASE_UPPER), 'is_array' );
		}
		if( empty($trgtVls) ){
			return null;
		}
		// What GET gets without running an action, HEAD gets too
		if( isset($trgtVls['GET']) && !in_array($this->targetKindText($trgtVls['GET']), Array(null, 'toAction'), true) ){
			$extrLst[] = 'HEAD';
		}
		$allwLst = array_unique( array_merge(array_map('strval', array_keys($trgtVls)), $extrLst) );
		$rteBase = Array('app'=>null, 'action'=>null, 'routPath'=>trim($routShapeTxt, '/'), 'routOwnr'=>strval($routEntryArr['routOwnr'] ?? 'site'));

		$usedMth = null;
		foreach( $allwLst as $mthNme ){
			if( $this->httpRequst->usedMethodFlag($mthNme) ){
				$usedMth = $mthNme;
			}
		}
		$appInfo = ($usedMth!==null) ? ($trgtVls[$usedMth] ?? null) : null;
		if( $appInfo===null && $usedMth=='HEAD' ){
			$appInfo = $trgtVls['GET'] ?? null;
		}
		if( $appInfo===null ){
			// OPTIONS is answered with the accepted methods, anything else is refused
			$optFlag = ($usedMth=='OPTIONS');
			return Array(
				'statCode'=>$optFlag ? 204 : 405,
				'statMssg'=>$optFlag ? '' : 'Method Not Allowed',
				'allowed'=>	implode(', ', $allwLst)
			) + $rteBase;
		}
		$rteKind = $this->targetKindText($appInfo);
		if( $rteKind=='redirect' ){
			return $this->redirectValues($appInfo, $capturedVals) + $rteBase;
		}
		if( $rteKind=='toStatic' || $rteKind=='htmlPage' ){
			return $this->statxValuesArr($appInfo, $capturedVals) + $rteBase;
		}
		if( empty($appInfo['appNamed']) ){
			return null;
		}
		return Array(
			'app'=>		$appInfo['appNamed'],
			'action'=>	$appInfo['actNamed'] ?? null,
			'param'=>	empty($capturedVals) ? null : strval(reset($capturedVals)),
			'pathVars'=>$capturedVals,
			'routKind'=>	'toAction'
		) + $rteBase;
	}

	/**
	 * Tells what a target block names, from its keys.
	 *
	 * @param Array $targetValues One target block.
	 * @return ?String 'toAction', 'redirect', 'htmlPage' or 'toStatic'; null when the block names none of them.
	 */
	protected function targetKindText( Array $targetValues ): ?String {
		if( isset($targetValues['appNamed']) ){
			return 'toAction';
		}
		if( isset($targetValues['redirect']) ){
			return 'redirect';
		}
		if( isset($targetValues['pageFile']) ){
			return 'htmlPage';
		}
		if( isset($targetValues['filePath']) || isset($targetValues['foldPath']) ){
			return 'toStatic';
		}
		return null;
	}

	/**
	 * Builds the route values of a redirection: its address, in which each
	 * $name is replaced by the value captured from the path (URL-encoded,
	 * so that it stays one part of the path), and its status.
	 *
	 * @param Array $targetValues The target block, with its 'redirect' and 'httpCode'.
	 * @param Array $capturedVals Values captured from the path, by name.
	 * @return Array The 'routKind', 'routGoal' and 'routCode' values.
	 *
	 * @throws \RuntimeException When the address or the status is not one of a redirection.
	 */
	protected function redirectValues( Array $targetValues, Array $capturedVals ): Array {
		$movCode = intval($targetValues['httpCode'] ?? 302);
		$moveUrl = strval( preg_replace_callback('/\$(\w+)/', function( Array $matchedArray ) use ( $capturedVals ): String {
			return isset($capturedVals[$matchedArray[1]]) ? rawurlencode(strval($capturedVals[$matchedArray[1]])) : $matchedArray[0];
		}, strval($targetValues['redirect'])) );
		if( !in_array($movCode, self::MOVE_LIST, true) || !self::validRouteAddr($moveUrl) ){
			throw new \RuntimeException('Route misconfigured: "'.strval($targetValues['redirect']).'" with the status '.$movCode.' is not a redirection');
		}
		return Array('routKind'=>'redirect', 'routGoal'=>$moveUrl, 'routCode'=>$movCode);
	}

	/**
	 * Tells whether an address can be redirected to: a path of this site,
	 * starting with one slash, or a full http(s) address. Spaces, control
	 * characters and backslashes are refused.
	 *
	 * @param String $redirectAddr The address.
	 * @return Bool True when the address is accepted.
	 */
	public static function validRouteAddr( String $redirectAddr ): Bool {
		if( preg_match('/[\x00-\x20\x7F\\\\]/', $redirectAddr) ){
			return false;
		}
		return preg_match('#^/(?!/)#', $redirectAddr)===1 || preg_match('#^https?://[^/?\#]+#i', $redirectAddr)===1;
	}

	/**
	 * Builds the route values of a static file, of a file of a static
	 * folder or of an HTML page. Everything lies in the folder the route
	 * configuration names as 'htroot': without it, nothing is served. The
	 * file asked for in a folder is the rest of the path; it cannot be
	 * outside that folder, nor be a hidden file, and its extension must
	 * have a content type (see Response::TYPE_LIST). A folder gives its
	 * index.html, never its listing.
	 *
	 * @param Array $targetValues The target block, with its 'filePath', 'foldPath' or 'pageFile'.
	 * @param Array $capturedVals Values captured from the path, by name.
	 * @return Array The 'routKind', 'routGoal' and 'routType' values, or a 404 as 'statCode' and 'statMssg'.
	 *
	 * @throws \RuntimeException When the target is outside the 'htroot' folder, or a page is not an HTML file.
	 */
	protected function statxValuesArr( Array $targetValues, Array $capturedVals ): Array {
		$missing = Array('statCode'=>404, 'statMssg'=>'Not Found', 'routKind'=>'toStatic');
		$rootCnf = strval($this->routConfig['htroot'] ?? '');
		$rootDir = ($rootCnf!=='') ? realpath(TOP_DIR.DS.$rootCnf) : false;
		if( $rootDir===false || !is_dir($rootDir) ){
			return $missing;
		}
		$forPage = isset($targetValues['pageFile']);
		$rteName = strval($targetValues['pageFile'] ?? $targetValues['filePath'] ?? $targetValues['foldPath']);
		$foundAt = realpath($rootDir.DS.$rteName);
		if( $foundAt===false ){
			return $missing;
		}
		if( !$this->liesRouteInDir($foundAt, $rootDir) ){
			throw new \RuntimeException('Route misconfigured: "'.$rteName.'" is outside the folder of the static files');
		}
		if( !$forPage && !isset($targetValues['filePath']) ){
			// A folder : the file is named by the rest of the path
			$rtePath = $foundAt;
			$restPth = strval($capturedVals['*'] ?? '');
			if( !is_dir($rtePath) || ($restPth!=='' && !$this->securePartsFlg($restPth)) ){
				return $missing;
			}
			if( $restPth!=='' ){
				$foundAt = realpath($rtePath.DS.str_replace('/', DS, $restPth));
			}
			if( $foundAt!==false && is_dir($foundAt) ){
				$foundAt = realpath($foundAt.DS.'index.html');
			}
			// Checked on the real path : a link cannot lead out of the folder either
			if( $foundAt===false || !$this->liesRouteInDir($foundAt, $rtePath) ){
				return $missing;
			}
			// The real names below the folder too : a short name (ENV~1 on Windows) can stand for a hidden file
			$realRst = str_replace(DS, '/', substr($foundAt, strlen(rtrim($rtePath, '/\\')) + 1));
			if( $realRst!=='' && $realRst!==false && !$this->securePartsFlg($realRst) ){
				return $missing;
			}
		}
		$mimeTxt = Response::typOfExtension( pathinfo($foundAt, PATHINFO_EXTENSION) );
		if( !is_file($foundAt) || $mimeTxt===null ){
			return $missing;
		}
		if( $forPage ){
			if( strpos($mimeTxt, 'text/html')!==0 ){
				throw new \RuntimeException('Route misconfigured: "'.$rteName.'" is not an HTML page');
			}
			return Array('routKind'=>'htmlPage', 'routGoal'=>$foundAt, 'pathVars'=>$capturedVals);
		}
		return Array('routKind'=>'toStatic', 'routGoal'=>$foundAt, 'routType'=>$mimeTxt);
	}

	/**
	 * Gives the HTML page a site answers an error status with: the file
	 * errors/<status>.html of the folder the route configuration names as
	 * 'htroot', when it holds one.
	 *
	 * @param Int $responseCode The status of the reply.
	 * @return ?String The full path of the page, or null when the site has none for that status.
	 */
	public function errorPgeString( Int $responseCode ): ?String {
		$rootCnf = strval($this->routConfig['htroot'] ?? '');
		$rootDir = ($rootCnf!=='') ? realpath(TOP_DIR.DS.$rootCnf) : false;
		if( $rootDir===false || !is_dir($rootDir) ){
			return null;
		}
		$pgePath = realpath($rootDir.DS.'errors'.DS.$responseCode.'.html');
		return ( $pgePath!==false && is_file($pgePath) && $this->liesRouteInDir($pgePath, $rootDir) ) ? $pgePath : null;
	}

	/**
	 * Tells whether a path asked for inside a folder is made of plain
	 * names only: no empty part, none starting with a dot (which refuses
	 * '.', '..' and hidden files), no backslash, colon or control character.
	 *
	 * @param String $pathFromRoot The path, URL-decoded, its parts separated by slashes.
	 * @return Bool True when every part is a plain name.
	 */
	protected function securePartsFlg( String $pathFromRoot ): Bool {
		foreach( explode('/', $pathFromRoot) as $oneSeg ){
			if( $oneSeg==='' || $oneSeg[0]=='.' || preg_match('/[\\\\:\x00-\x1F]/', $oneSeg) ){
				return false;
			}
		}
		return true;
	}

	/**
	 * Tells whether a real path is a folder or lies in it.
	 *
	 * @param String $pathFromRoot The path, as realpath() gives it.
	 * @param String $fullFoldPath The folder, as realpath() gives it.
	 * @return Bool True when the path is the folder itself or inside it.
	 */
	protected function liesRouteInDir( String $pathFromRoot, String $fullFoldPath ): Bool {
		return $pathFromRoot===$fullFoldPath || strpos($pathFromRoot, rtrim($fullFoldPath, '/\\').DS)===0;
	}

	/**
	 * Builds the link to a declared route, from the path it is declared
	 * with: each $name is replaced by its value, a final * by the value
	 * given as '*', all URL-encoded. In autopath mode, a path without
	 * such parts that no route declares is taken as it is.
	 *
	 * @param String $routShapeTxt Path the route is declared with, such as profiles/$id.
	 * @param Array  $pathPartVals Values of the named parts of the path.
	 * @param Array  $qryParameter Values to add as a query string.
	 * @return String The link, from the root of the site.
	 *
	 * @throws \RuntimeException When no such route is declared, or a named part has no value.
	 */
	public function buildLinkDbCon( String $routShapeTxt, Array $pathPartVals=Array(), Array $qryParameter=Array() ): String {
		$rtePath = trim($routShapeTxt, '/');
		$isKnown = isset($this->loadFilesArray()[$rtePath]);
		$hasVars = (strpos($rtePath, '$')!==false || strpos($rtePath, '*')!==false);
		if( !$isKnown && ($hasVars || ($this->routConfig['detect'] ?? '')!='autopath') ){
			throw new \RuntimeException('No route is declared as "'.$routShapeTxt.'"');
		}
		$segList = Array();
		foreach( explode('/', $rtePath) as $segKey ){
			if( $segKey==='*' ){
				$restVal = trim(strval($pathPartVals['*'] ?? ''), '/');
				$segList[] = implode('/', array_map('rawurlencode', explode('/', $restVal)));
			}elseif( strlen($segKey)>1 && $segKey[0]=='$' ){
				$segText = $pathPartVals[substr($segKey, 1)] ?? null;
				if( !is_scalar($segText) || strval($segText)==='' ){
					throw new \RuntimeException('No value given for "'.$segKey.'" of the route "'.$routShapeTxt.'"');
				}
				$segList[] = rawurlencode(strval($segText));
			}else{
				$segList[] = $segKey;
			}
		}
		// The route declared as index is the root of the site
		$linkTxt = ($isKnown && $rtePath=='index') ? '/' : '/'.implode('/', $segList);
		$qryText = http_build_query($qryParameter, '', '&', PHP_QUERY_RFC3986);
		return $linkTxt.($qryText!='' ? '?'.$qryText : '');
	}
}
