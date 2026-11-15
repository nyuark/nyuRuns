<?php

namespace nyu\Shell\Cmds;

use nyu\Shell\Command;
use nyu\Shell\ConfFile;
use nyu\Shell\RunMaker;
use nyu\Shell\RunSpec;
use nyu\Access\Checker;
use nyu\App\Controller;
use nyu\Data\DBCon;
use nyu\Tools\StrKit;

/**
 * Generates a CRUDL Run from an existing table of an SQL database:
 *
 *   php nyuRun makerun crudlRun Shows     asks, writes runs/Shows/runspec.json, then the Run
 *   php nyuRun makerun fromSpec Shows     writes the Run again from its spec, without a question
 *   php nyuRun makerun fullList           lists the Runs that have a spec
 *
 * The questions fill the spec (see RunSpec); the generator reads only the
 * spec (see RunMaker). --noPrompt takes the defaults; a file that exists is
 * never replaced unasked, --yesToAll answers yes beforehand. Every file
 * written is traced on the 'shell' log channel. The least role and the
 * mount are printed for the preset, never written into it.
 *
 * Not in 1.0: creating the table, MongoDB, grandchildren, many-to-many
 * links, a table linked to itself (parent_id), writing the children through
 * their parent (a child gets a Run of its own: generate it apart), and
 * generating again over files changed by hand.
 */
class RunGen extends Command{

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function renderSynopsis(): String {
		return 'Generates a CRUDL Run from an existing SQL table, asked step by step (not in 1.0: creating tables, MongoDB, grandchildren, many-to-many and parent_id links, writing children through their parent, generating over files changed by hand)';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function listSubCmndDct(): Array {
		return Array(
			'crudlRun'=>Array('args'=>'<App> [--noPrompt] [--yesToAll]', 'about'=>'asks the database, the table, its key, fields, actions, children, least role and mount, writes runs/<App>/runspec.json, then the Run; a Run that exists is refused'),
			'fromSpec'=>Array('args'=>'<App> [--noPrompt] [--yesToAll]', 'about'=>'writes the Run again from its runspec.json, without a question; each file that exists is replaced only when agreed (files changed by hand are not merged)'),
			'fullList'=>Array('args'=>'', 'about'=>'lists the Runs that have a spec: database, table, actions, children')
		);
	}

	/**
	 * Asks for a Run step by step, writes its spec, then the Run.
	 *
	 * @return Int The exit code.
	 */
	public function invokeCrudlRun(): Int {
		$applName = $this->promptRunFoldr();
		$baseName = $this->promptDatabase();
		$tableNme = $this->promptTableNme($baseName, strtolower($applName));
		$dtbsHndr = DBCon::linkNamedDbCon($baseName)->readConnection();
		$tableDct = $dtbsHndr->tableInfoArray($tableNme);
		$idColumn = $this->promptIdColumn($tableNme, is_array($tableDct) ? $tableDct : Array());
		$specList = Array(
			'appsName'=>$applName,
			'database'=>$baseName,
			'tableRef'=>$tableNme,
			'idColumn'=>$idColumn,
			'fieldSet'=>$this->promptFldsDict($baseName, $tableNme, $idColumn, (array)($tableDct['blob'] ?? Array())),
			'actnList'=>$this->promptActnList(),
			'minmRole'=>'',
			'runMount'=>'',
			'childSet'=>$this->promptChildren($baseName, $tableNme)
		);
		$specList['minmRole'] = $this->promptLeastRle();
		$specList['runMount'] = $this->promptMountPth($applName);
		try{
			$specPath = RunSpec::writeSpecsFile(RUN_DIR, $specList);
		}catch( \RuntimeException $e ){
			$this->stopWithReason($e->getMessage(), self::EXIT_FAIL);
		}
		Controller::clearFldrCache();
		$this->logShellChange( 'Makerun: '.$applName.'/'.RunSpec::SPEC_FILE.' written, from the table '.json_encode($tableNme) );
		$this->printerObj->writeShellPair('written', $this->getRunFilePath($specPath));
		return $this->writeRunFolder($specList);
	}

	/**
	 * Writes a Run again from its spec, without a question.
	 *
	 * @return Int The exit code.
	 */
	public function invokeFromSpec(): Int {
		$applName = $this->needsShellWord('the name of the Run');
		try{
			$specList = RunSpec::readSpecsArray(RUN_DIR, $applName);
		}catch( \RuntimeException $e ){
			$this->stopWithReason($e->getMessage(), self::EXIT_DENY);
		}
		return $this->writeRunFolder($specList);
	}

	/**
	 * Lists the Runs that have a spec.
	 *
	 * @return Int The exit code.
	 */
	public function invokeFullList(): Int {
		$this->printerObj->writeShellRows( Array('run', 'database', 'table', 'actions', 'children'), RunSpec::listSpecsArray(RUN_DIR) );
		return self::EXIT_DONE;
	}

	/**
	 * Step 1: the name of the Run, letters and digits with a letter first,
	 * written with a capital as the folders of Runs are; a Run of that name,
	 * whatever its case, is refused.
	 *
	 * @return String The name.
	 */
	protected function promptRunFoldr(): String {
		$applName = ucfirst( $this->needsShellWord('the name of the Run') );
		if( !preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $applName) ){
			$this->stopWithReason('Invalid name: letters and digits, a letter first', self::EXIT_HELP);
		}
		Controller::clearFldrCache();
		if( Controller::findRunFldrNme($applName)!=='' ){
			$this->stopWithReason('No Run generated: a Run named '.$applName.' exists (makerun fromSpec writes a generated one again)', self::EXIT_DENY);
		}
		return $applName;
	}

