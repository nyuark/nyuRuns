<?php

namespace nyu\Setup;

/**
 * The cache of the configuration, one file per preset in temp/cache: the
 * preset as checked, the config files of conf as read (their env: values
 * left as written, filled on each request), and the declared routes. It
 * is written by the first request of a preset when CNF_CACH is TRUE, and
 * read by the next ones in place of every file it comes from.
 *
 * With TBL_CACH, it also keeps the descriptions of the tables (the
 * EXPLAIN of each), by database: a table described during a request is
 * added at its end. A query failing on a table it described removes it.
 *
 * It is read as a file on each request, never kept by OPcache: a cache
 * removed or written again is seen by the next request, whatever OPcache
 * is set to. Whatever writes in conf removes it (Shell\ConfFile), and so
 * do "config cache", "deploy start" and "cleans purge cache".
 */
class ConfCache{

	// The folder of the cache files, from the project root
	const CACHPATH = 'temp/cache';

	// The descriptions of the tables known for this request, by database and by table; null when they are not kept (TBL_CACH)
	public static $tblInfos = null;
	// Whether descriptions were added during this request, for the cache to be written again at its end
	public static $tblAdded = false;

	/**
	 * Gives the description of a table the config cache keeps (TBL_CACH),
	 * in place of asking the database.
	 *
	 * @param String $dbaseLabel  Name of the database in the config, '' for the default one.
	 * @param String $tableLabel Name of the table.
	 * @return ?Array The description: 'flds', 'pkey' and 'blob'; null when the cache keeps none, or no description of the table.
	 */
	public static function tableInfo( String $dbaseLabel, String $tableLabel ): ?Array {
		if( self::$tblInfos===null || !isset(self::$tblInfos[$dbaseLabel][$tableLabel]) || !is_array(self::$tblInfos[$dbaseLabel][$tableLabel]) ){
			return null;
		}
		return self::$tblInfos[$dbaseLabel][$tableLabel];
	}

	/**
	 * Keeps the description the database gave of a table, for the cache to
	 * hold it from the end of the request (TBL_CACH); nothing is kept when
	 * the descriptions are not.
	 *
	 * @param String $dbaseLabel  Name of the database in the config, '' for the default one.
	 * @param String $tableLabel Name of the table.
	 * @param Array  $tableValue The description: 'flds', 'pkey' and 'blob'.
	 */
	public static function keepTable( String $dbaseLabel, String $tableLabel, Array $tableValue ): Void {
		if( self::$tblInfos===null ){
			return;
		}
		self::$tblInfos[$dbaseLabel][$tableLabel] = $tableValue;
		self::$tblAdded = true;
	}

	/**
	 * Gives the values the cache holds for a preset.
	 *
	 * @param String $presetName Name of the preset, as the Runner is given it ('dfault' when empty).
	 * @return ?Array The values under 'preset', 'config', 'routes' and, when kept, 'tables'; null when there is no cache of the preset, it cannot be read, or the name is not one of a preset.
	 */
	public static function readCache( String $presetName ): ?Array {
		// The name goes into a path : letters, digits, '_' and '-' only, as a preset is named
		$presetName = ($presetName=='') ? 'dfault' : $presetName;
		if( !preg_match('/^[\w\-]+$/D', $presetName) ){
			return null;
		}
		$cchPath = TOP_DIR.DS.str_replace('/', DS, self::CACHPATH).DS.'config-'.$presetName.'.cache';
		$cchText = @file_get_contents($cchPath);
		if( $cchText===false || $cchText=='' ){
			return null;
		}
		$cchVals = @unserialize($cchText, Array('allowed_classes'=>false));
		if( !is_array($cchVals) || !isset($cchVals['preset'], $cchVals['config'], $cchVals['routes']) || !is_array($cchVals['preset']) || !is_array($cchVals['config']) || !is_array($cchVals['routes']) ){
			return null;
		}
		return $cchVals;
	}

	/**
	 * Writes the cache of a preset as a whole: a temporary file, then
	 * renamed, so that a request reads the former cache or the new one.
	 *
	 * @param String $presetName Name of the preset ('dfault' when empty).
	 * @param Array  $cachedVals The values: 'preset', 'config' and 'routes'.
	 * @return Bool Whether it was written; a cache that cannot be written leaves the config read from its files.
	 */
	public static function makeCache( String $presetName, Array $cachedVals ): Bool {
		$presetName = ($presetName=='') ? 'dfault' : $presetName;
		if( !preg_match('/^[\w\-]+$/D', $presetName) || !isset($cachedVals['preset'], $cachedVals['config'], $cachedVals['routes']) ){
			return false;
		}
		$cchPath = TOP_DIR.DS.str_replace('/', DS, self::CACHPATH).DS.'config-'.$presetName.'.cache';
		if( !is_dir(dirname($cchPath)) ){
			@mkdir(dirname($cchPath), 0770, true);
		}
		$tmpPath = $cchPath.'.tmp'.getmypid();
		if( @file_put_contents($tmpPath, serialize($cachedVals), LOCK_EX)===false ){
			return false;
		}
		// On Windows a file being read cannot be replaced: a later request writes it
		if( !@rename($tmpPath, $cchPath) ){
			@unlink($tmpPath);
			return false;
		}
		return true;
	}

	/**
	 * Removes every cache file: the next request of each preset reads its
	 * files, and writes its cache again when CNF_CACH is TRUE.
	 *
	 * @return Int The number of cache files removed.
	 */
	public static function dropCache(): Int {
		// The descriptions this request knows are no longer to be trusted, nor written again
		self::$tblInfos = (self::$tblInfos===null) ? null : Array();
		self::$tblAdded = false;
		$dropNum = 0;
		foreach( (array)glob(TOP_DIR.DS.str_replace('/', DS, self::CACHPATH).DS.'config-*.cache') as $cchFle ){
			if( @unlink(strval($cchFle)) ){
				$dropNum++;
			}
		}
		return $dropNum;
	}
}
