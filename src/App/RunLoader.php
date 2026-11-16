<?php

namespace nyu\App;

/**
 * The loader of the classes of the Runs (nyu\Runs\...), registered after
 * Composer's own by src/App/loadruns.php: the core and the Runs ask for a
 * class of a Run by its name, with no require_once of their own.
 */
class RunLoader {

	/**
	 * Loads the file of a class of the Runs, when it is one of these and its
	 * file is there:
	 *   nyu\Runs\<App>\Actions, nyu\Runs\<App>\Shell   <Folder>/Actions.php, <Folder>/Shell.php
	 *   nyu\Runs\<App>\mods\<Name>                     <Folder>/mods/<Name>Model.php, then <Folder>/mods/<Name>.php
	 *   nyu\Runs\<App>\<Name>                          <Folder>/<Name>.php
	 *   nyu\Runs\Shared\<Name>                         Shared/<Name>Model.php, then Shared/<Name>.php
	 * <Folder> is the folder Controller::findRunFldrNme() gives for <App>. The
	 * first file found is the one loaded. Every part of the name is letters
	 * and digits only: a part that is not, a deeper name, a missing file or no
	 * RUN_DIR loads nothing and throws nothing. RUN_DIR is read here, when a
	 * class is asked for.
	 *
	 * @param String $classNameTxt Full name of the class asked for.
	 * @return Void
	 */
	public static function loadsRunsClass( String $classNameTxt ): Void {
		$partList = explode('\\', ltrim($classNameTxt, '\\'));
		if( !defined('RUN_DIR') || !preg_match('/^nyu\\\\Runs(\\\\[A-Za-z0-9]+){2,3}$/D', implode('\\', $partList)) ){
			return;
		}
		$applName = $partList[2];
		$baseName = $partList[count($partList)-1];
		if( strcasecmp($applName, 'Shared')===0 ){
			// The shared classes have no folder of their own under Shared
			if( count($partList)!==4 ){
				return;
			}
			$fldrName = 'Shared';
			$fileList = Array($baseName.'Model.php', $baseName.'.php');
		}else{
			$fldrName = Controller::findRunFldrNme($applName);
			if( $fldrName==='' ){
				return;
			}
			$fileList = Array($baseName.'.php');
			if( count($partList)===5 ){
				// A deeper name is a model of the Run, and nothing else
				if( $partList[3]!=='mods' ){
					return;
				}
				$fileList = Array('mods'.DIRECTORY_SEPARATOR.$baseName.'Model.php', 'mods'.DIRECTORY_SEPARATOR.$baseName.'.php');
			}
		}
		foreach( $fileList as $onePth ){
			$fullPath = RUN_DIR.DIRECTORY_SEPARATOR.$fldrName.DIRECTORY_SEPARATOR.$onePth;
			if( is_file($fullPath) ){
				// A file its Run has already required is not read again
				require_once $fullPath;
				return;
			}
		}
	}
}