	/**
	 * Step 2: the database, by its label; it must be an SQL one.
	 *
	 * @return String The label, '' for the default database.
	 */
	protected function promptDatabase(): String {
		$baseName = $this->promptValueTxt('Database (its label, empty for the default one)', '');
		try{
			$dtbsHndr = DBCon::linkNamedDbCon($baseName)->readConnection();
		}catch( \RuntimeException $e ){
			$this->stopWithReason('No Run generated: '.$e->getMessage(), self::EXIT_DENY);
		}
		if( $dtbsHndr===null ){
			$this->stopWithReason('No Run generated: makerun needs an SQL database (mysql_db, postgres or sqlitedb), not MongoDB', self::EXIT_DENY);
		}
		return $baseName;
	}

	/**
	 * Step 3: the table, which must exist.
	 *
	 * @param String $linkNameText Label of the database.
	 * @param String $tableDefault The table proposed: the name of the Run in lower case.
	 * @return String The table.
	 */
	protected function promptTableNme( String $linkNameText, String $tableDefault ): String {
		$tableLst = DBCon::linkNamedDbCon($linkNameText)->readConnection()->tableNameArray();
		if( !is_array($tableLst) ){
			$this->stopWithReason('No Run generated: the tables of the database cannot be read', self::EXIT_FAIL);
		}
		$tableNme = $this->promptValueTxt('Table ('.implode(', ', $tableLst).')', in_array($tableDefault, $tableLst, true) ? $tableDefault : '');
		if( !in_array($tableNme, $tableLst, true) ){
			$this->stopWithReason('No Run generated: the database has no table '.json_encode($tableNme), self::EXIT_DENY);
		}
		return $tableNme;
	}

	/**
	 * Step 4: the key of the table, id_<singular> proposed when present. It
	 * must be the primary key, which reads and writes a record.
	 *
	 * @param String $tbleLabelTxt The table.
	 * @param Array  $tableDetails Its description (SqlStore::tableInfoArray()).
	 * @return String The key.
	 */
	protected function promptIdColumn( String $tbleLabelTxt, Array $tableDetails ): String {
		$primClef = strval($tableDetails['pkey'] ?? '');
		if( $primClef==='' ){
			$this->stopWithReason('No Run generated: the table '.$tbleLabelTxt.' has no primary key', self::EXIT_DENY);
		}
		$prgrName = 'id_'.StrKit::singularizeTxt($tbleLabelTxt);
		$idColumn = $this->promptValueTxt('Key of '.$tbleLabelTxt, in_array($prgrName, (array)($tableDetails['flds'] ?? Array()), true) ? $prgrName : $primClef);
		if( $idColumn!==$primClef ){
			$this->stopWithReason('No Run generated: the key must be the primary key of the table, '.$primClef, self::EXIT_DENY);
		}
		return $idColumn;
	}

