<?php

namespace nyu\Tools;

use nyu\Data\Dcmnt;
use nyu\Output\Response;

/**
 * Resolves the application, action and parameter to run for a request,
 * according to the 'dispatch' block of the active preset.
 */
class Router{

	// Statuses a redirection can be sent with
	const MOVE_LIST = Array(301, 302, 303, 307, 308);
	// The keys a declared route may hold, and those of a target block
	const KEYS_LIST = Array('mthdList', 'pointsTo');
	const TRGT_KEYS = Array('appToRun', 'doAction', 'redirect', 'httpCode', 'filePath', 'fldrPath', 'pageFile');

	protected $routConfig;	// 'dispatch' block of the active preset
	protected $requestObj;	// The shared HTTP request
	protected $rtePathTxt;	// Request path, without its leading slash and query string
	protected $routesBase = '';	// The start of the path the preset is served under, without a final slash ('' for the root)
	protected $routeFiles;	// Declared routes, once read

	/**
	 * Prepares the router for a request: keeps the route configuration and
	 * extracts the path to resolve from the request URI.
	 *
	 * @param Array   $routSettings The 'dispatch' config block of the active preset.
	 * @param Request $givenRequest The shared, already-parsed HTTP request.
	 * @param String  $basePathText The start of the path the preset is served under, from the map of the presets served ('' for the root): taken off the path, put back in front of the links and redirections.
	 */
	public function __construct( Array $routSettings, Request $givenRequest, String $basePathText='' ){
		$this->routConfig = $routSettings;
		$this->requestObj = $givenRequest;
		$this->routesBase = rtrim($basePathText, '/');
		$this->rtePathTxt = $this->cleanRoutePath( strval($givenRequest->obtainUrlValue('fullPath')) );
		// The basePath is taken off whole parts of the path : /api/users/view/12 is routed as users/view/12
		$baseText = ltrim($this->routesBase, '/');
		if( $baseText!=='' && ($this->rtePathTxt===$baseText || str_starts_with($this->rtePathTxt, $baseText.'/')) ){
			$this->rtePathTxt = ltrim(substr($this->rtePathTxt, strlen($baseText)), '/');
		}
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
	 * @return Registry Registry holding the resolved 'appToRun' and 'doAction' values, plus 'argument' and any other mapped or captured key.
	 *
	 * @throws \RuntimeException When the route found is not properly declared.
	 */
	public function locateRouteReg(): Registry {
		// A CORS preflight is answered by the route itself, on any path : no action runs
		if( $this->requestObj->usedMethodFlag('OPTIONS') && $this->requestObj->retrieveHeader('Access-Control-Request-Method')!==null ){
			return new Registry( Array('appToRun'=>null, 'doAction'=>null, 'statCode'=>204, 'statMssg'=>'') );
		}
		$routVals = Array(
			'appToRun'=>null,
			'doAction'=>null
		);
		$dtctMode = $this->routConfig['detectBy'] ?? '';

		// Declared routes come first : the site's, then the Runs'
		$urlTrail = str_replace('.php', '', $this->rtePathTxt);
		$matxList = $this->fileRouteArray($urlTrail);
		if( $matxList!==null ){
			return new Registry( $matxList );
		}

		// Automatic path detection (from url path), for the Runs that declare no routes
		if( $dtctMode=='autopath' ){
			$routVals = array_merge($routVals, $this->autoRouteArray());
			$applName = strval($routVals['appToRun'] ?? '');
			// A Run declaring its routes is closed : its actions are reached through them only
			$runFoldr = \nyu\App\Controller::runFoldrString($applName);
			if( $runFoldr!=='' && is_file(RUN_DIR.DS.$runFoldr.DS.'routes.rte') ){
				Logger::retrieveLogger('runner')->info( 'Not found: '.json_encode($applName.'/'.strval($routVals['doAction'] ?? ''), JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES).': not listed in the routes of the application'.Logger::getActorSuffix() );
				return new Registry( Array('appToRun'=>null, 'doAction'=>null, 'statCode'=>404, 'statMssg'=>'Not Found') );
			}
		}
		return new Registry( $routVals );
	}

	/**
	 * Logs, as warnings on the 'runner' channel, the keys of declared
	 * routes that nothing reads: a key left from another version, or
	 * misspelt, would otherwise be ignored without a word.
	 *
	 * @param Array  $rteRoutesLst The routes of a file, by path.
	 * @param String $fileOfRoutes Name of the file, for the message.
	 * @param Array  $goalKeysList The keys a target block of the file may hold: TRGT_KEYS for the site, 'doAction' alone for a Run.
	 */
	protected function warnsRouteKeys( Array $rteRoutesLst, String $fileOfRoutes, Array $goalKeysList=self::TRGT_KEYS ): Void {
		foreach( $rteRoutesLst as $rteKey => $rteSet ){
			if( !is_array($rteSet) ){
				continue;
			}
			$badClave = array_values( array_diff(array_map('strval', array_keys($rteSet)), self::KEYS_LIST) );
			$trgtVals = $rteSet['pointsTo'] ?? null;
			if( is_array($trgtVals) ){
				// One block, or one per method (a block of a Run names its action alone)
				$blockLst = ($this->targetKindText($trgtVals)!==null || isset($trgtVals['doAction'])) ? Array($trgtVals) : array_filter($trgtVals, 'is_array');
				foreach( $blockLst as $blkVal ){
					foreach( array_diff(array_map('strval', array_keys($blkVal)), $goalKeysList) as $tgtKey ){
						$badClave[] = 'pointsTo.'.$tgtKey;
					}
				}
			}
			foreach( array_unique($badClave) as $badKey ){
				Logger::retrieveLogger('runner')->warning( 'Route '.json_encode(strval($rteKey)).' of '.$fileOfRoutes.' holds an unknown key: '.$badKey );
			}
		}
	}

	/**
	 * Strips the leading slash and the query string from a request URI.
	 *
	 * @param String $reqstUriText The request URI, as received.
	 * @return String The path to resolve.
	 */
	protected function cleanRoutePath( String $reqstUriText ): String {
		$quoteMrk = strpos($reqstUriText, '?');
		if( $quoteMrk!==false ){
			return substr($reqstUriText, 1, $quoteMrk-1);
		}
		return substr($reqstUriText, 1);
	}

	/**
	 * Maps the segments of the path onto the keys of the configured mapper
	 * (such as $appToRun/$doAction/$argument), by position.
	 *
	 * @return Array The mapper keys with their path segment, null when the path is shorter.
	 */
	protected function autoRouteArray(): Array {
		$urlParte = explode( '/', $this->rtePathTxt );
		$mapTrail = str_replace( '$', '', $this->routConfig['pathForm'] ?? '' );
		$mapParte = explode( '/', $mapTrail );
		$mapsList = Array();

		foreach( $mapParte as $mapIdx => $mapKey ){
			$mapsList[$mapKey] = $urlParte[$mapIdx] ?? null;
		}
		return $mapsList;
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
		$routeDct = $this->loadFilesArray();
		foreach( Array(true, false) as $ofSite ){
			if( isset($routeDct[$pathToLookUp]) && (strval($routeDct[$pathToLookUp]['routOwnr'] ?? 'site')=='site')===$ofSite ){
				return $this->generateValues( $routeDct[$pathToLookUp], Array(), $pathToLookUp );
			}
			foreach( $routeDct as $rteKey => $rteSet ){
				if( (strval($rteSet['routOwnr'] ?? 'site')=='site')!==$ofSite ){
					continue;
				}
				$captVals = $this->matchRoutePath( strval($rteKey), $pathToLookUp );
				if( $captVals!==null ){
					return $this->generateValues( $rteSet, $captVals, strval($rteKey) );
				}
			}
		}
		return null;
	}

	/**
	 * Reads the declared routes: those of the site, every route file of the
	 * routes folder (conf/routes, or the one named by the 'routeDir' key of the
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
		$routeDct = Array();
		$drctPath = TOP_DIR.DS.($this->routConfig['routeDir'] ?? 'conf'.DS.'routes');
		if( is_dir($drctPath) ){
			foreach( glob($drctPath.DS.'*.rte') as $rteFil ){
				// Plain JSON (Parsers\Index), the keys kept as written
				$routeFle = new Dcmnt($rteFil, false);
				$fileDict = $routeFle->readFileDetail('out');
				if( is_array($fileDict) ){
					$this->warnsRouteKeys( $fileDict, basename(strval($rteFil)) );
					$routeDct = $routeDct + $fileDict;
				}
			}
		}
		$siteRout = Array();
		foreach( $routeDct as $rteKey => $rteSet ){
			$routePth = trim(strval($rteKey), '/');
			if( is_array($rteSet) && !isset($siteRout[$routePth]) ){
				$siteRout[$routePth] = Array('routOwnr'=>'site') + $rteSet;
			}
		}
		foreach( $this->runRoutesArray() as $runRte ){
			$routePth = strval($runRte['routPath']);
			if( isset($siteRout[$routePth]) ){
				if( $siteRout[$routePth]['routOwnr']=='site' ){
					Logger::retrieveLogger('runner')->warning( 'Route '.json_encode('/'.$routePth, JSON_UNESCAPED_SLASHES).' of the application '.$runRte['routOwnr'].' is also declared by the site: the site\'s answers' );
				}else{
					Logger::retrieveLogger('runner')->error( 'Route '.json_encode('/'.$routePth, JSON_UNESCAPED_SLASHES).' is declared by the applications '.$siteRout[$routePth]['routOwnr'].' and '.$runRte['routOwnr'].': '.$siteRout[$routePth]['routOwnr'].' answers' );
				}
				continue;
			}
			// A pattern of the site over it answers first : told as well
			foreach( array_keys(array_combine(array_keys($siteRout), array_column($siteRout, 'routOwnr')), 'site', true) as $rteKey ){
				if( $this->matchRoutePath(strval($rteKey), $routePth)!==null ){
					Logger::retrieveLogger('runner')->warning( 'Route '.json_encode('/'.$routePth, JSON_UNESCAPED_SLASHES).' of the application '.$runRte['routOwnr'].' falls under the site route '.json_encode('/'.$rteKey, JSON_UNESCAPED_SLASHES).': the site\'s answers' );
					break;
				}
			}
			unset($runRte['routPath']);
			$siteRout[$routePth] = $runRte;
		}
		$this->routeFiles = $siteRout;
		return $siteRout;
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
	 * that Run with 'doAction' alone (its 'appToRun' is implied; any other key of a
	 * target is warned as unknown). A target naming a redirection, a file or
	 * a page is left out, not served: a whole route for one block, the
	 * method alone in a block per method. A path is moved under the mount of
	 * the Run (see mountPath), except one with a leading slash, from the root
	 * of the site. A Run the preset does not mount serves none.
	 *
	 * @return Array The routes, in a list: each its declaration with its final path under 'routPath' (without its slashes), its Run under 'routOwnr' and the path its file declares it with under 'routDecl' (without its slashes).
	 */
	public function runRoutesArray(): Array {
		$rowsList = Array();
		if( !defined('RUN_DIR') ){
			return $rowsList;
		}
		foreach( (array)glob(RUN_DIR.DS.'*'.DS.'routes.rte') as $rteFil ){
			$applName = basename(dirname(strval($rteFil)));
			$mountPth = $this->mountRoutePath($applName);
			if( $mountPth===null || !preg_match('/^[A-Za-z0-9]+$/D', $applName) ){
				continue;
			}
			$fileDict = (new Dcmnt(strval($rteFil), false))->readFileDetail('out');
			if( !is_array($fileDict) ){
				continue;
			}
			$this->warnsRouteKeys( $fileDict, $applName.'/routes.rte', Array('doAction') );
			foreach( $fileDict as $rteKey => $rteSet ){
				if( !is_array($rteSet) ){
					continue;
				}
				$rteKey = strval($rteKey);
				$routePth = (substr($rteKey, 0, 1)=='/') ? trim($rteKey, '/') : trim($mountPth.'/'.trim($rteKey, '/'), '/');
				$trgtVals = $rteSet['pointsTo'] ?? null;
				if( !is_array($trgtVals) ){
					continue;
				}
				// A Run serves its own actions only : a target naming a redirection, a file or a page is left out (warned by warnsKeys)
				if( !empty(array_intersect(array_map('strval', array_keys($trgtVals)), array_merge(self::TRGT_KEYS, Array('doAction')))) ){
					// One block for every method
					if( !empty(array_intersect(array_keys($trgtVals), array_diff(self::TRGT_KEYS, Array('appToRun', 'doAction')))) ){
						continue;
					}
					$trgtVals['appToRun'] = $applName;
				}else{
					// One block per method, each checked
					foreach( $trgtVals as $mthNme => $target ){
						if( !is_array($target) || !empty(array_intersect(array_keys($target), array_diff(self::TRGT_KEYS, Array('appToRun', 'doAction')))) ){
							unset($trgtVals[$mthNme]);
							continue;
						}
						$trgtVals[$mthNme]['appToRun'] = $applName;
					}
					if( empty($trgtVals) ){
						continue;
					}
				}
				$rteSet['pointsTo'] = $trgtVals;
				$rowsList[] = Array('routPath'=>$routePth, 'routOwnr'=>$applName, 'routDecl'=>trim($rteKey, '/')) + $rteSet;
			}
		}
		return $rowsList;
	}

	/**
	 * Gives the mount of a Run: the path its routes are moved under, from
	 * the 'runMount' key of the route configuration, { "<run>": false |
	 * "<prefix>" }, the Run named whatever its case. "*" names every Run
	 * the key does not name on its own line. A Run neither names is mounted
	 * under its name in lower case.
	 *
	 * @param String $appNameToRun Name of the Run.
	 * @return ?String The prefix, without its slashes (empty for the root of the site); null when the Run's routes are not served.
	 */
	public function mountRoutePath( String $appNameToRun ): ?String {
		$mountLst = $this->routConfig['runMount'] ?? Array();
		$mountLst = is_array($mountLst) ? $mountLst : Array();
		foreach( $mountLst as $mntKey => $mntVal ){
			if( strval($mntKey)==='*' || strcasecmp(strval($mntKey), $appNameToRun)!=0 ){
				continue;
			}
			return ($mntVal===false) ? null : trim(strval($mntVal), '/');
		}
		// Every Run not named on its own line
		if( array_key_exists('*', $mountLst) ){
			return ($mountLst['*']===false) ? null : trim(strval($mountLst['*']), '/');
		}
		return strtolower($appNameToRun);
	}

	/**
	 * Tells whether a request can reach an action through this
	 * configuration: a declared route to an action (the site's, or a
	 * mounted Run's), or the automatic paths ('autopath') with a Run that
	 * declares no routes of its own.
	 *
	 * @return Bool True when an action can be run.
	 */
	public function reachesActions(): Bool {
		foreach( $this->allRoutesArray() as $rteRow ){
			if( ($rteRow['routKind'] ?? '')=='toAction' ){
				return true;
			}
		}
		if( ($this->routConfig['detectBy'] ?? '')!='autopath' || !defined('RUN_DIR') ){
			return false;
		}
		// A Run declaring its routes is closed to the automatic paths
		foreach( (array)glob(RUN_DIR.DS.'*', GLOB_ONLYDIR) as $runDir ){
			if( is_file(strval($runDir).DS.'Actions.php') && !is_file(strval($runDir).DS.'routes.rte') ){
				return true;
			}
		}
		return false;
	}

	/**
	 * Takes the declared routes already read (the setting clrCache), in place
	 * of the files of the routes folder.
	 *
	 * @param Array $rteRoutesLst The declared routes, by path or path pattern, as retrieveRoutes() gives them.
	 */
	public function assignRouteSet( Array $rteRoutesLst ): Void {
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
	 * @return Array The entries, each with the path under 'routPath', its 'mthdList', its 'routKind' (toAction, redirect, toStatic or htmlPage), what it answers with under 'routTarg', its owner under 'routOwnr' ('site' or a Run) and, for an action, its 'appToRun' and 'doAction'.
	 */
	public function allRoutesArray(): Array {
		$rowsList = Array();
		foreach( $this->loadFilesArray() as $rteKey => $rteSet ){
			$trgtVals = is_array($rteSet) ? ($rteSet['pointsTo'] ?? null) : null;
			if( !is_array($trgtVals) ){
				continue;
			}
			$allowLst = array_filter( array_map('trim', explode(',', strtoupper(strval($rteSet['mthdList'] ?? '')))) );
			if( $this->targetKindText($trgtVals)!==null ){
				$mthdList = array_diff($allowLst, Array('HEAD', 'OPTIONS'));
				$trgtVals = array_fill_keys( empty($mthdList) ? Array('GET') : $mthdList, $trgtVals );
			}else{
				$trgtVals = array_filter( array_change_key_case($trgtVals, CASE_UPPER), 'is_array' );
			}
			// The methods answered by the same target are told together
			$goalList = Array();
			foreach( $trgtVals as $mthNme => $target ){
				$routeCod = $this->targetKindText($target);
				if( $routeCod===null ){
					continue;
				}
				$goalText = strval($target['redirect'] ?? $target['pageFile'] ?? $target['filePath'] ?? ( isset($target['fldrPath']) ? rtrim(strval($target['fldrPath']), '/').'/*' : '' ));
				if( $routeCod=='toAction' ){
					$goalText = strval($target['appToRun']).'/'.strval($target['doAction'] ?? '');
				}
				$goalClef = $routeCod.' '.$goalText;
				if( !isset($goalList[$goalClef]) ){
					$goalList[$goalClef] = Array('routPath'=>trim(strval($rteKey), '/'), 'mthdList'=>Array(), 'routKind'=>$routeCod, 'routTarg'=>$goalText, 'appToRun'=>($routeCod=='toAction') ? strval($target['appToRun']) : null, 'doAction'=>($routeCod=='toAction') ? strval($target['doAction'] ?? '') : null, 'routOwnr'=>strval($rteSet['routOwnr'] ?? 'site'));
				}
				$goalList[$goalClef]['mthdList'][] = strval($mthNme);
			}
			foreach( $goalList as $goalRw ){
				$rowsList[] = $goalRw;
			}
		}
		return $rowsList;
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
		$keyParte = explode('/', trim($routShapeTxt, '/'));
		$urlParte = explode('/', $pathToLookUp);
		$restVals = null;
		if( end($keyParte)==='*' ){
			// The rest of the path may be empty : the folder itself is asked for
			array_pop($keyParte);
			if( count($urlParte)<count($keyParte) ){
				return null;
			}
			$restVals = implode('/', array_slice($urlParte, count($keyParte)));
			$urlParte = array_slice($urlParte, 0, count($keyParte));
		}
		if( count($keyParte)!=count($urlParte) ){
			return null;
		}
		$captVals = Array();
		foreach( $keyParte as $segInc => $segKey ){
			$sgmtText = $urlParte[$segInc];
			if( strlen($segKey)>1 && $segKey[0]=='$' ){
				if( $sgmtText==='' ){
					return null;
				}
				$captVals[substr($segKey, 1)] = rawurldecode($sgmtText);
			}elseif( $segKey!==$sgmtText ){
				return null;
			}
		}
		if( $restVals!==null ){
			$captVals['*'] = rawurldecode($restVals);
		}
		return $captVals;
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
	 * or folder ('fldrPath'), or an HTML page ('pageFile'). Those are served for HEAD
	 * as they are for GET.
	 *
	 * @param Array  $routEntryLst The declared route, with its 'pointsTo' block.
	 * @param Array  $capturedVals Values captured from the path, by name.
	 * @param String $routShapeTxt Path the route is declared with.
	 * @return ?Array The 'appToRun', 'doAction', 'argument' and 'pathVars' values, or 'statCode', 'statMssg' and 'allowHdr' when no action is to run, or the 'routTarg' of another kind of reply; always with 'routKind', 'routPath' and 'routOwnr' ('site' or the Run declaring it). Null when the route names no target.
	 *
	 * @throws \RuntimeException When the target is not properly declared.
	 */
	protected function generateValues( Array $routEntryLst, Array $capturedVals = Array(), String $routShapeTxt = '' ): ?Array {
		$trgtVals = $routEntryLst['pointsTo'] ?? Array();
		if( !is_array($trgtVals) ){
			return null;
		}
		$allowLst = array_filter( array_map('trim', explode(',', strtoupper(strval($routEntryLst['mthdList'] ?? '')))) );
		$extraLst = array_intersect($allowLst, Array('HEAD', 'OPTIONS'));

		// Targets by method : a general target serves the allowed methods, GET when none is listed
		if( $this->targetKindText($trgtVals)!==null ){
			$mthdList = array_diff($allowLst, $extraLst);
			$trgtVals = array_fill_keys( empty($mthdList) ? Array('GET') : $mthdList, $trgtVals );
		}else{
			$trgtVals = array_filter( array_change_key_case($trgtVals, CASE_UPPER), 'is_array' );
		}
		if( empty($trgtVals) ){
			return null;
		}
		// What GET gets without running an action, HEAD gets too
		if( isset($trgtVals['GET']) && !in_array($this->targetKindText($trgtVals['GET']), Array(null, 'toAction'), true) ){
			$extraLst[] = 'HEAD';
		}
		$allowLst = array_unique( array_merge(array_map('strval', array_keys($trgtVals)), $extraLst) );
		$routeBas = Array('appToRun'=>null, 'doAction'=>null, 'routPath'=>trim($routShapeTxt, '/'), 'routOwnr'=>strval($routEntryLst['routOwnr'] ?? 'site'));

		$usedMthd = null;
		foreach( $allowLst as $mthNme ){
			if( $this->requestObj->usedMethodFlag($mthNme) ){
				$usedMthd = $mthNme;
			}
		}
		$applDict = ($usedMthd!==null) ? ($trgtVals[$usedMthd] ?? null) : null;
		if( $applDict===null && $usedMthd=='HEAD' ){
			$applDict = $trgtVals['GET'] ?? null;
		}
		if( $applDict===null ){
			// OPTIONS is answered with the accepted methods, anything else is refused
			$optiFlag = ($usedMthd=='OPTIONS');
			return Array(
				'statCode'=>$optiFlag ? 204 : 405,
				'statMssg'=>$optiFlag ? '' : 'Method Not Allowed',
				'allowHdr'=>	implode(', ', $allowLst)
			) + $routeBas;
		}
		$routeCod = $this->targetKindText($applDict);
		if( $routeCod=='redirect' ){
			return $this->redirectValues($applDict, $capturedVals) + $routeBas;
		}
		if( $routeCod=='toStatic' || $routeCod=='htmlPage' ){
			return $this->staticValueArr($applDict, $capturedVals) + $routeBas;
		}
		if( empty($applDict['appToRun']) ){
			return null;
		}
		return Array(
			'appToRun'=>		$applDict['appToRun'],
			'doAction'=>	$applDict['doAction'] ?? null,
			'argument'=>	empty($capturedVals) ? null : strval(reset($capturedVals)),
			'pathVars'=>$capturedVals,
			'routKind'=>	'toAction'
		) + $routeBas;
	}

	/**
	 * Tells what a target block names, from its keys.
	 *
	 * @param Array $targetValues One target block.
	 * @return ?String 'toAction', 'redirect', 'htmlPage' or 'toStatic'; null when the block names none of them.
	 */
	protected function targetKindText( Array $targetValues ): ?String {
		if( isset($targetValues['appToRun']) ){
			return 'toAction';
		}
		if( isset($targetValues['redirect']) ){
			return 'redirect';
		}
		if( isset($targetValues['pageFile']) ){
			return 'htmlPage';
		}
		if( isset($targetValues['filePath']) || isset($targetValues['fldrPath']) ){
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
	 * @return Array The 'routKind', 'routTarg' and 'routStat' values.
	 *
	 * @throws \RuntimeException When the address or the status is not one of a redirection.
	 */
	protected function redirectValues( Array $targetValues, Array $capturedVals ): Array {
		$moveCode = intval($targetValues['httpCode'] ?? 302);
		$moveTrgt = strval( preg_replace_callback('/\$(\w+)/', function( Array $matchedArray ) use ( $capturedVals ): String {
			return isset($capturedVals[$matchedArray[1]]) ? rawurlencode(strval($capturedVals[$matchedArray[1]])) : $matchedArray[0];
		}, strval($targetValues['redirect'])) );
		if( !in_array($moveCode, self::MOVE_LIST, true) || !self::validRouteAddr($moveTrgt) ){
			throw new \RuntimeException('Route misconfigured: "'.strval($targetValues['redirect']).'" with the status '.$moveCode.' is not a redirection');
		}
		// A path of this site is served under the basePath of the preset; a full address is left as it is
		if( str_starts_with($moveTrgt, '/') && !str_starts_with($moveTrgt, '//') ){
			$moveTrgt = $this->routesBase.$moveTrgt;
		}
		return Array('routKind'=>'redirect', 'routTarg'=>$moveTrgt, 'routStat'=>$moveCode);
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
	 * configuration names as 'fileRoot': without it, nothing is served. The
	 * file asked for in a folder is the rest of the path; it cannot be
	 * outside that folder, nor be a hidden file, and its extension must
	 * have a content type (see Response::TYPE_LIST). A folder gives its
	 * index.html, never its listing.
	 *
	 * @param Array $targetValues The target block, with its 'filePath', 'fldrPath' or 'pageFile'.
	 * @param Array $capturedVals Values captured from the path, by name.
	 * @return Array The 'routKind', 'routTarg' and 'routType' values, or a 404 as 'statCode' and 'statMssg'.
	 *
	 * @throws \RuntimeException When the target is outside the 'fileRoot' folder, or a page is not an HTML file.
	 */
	protected function staticValueArr( Array $targetValues, Array $capturedVals ): Array {
		$missDict = Array('statCode'=>404, 'statMssg'=>'Not Found', 'routKind'=>'toStatic');
		$rootConf = strval($this->routConfig['fileRoot'] ?? '');
		$rootPath = ($rootConf!=='') ? realpath(TOP_DIR.DS.$rootConf) : false;
		if( $rootPath===false || !is_dir($rootPath) ){
			return $missDict;
		}
		$goToPage = isset($targetValues['pageFile']);
		$routeNme = strval($targetValues['pageFile'] ?? $targetValues['filePath'] ?? $targetValues['fldrPath']);
		$foundIdx = realpath($rootPath.DS.$routeNme);
		if( $foundIdx===false ){
			return $missDict;
		}
		if( !$this->liesRouteInDir($foundIdx, $rootPath) ){
			throw new \RuntimeException('Route misconfigured: "'.$routeNme.'" is outside the folder of the static files');
		}
		if( !$goToPage && !isset($targetValues['filePath']) ){
			// A folder : the file is named by the rest of the path
			$routePth = $foundIdx;
			$restPath = strval($capturedVals['*'] ?? '');
			if( !is_dir($routePth) || ($restPath!=='' && !$this->securePartsFlg($restPath)) ){
				return $missDict;
			}
			if( $restPath!=='' ){
				$foundIdx = realpath($routePth.DS.str_replace('/', DS, $restPath));
			}
			if( $foundIdx!==false && is_dir($foundIdx) ){
				$foundIdx = realpath($foundIdx.DS.'index.html');
			}
			// Checked on the real path : a link cannot lead out of the folder either
			if( $foundIdx===false || !$this->liesRouteInDir($foundIdx, $routePth) ){
				return $missDict;
			}
			// The real names below the folder too : a short name (ENV~1 on Windows) can stand for a hidden file
			$realRest = str_replace(DS, '/', substr($foundIdx, strlen(rtrim($routePth, '/\\')) + 1));
			if( $realRest!=='' && $realRest!==false && !$this->securePartsFlg($realRest) ){
				return $missDict;
			}
		}
		$mimeText = Response::mimeOfExtnsTxt( pathinfo($foundIdx, PATHINFO_EXTENSION) );
		if( !is_file($foundIdx) || $mimeText===null ){
			return $missDict;
		}
		if( $goToPage ){
			if( strpos($mimeText, 'text/html')!==0 ){
				throw new \RuntimeException('Route misconfigured: "'.$routeNme.'" is not an HTML page');
			}
			return Array('routKind'=>'htmlPage', 'routTarg'=>$foundIdx, 'pathVars'=>$capturedVals);
		}
		return Array('routKind'=>'toStatic', 'routTarg'=>$foundIdx, 'routType'=>$mimeText);
	}

	/**
	 * Gives the HTML page a site answers an error status with: the file
	 * errors/<status>.html of the folder the route configuration names as
	 * 'fileRoot', when it holds one.
	 *
	 * @param Int $responseCode The status of the reply.
	 * @return ?String The full path of the page, or null when the site has none for that status.
	 */
	public function errorPgeString( Int $responseCode ): ?String {
		$rootConf = strval($this->routConfig['fileRoot'] ?? '');
		$rootPath = ($rootConf!=='') ? realpath(TOP_DIR.DS.$rootConf) : false;
		if( $rootPath===false || !is_dir($rootPath) ){
			return null;
		}
		$pagePath = realpath($rootPath.DS.'errors'.DS.$responseCode.'.html');
		return ( $pagePath!==false && is_file($pagePath) && $this->liesRouteInDir($pagePath, $rootPath) ) ? $pagePath : null;
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
	 * @param String $fullFldrPath The folder, as realpath() gives it.
	 * @return Bool True when the path is the folder itself or inside it.
	 */
	protected function liesRouteInDir( String $pathFromRoot, String $fullFldrPath ): Bool {
		return $pathFromRoot===$fullFldrPath || strpos($pathFromRoot, rtrim($fullFldrPath, '/\\').DS)===0;
	}

	/**
	 * Builds the link to a declared route, from the path it is declared
	 * with: each $name is replaced by its value, a final * by the value
	 * given as '*', all URL-encoded. In autopath mode, a path without
	 * such parts that no route declares is taken as it is.
	 *
	 * @param String $routShapeTxt Path the route is declared with, such as profiles/$id.
	 * @param Array  $pathPartVals Values of the named parts of the path.
	 * @param Array  $linkQueryLst Values to add as a query string.
	 * @return String The link, from the root of the site.
	 *
	 * @throws \RuntimeException When no such route is declared, or a named part has no value.
	 */
	public function buildRouteLink( String $routShapeTxt, Array $pathPartVals=Array(), Array $linkQueryLst=Array() ): String {
		$routePth = trim($routShapeTxt, '/');
		$isListed = isset($this->loadFilesArray()[$routePth]);
		$hasVarbl = (strpos($routePth, '$')!==false || strpos($routePth, '*')!==false);
		if( !$isListed && ($hasVarbl || ($this->routConfig['detectBy'] ?? '')!='autopath') ){
			throw new \RuntimeException('No route is declared as "'.$routShapeTxt.'"');
		}
		$sgmtList = Array();
		foreach( explode('/', $routePth) as $segKey ){
			if( $segKey==='*' ){
				$restVals = trim(strval($pathPartVals['*'] ?? ''), '/');
				$sgmtList[] = implode('/', array_map('rawurlencode', explode('/', $restVals)));
			}elseif( strlen($segKey)>1 && $segKey[0]=='$' ){
				$sgmtText = $pathPartVals[substr($segKey, 1)] ?? null;
				if( !is_scalar($sgmtText) || strval($sgmtText)==='' ){
					throw new \RuntimeException('No value given for "'.$segKey.'" of the route "'.$routShapeTxt.'"');
				}
				$sgmtList[] = rawurlencode(strval($sgmtText));
			}else{
				$sgmtList[] = $segKey;
			}
		}
		// The route declared as index is the root of the site; the basePath the preset is served under goes in front
		$linkText = $this->routesBase.( ($isListed && $routePth=='index') ? '/' : '/'.implode('/', $sgmtList) );
		$queryTxt = http_build_query($linkQueryLst, '', '&', PHP_QUERY_RFC3986);
		return $linkText.($queryTxt!='' ? '?'.$queryTxt : '');
	}
}
