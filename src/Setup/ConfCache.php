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
 * do "setting clrCache", "install complete" and "cleanup cleanDir cache".
 */
class ConfCache{

	// The folder of the cache files, from the project root
	const CACH_PATH = 'temp/cache';

	// The descriptions of the tables known for this request, by database and by table; null when they are not kept (TBL_CACH)
	public static $tableInfos = null;
	// Whether descriptions were added during this request, for the cache to be written again at its end
	public static $tableAdded = false;

	/**
	 * Gives the description of a table the config cache keeps (TBL_CACH),
	 * in place of asking the database.
	 *
	 * @param String $linkNameText  Name of the database in the config, '' for the default one.
	 * @param String $tbleLabelTxt Name of the table.
	 * @return ?Array The description: 'flds', 'pkey' and 'blob'; null when the cache keeps none, or no description of the table.
	 */
	public static function tableInfoArray( String $linkNameText, String $tbleLabelTxt ): ?Array {
		if( self::$tableInfos===null || !isset(self::$tableInfos[$linkNameText][$tbleLabelTxt]) || !is_array(self::$tableInfos[$linkNameText][$tbleLabelTxt]) ){
			return null;
		}
		return self::$tableInfos[$linkNameText][$tbleLabelTxt];
	}

	/**
	 * Keeps the description the database gave of a table, for the cache to
	 * hold it from the end of the request (TBL_CACH); nothing is kept when
	 * the descriptions are not.
	 *
	 * @param String $linkNameText  Name of the database in the config, '' for the default one.
	 * @param String $tbleLabelTxt Name of the table.
	 * @param Array  $tbleValueArr The description: 'flds', 'pkey' and 'blob'.
	 */
	public static function keepCacheTable( String $linkNameText, String $tbleLabelTxt, Array $tbleValueArr ): Void {
		if( self::$tableInfos===null ){
			return;
		}
		self::$tableInfos[$linkNameText][$tbleLabelTxt] = $tbleValueArr;
		self::$tableAdded = true;
	}

	/**
	 * Gives the values the cache holds for a preset.
	 *
	 * @param String $nameOfPreset Name of the preset, as the Runner is given it ('default' when empty).
	 * @return ?Array The values under 'preset', 'config', 'routes' and, when kept, 'tables'; null when there is no cache of the preset, it cannot be read, or the name is not one of a preset.
	 */
	public static function readCacheArray( String $nameOfPreset ): ?Array {
		// The name goes into a path : letters, digits, '_' and '-' only, as a preset is named
		$nameOfPreset = ($nameOfPreset=='') ? 'default' : $nameOfPreset;
		if( !preg_match('/^[\w\-]+$/D', $nameOfPreset) ){
			return null;
		}
		$cachePth = TOP_DIR.DS.str_replace('/', DS, self::CACH_PATH).DS.'config-'.$nameOfPreset.'.cache';
		$cacheTxt = @file_get_contents($cachePth);
		if( $cacheTxt===false || $cacheTxt=='' ){
			return null;
		}
		$cacheVal = @unserialize($cacheTxt, Array('allowed_classes'=>false));
		if( !is_array($cacheVal) || !isset($cacheVal['preset'], $cacheVal['config'], $cacheVal['routes']) || !is_array($cacheVal['preset']) || !is_array($cacheVal['config']) || !is_array($cacheVal['routes']) ){
			return null;
		}
		return $cacheVal;
	}

	/**
	 * Writes the cache of a preset as a whole: a temporary file, then
	 * renamed, so that a request reads the former cache or the new one.
	 *
	 * @param String $nameOfPreset Name of the preset ('default' when empty).
	 * @param Array  $cachedValues The values: 'preset', 'config' and 'routes'.
	 * @return Bool Whether it was written; a cache that cannot be written leaves the config read from its files.
	 */
	public static function buildCacheFlag( String $nameOfPreset, Array $cachedValues ): Bool {
		$nameOfPreset = ($nameOfPreset=='') ? 'default' : $nameOfPreset;
		if( !preg_match('/^[\w\-]+$/D', $nameOfPreset) || !isset($cachedValues['preset'], $cachedValues['config'], $cachedValues['routes']) ){
			return false;
		}
		$cachePth = TOP_DIR.DS.str_replace('/', DS, self::CACH_PATH).DS.'config-'.$nameOfPreset.'.cache';
		if( !is_dir(dirname($cachePth)) ){
			@mkdir(dirname($cachePth), 0770, true);
		}
		$tempFile = $cachePth.'.tmp'.getmypid();
		if( @file_put_contents($tempFile, serialize($cachedValues), LOCK_EX)===false ){
			return false;
		}
		// On Windows a file being read cannot be replaced: a later request writes it
		if( !@rename($tempFile, $cachePth) ){
			@unlink($tempFile);
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
	public static function clearConfCache(): Int {
		// The descriptions this request knows are no longer to be trusted, nor written again
		self::$tableInfos = (self::$tableInfos===null) ? null : Array();
		self::$tableAdded = false;
		$dropNumb = 0;
		foreach( (array)glob(TOP_DIR.DS.str_replace('/', DS, self::CACH_PATH).DS.'config-*.cache') as $cchFil ){
			if( @unlink(strval($cchFil)) ){
				$dropNumb++;
			}
		}
		return $dropNumb;
	}
}