	/**
	 * Step 5: the fields, each with the format its type proposes; each can
	 * be given another one, required by create (! after the format) or left
	 * out (-). The binary columns are left out.
	 *
	 * @param String $linkNameText  Label of the database.
	 * @param String $tbleLabelTxt  The table.
	 * @param String $identColText  Its key, never written.
	 * @param Array  $binaryFields  Its binary columns.
	 * @return Array The formats, by field.
	 */
	protected function promptFldsDict( String $linkNameText, String $tbleLabelTxt, String $identColText, Array $binaryFields ): Array {
		$typeList = DBCon::linkNamedDbCon($linkNameText)->readConnection()->columnTypeList($tbleLabelTxt);
		$fldsDict = Array();
		foreach( (array)$typeList as $oneFld => $oneTyp ){
			if( $oneFld===$identColText || in_array($oneFld, $binaryFields, true) ){
				continue;
			}
			$frmtText = $this->promptValueTxt('Field '.$oneFld.' ('.$oneTyp.'): its format, ! after it to require it, - to leave it out', RunSpec::getFormatOfCol(strval($oneTyp)));
			if( $frmtText==='-' ){
				continue;
			}
			$needFlag = (substr($frmtText, -1)==='!');
			$fldsDict[$oneFld.($needFlag ? '!' : '')] = $needFlag ? substr($frmtText, 0, -1) : $frmtText;
		}
		if( empty($fldsDict) ){
			$this->stopWithReason('No Run generated: no field is kept', self::EXIT_DENY);
		}
		return $fldsDict;
	}

	/**
	 * Step 6: the actions, all five by default.
	 *
	 * @return Array The actions kept, in the order of RunSpec::ACTN_LIST.
	 */
	protected function promptActnList(): Array {
		$actStrng = $this->promptValueTxt('Actions ('.implode(', ', RunSpec::ACTN_LIST).')', implode(', ', RunSpec::ACTN_LIST));
		$actLstng = array_values( array_filter(array_map('trim', explode(',', strtolower($actStrng)))) );
		if( empty($actLstng) || array_diff($actLstng, RunSpec::ACTN_LIST) ){
			$this->stopWithReason('No Run generated: the actions are some of '.implode(', ', RunSpec::ACTN_LIST), self::EXIT_DENY);
		}
		return array_values( array_intersect(RunSpec::ACTN_LIST, $actLstng) );
	}

	/**
	 * Step 7: the children, every table holding <singular>_id offered; for
	 * each one followed, its fields, its order, its own route, and whether
	 * the list holds it too.
	 *
	 * @param String $linkNameText Label of the database.
	 * @param String $tbleLabelTxt The table of the Run.
	 * @return Array The children, by table.
	 */
	protected function promptChildren( String $linkNameText, String $tbleLabelTxt ): Array {
		$dtbsHndr = DBCon::linkNamedDbCon($linkNameText)->readConnection();
		$linkFild = StrKit::singularizeTxt($tbleLabelTxt).'_id';
		$childDct = Array();
		foreach( (array)$dtbsHndr->tableNameArray() as $oneTbl ){
			$typeList = ($oneTbl===$tbleLabelTxt) ? false : $dtbsHndr->columnTypeList($oneTbl);
			if( !is_array($typeList) || !isset($typeList[$linkFild]) || !$this->promptQuestion('Child table '.$oneTbl.' (linked by '.$linkFild.'): follow it', true) ){
				continue;
			}
			$fldsDefn = implode(', ', array_diff(array_keys($typeList), Array($linkFild)));
			$fieldLst = array_values( array_filter(array_map('trim', explode(',', $this->promptValueTxt('  its fields', $fldsDefn)))) );
			$childDct[$oneTbl] = Array(
				'linkedBy'=>$linkFild,
				'fieldSet'=>array_fill_keys($fieldLst, ''),
				'sortedBy'=>$this->promptValueTxt('  its order (a field, ^ before it for the reverse order, empty for none)', ''),
				'ownRoute'=>$this->promptQuestion('  its own route, GET <id>/'.$oneTbl, true),
				'listsToo'=>$this->promptQuestion('  nested in index too', false)
			);
		}
		return $childDct;
	}

	/**
	 * Step 8: the least role of every action.
	 *
	 * @return String The role.
	 */
	protected function promptLeastRle(): String {
		$roleName = $this->promptValueTxt('Least role ('.implode(', ', Checker::ROLE_LIST).')', Checker::ROLE_LIST[0]);
		if( !in_array($roleName, Checker::ROLE_LIST, true) ){
			$this->stopWithReason('No Run generated: the role is one of '.implode(', ', Checker::ROLE_LIST), self::EXIT_DENY);
		}
		return $roleName;
	}

	/**
	 * Step 8: the mount, where the preset serves the routes of the Run.
	 *
	 * @param String $appsNameText The name of the Run.
	 * @return String The mount, the name in lower case by default.
	 */
	protected function promptMountPth( String $appsNameText ): String {
		return trim( $this->promptValueTxt('Mount (the path its routes are served under)', strtolower($appsNameText)), '/' );
	}

	/**
	 * Writes the files of a Run from its spec: a file that exists is
	 * replaced only when agreed (--yesToAll beforehand), each file written is
	 * traced on 'shell', and the lines of the preset are printed.
	 *
	 * @param Array $runSpecArray The spec.
	 * @return Int The exit code.
	 */
	protected function writeRunFolder( Array $runSpecArray ): Int {
		try{
			$fileList = (new RunMaker($runSpecArray, RUN_DIR))->makeFilesOfRun();
		}catch( \RuntimeException $e ){
			$this->stopWithReason($e->getMessage(), self::EXIT_FAIL);
		}
		$confFile = new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' );
		$keptList = Array();
		foreach( $fileList as $filPth => $oneTxt ){
			$rltnPath = $this->getRunFilePath($filPth);
			if( is_file($filPth) && !$this->promptApproval('Replace '.$rltnPath.' (any change made by hand is lost)') ){
				$keptList[] = $rltnPath;
				continue;
			}
			if( !is_dir(dirname($filPth)) ){
				@mkdir(dirname($filPth), 0775, true);
			}
			try{
				$confFile->saveFileAtomic($filPth, $oneTxt);
			}catch( \RuntimeException $e ){
				$this->stopWithReason($e->getMessage(), self::EXIT_FAIL);
			}
			$this->logShellChange( 'Makerun: '.$rltnPath.' written' );
			$this->printerObj->writeShellPair('written', $rltnPath);
		}
		Controller::clearFldrCache();
		foreach( $keptList as $onePth ){
			$this->printerObj->writeShellPair('kept as it was', $onePth);
		}
		$this->printerObj->writeShellPair('preset security', (new RunMaker($runSpecArray, RUN_DIR))->getPsetRoleLne());
		if( $runSpecArray['runMount']!==strtolower($runSpecArray['appsName']) ){
			$this->printerObj->writeShellPair('preset dispatch', '"runMount": { "'.strtolower($runSpecArray['appsName']).'": "'.$runSpecArray['runMount'].'" }');
		}
		return self::EXIT_DONE;
	}

	/**
	 * Asks a yes or no question whose default is the answer with --noPrompt.
	 *
	 * @param String $questionText The question.
	 * @param Bool   $defaultValue The answer on Enter, and with --noPrompt.
	 * @return Bool The answer.
	 */
	protected function promptQuestion( String $questionText, Bool $defaultValue ): Bool {
		$givenTxt = strtolower( $this->promptValueTxt($questionText.' (y/n)', $defaultValue ? 'y' : 'n') );
		return in_array($givenTxt, Array('y', 'yes'), true);
	}

	/**
	 * Names a file of a Run from the folder of Runs, with slashes.
	 *
	 * @param String $filePathName Full path of the file.
	 * @return String Its path from the folder of Runs, runs/ before it.
	 */
	protected function getRunFilePath( String $filePathName ): String {
		return 'runs/'.str_replace(DS, '/', ltrim(substr($filePathName, strlen(RUN_DIR)), '/\\'));
	}

}
