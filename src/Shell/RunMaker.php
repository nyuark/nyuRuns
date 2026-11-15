<?php

namespace nyu\Shell;

/**
 * The generator of makerun: the files of a CRUDL Run, written from its spec
 * alone (see RunSpec), in runs/<App>:
 *
 *   routes.rte           "" : GET index, POST create; "$id" : GET view, PUT update,
 *                        DELETE remove; "$id/<child>" : GET, for a child with its own route
 *   Actions.php          the actions kept, the least role asked before each one
 *   input/<action>.json  the inputs of each action: none for those taking the key alone
 *
 * The Run declares its routes, so it has no default action: its "" route
 * says what its path alone answers.
 */
class RunMaker{

	protected $runSpecArr;
	protected $runsFolder;

	/**
	 * Builds the generator of a Run.
	 *
	 * @param Array  $runSpecArray The spec, as RunSpec reads it.
	 * @param String $runsFldrPath Folder holding the Runs.
	 *
	 * @throws \RuntimeException When the spec is not valid.
	 */
	function __construct( Array $runSpecArray, String $runsFldrPath ){
		$flawText = RunSpec::checkSpecFlaws($runSpecArray);
		if( $flawText!=='' ){
			throw new \RuntimeException('Nothing generated: '.$flawText);
		}
		$this->runSpecArr = $runSpecArray;
		$this->runsFolder = rtrim($runsFldrPath, '/\\');
	}

	/**
	 * Gives every file of the Run, by its full path, its lines ended with
	 * \n whatever the line endings of the checkout.
	 *
	 * @return Array The content of each file, by its path.
	 */
	public function buildFilesList(): Array {
		$runFoldr = $this->runsFolder.DS.$this->runSpecArr['appsName'];
		$fileList = Array( $runFoldr.DS.'routes.rte'=>$this->routesFileText(), $runFoldr.DS.'Actions.php'=>$this->actionsPhpText() );
		foreach( $this->inputFileArray() as $oneAct => $oneTxt ){
			$fileList[$runFoldr.DS.'input'.DS.$oneAct.'.json'] = $oneTxt;
		}
		// Every file is written with \n, on every system
		foreach( $fileList as $onePth => $oneTxt ){
			$fileList[$onePth] = str_replace("\r\n", "\n", $oneTxt);
		}
		return $fileList;
	}

	/**
	 * Writes the routes of the Run: one block per path, one target per
	 * method, for the actions kept only.
	 *
	 * @return String The content of routes.rte.
	 */
	public function routesFileText(): String {
		$actLstng = $this->runSpecArr['actnList'];
		$routeLst = Array();
		foreach( Array(''=>Array('GET'=>'index', 'POST'=>'create'), '$id'=>Array('GET'=>'view', 'PUT'=>'update', 'DELETE'=>'remove')) as $onePth => $oneSet ){
			$trgtVals = Array();
			foreach( $oneSet as $oneMth => $oneAct ){
				if( in_array($oneAct, $actLstng, true) ){
					$trgtVals[$oneMth] = Array('doAction'=>$oneAct);
				}
			}
			if( !empty($trgtVals) ){
				$routeLst[$onePth] = Array('pointsTo'=>$trgtVals);
			}
		}
		foreach( $this->runSpecArr['childSet'] as $oneTbl => $oneSet ){
			if( $oneSet['ownRoute'] ){
				$routeLst['$id/'.$oneTbl] = Array('mthdList'=>'GET', 'pointsTo'=>Array('doAction'=>strval($oneTbl)));
			}
		}
		return self::tabbedJsonText( empty($routeLst) ? new \stdClass() : $routeLst );
	}

	/**
	 * Writes the inputs of each action: the listing ones for index, the
	 * fields for create (those the spec requires marked so) and for
	 * update (none required), and none for the actions taking the key alone.
	 *
	 * @return Array The content of each input/<action>.json, by action.
	 */
	public function inputFileArray(): Array {
		$fieldAll = Array();
		$fldsOpti = Array();
		foreach( $this->runSpecArr['fieldSet'] as $oneFld => $oneFmt ){
			$fieldAll[$oneFld] = $oneFmt;
			$fldsOpti[rtrim($oneFld, '!')] = $oneFmt;
		}
		$fileList = Array();
		foreach( $this->runSpecArr['actnList'] as $oneAct ){
			$fldsDict = match( $oneAct ){
				'index'=>Array('filters'=>array_fill_keys(array_keys($fldsOpti), ''), 'sortedBy'=>'', 'limitVal'=>'Integer', 'rowIndex'=>'Integer'),
				'create'=>$fieldAll,
				'update'=>$fldsOpti,
				default=>new \stdClass()
			};
			$fileList[$oneAct] = self::tabbedJsonText( Array('fieldSet'=>$fldsDict) );
		}
		foreach( $this->runSpecArr['childSet'] as $oneTbl => $oneSet ){
			if( $oneSet['ownRoute'] ){
				$fileList[strval($oneTbl)] = self::tabbedJsonText( Array('fieldSet'=>new \stdClass()) );
			}
		}
		return $fileList;
	}

	/**
	 * Gives the line of the preset asking for the least role of the Run:
	 * printed for the preset's security block, never written into it.
	 *
	 * @return String The minRoles entry.
	 */
	public function minRolesString(): String {
		return '"minRoles": { "'.strtolower($this->runSpecArr['appsName']).'/*": "'.$this->runSpecArr['minmRole'].'" }';
	}

	/**
	 * Writes the actions of the Run.
	 *
	 * @return String The content of Actions.php.
	 */
	public function actionsPhpText(): String {
		$specList = $this->runSpecArr;
		$applName = $specList['appsName'];
		$actLstng = $specList['actnList'];
		$fldsOpti = Array();
		foreach( $specList['fieldSet'] as $oneFld => $oneFmt ){
			$fldsOpti[rtrim($oneFld, '!')] = preg_replace('/\(.*$/s', '', $oneFmt);
		}
		$childLst = Array();
		foreach( $specList['childSet'] as $oneTbl => $oneSet ){
			$childLst[$oneTbl] = Array('linkedBy'=>$oneSet['linkedBy'], 'fieldSet'=>array_keys($oneSet['fieldSet']), 'sortedBy'=>$oneSet['sortedBy'], 'listsToo'=>$oneSet['listsToo']);
		}
		$trailTop = '/'.$specList['runMount'];
		$routeLne = Array();
		foreach( Array('index'=>'GET    '.$trailTop, 'create'=>'POST   '.$trailTop, 'view'=>'GET    '.$trailTop.'/<id>', 'update'=>'PUT    '.$trailTop.'/<id>', 'remove'=>'DELETE '.$trailTop.'/<id>') as $oneAct => $oneTxt ){
			if( in_array($oneAct, $actLstng, true) ){
				$routeLne[] = ' *   '.str_pad($oneTxt, 34).$oneAct;
			}
		}
		foreach( $specList['childSet'] as $oneTbl => $oneSet ){
			if( $oneSet['ownRoute'] ){
				$routeLne[] = ' *   '.str_pad('GET    '.$trailTop.'/<id>/'.$oneTbl, 34).$oneTbl;
			}
		}
		$childTxt = empty($childLst) ? '' : ', and its '.implode(' and ', array_keys($childLst));

		$codeText = "<?php\n\nnamespace nyu\\Runs\\".$applName.";\n\n"
			."use nyu\\App\\Actionset;\nuse nyu\\Data\\DBCon;\nuse nyu\\Data\\DBLst;\nuse nyu\\Data\\DBRec;\nuse nyu\\Data\\DBStore\\Filter;\n\n"
			."/**\n * ".$applName.": the records of the table ".$specList['tableRef'].$childTxt.".\n"
			." * Generated by makerun from runspec.json; \"php nyuRun makerun fromSpec ".$applName."\"\n"
			." * writes it again, over any change made by hand.\n *\n"
			.implode("\n", $routeLne)."\n */\n"
			."class Actions extends Actionset {\n\n"
			."\t// The database (its label, '' for the default one), the table, its key, and the fields read and written with their format\n"
			."\tconst BASE_LINK = ".self::phpLiteralText($specList['database']).";\n"
			."\tconst TBLE_NAME = ".self::phpLiteralText($specList['tableRef']).";\n"
			."\tconst IDNT_COLN = ".self::phpLiteralText($specList['idColumn']).";\n"
			."\tconst FLDS_LIST = ".self::phpLiteralText($fldsOpti).";\n"
			."\t// The children: the field naming their record, the fields read, their order, and whether the list holds them\n"
			."\tconst CHLD_LIST = ".self::phpLiteralText($childLst).";\n\n"
			."\t/**\n\t * Asks for the least role of the Run before every action.\n\t */\n"
			."\tprotected function setupForAction(): Void {\n\t\t\$this->needsActionRle(".self::phpLiteralText($specList['minmRole']).");\n\t}\n";

		if( in_array('index', $actLstng, true) ){
			$codeText .= <<<'PHPCODE'

	/**
	 * Lists the records: filtered on the fields sent under filters, each
	 * matched anywhere in the field as it is written (a star is a star),
	 * sorted by sortedBy (a field, ^ before it for the reverse order),
	 * paged by limitVal and rowIndex. The children the list holds are read
	 * at once for the page, one query for each child table.
	 */
	public function index(): Void {
		$listObj = new DBLst( self::TBLE_NAME, null, null, null, null, DBCon::linkNamedDbCon(self::BASE_LINK) );
		$listObj->pickReadFields( implode(', ', array_merge(Array(self::IDNT_COLN), array_keys(self::FLDS_LIST))) );
		$fltrSet = Array();
		foreach( (array)$this->inputsRgst->readValueByKey('filters') as $oneFld => $oneVal ){
			if( isset(self::FLDS_LIST[$oneFld]) && is_scalar($oneVal) && strval($oneVal)!=='' ){
				$fltrSet[$oneFld.'~'] = '*'.Filter::quoteMatchText(strval($oneVal)).'*';
			}
		}
		$listObj->replaceFilters($fltrSet);
		$sortTxt = strval( $this->inputsRgst->readValueByKey('sortedBy') ?? '' );
		$listObj->setOrderFields( (ltrim($sortTxt, '^')===self::IDNT_COLN || isset(self::FLDS_LIST[ltrim($sortTxt, '^')])) ? $sortTxt : self::IDNT_COLN );
		$listObj->setLimitOffset( Array($this->inputsRgst->readValueByKey('limitVal') ?? '', $this->inputsRgst->readValueByKey('rowIndex') ?? '') );
		$rowList = $listObj->readRowsOfList();
		if( !is_array($rowList) ){
			$this->stopThisAction(500, 'The list cannot be read');
		}
		$keyList = array_column($rowList, self::IDNT_COLN);
		foreach( self::CHLD_LIST as $oneTbl => $oneSet ){
			if( $oneSet['listsToo'] ){
				$chldRow = $this->childRowsArray($oneTbl, $keyList);
				foreach( $rowList as $rowIdx => $oneRow ){
					$rowList[$rowIdx][$oneTbl] = $chldRow[$oneRow[self::IDNT_COLN]] ?? Array();
				}
			}
		}
		$this->checkActionLst($rowList);
	}

PHPCODE;
		}
		if( in_array('view', $actLstng, true) ){
			$codeText .= <<<'PHPCODE'

	/**
	 * Gives a record, with its children nested.
	 *
	 * @param String $recordIdText The key of the record, from the path.
	 */
	public function view( String $recordIdText='' ): Void {
		$recdVls = $this->readRecordById($recordIdText)[1];
		foreach( array_keys(self::CHLD_LIST) as $oneTbl ){
			$recdVls[$oneTbl] = $this->childRowsArray($oneTbl, Array($recdVls[self::IDNT_COLN]))[$recdVls[self::IDNT_COLN]] ?? Array();
		}
		$this->statusCode = 200;
		$this->replyValue = $recdVls;
	}

PHPCODE;
		}
		if( in_array('create', $actLstng, true) ){
			$codeText .= <<<'PHPCODE'

	/**
	 * Adds a record from the fields sent: a 201 with its key.
	 */
	public function create(): Void {
		$sentVls = $this->sentFieldsList();
		$recdObj = new DBRec( self::TBLE_NAME, Array(), Array(), DBCon::linkNamedDbCon(self::BASE_LINK) );
		$recdObj->bindFieldArray($sentVls);
		$savdKey = $recdObj->insertOrUpdate();
		if( $savdKey===false ){
			$this->stopThisAction(500, 'The record cannot be saved');
		}
		$this->statusCode = 201;
		$this->replyValue = Array(self::IDNT_COLN=>$savdKey);
	}

PHPCODE;
		}
		if( in_array('update', $actLstng, true) ){
			$codeText .= <<<'PHPCODE'

	/**
	 * Changes the fields sent of a record: a 200 with the record as saved.
	 *
	 * @param String $recordIdText The key of the record, from the path.
	 */
	public function update( String $recordIdText='' ): Void {
		$recdObj = $this->readRecordById($recordIdText)[0];
		$recdObj->bindFieldArray( $this->sentFieldsList() );
		if( $recdObj->insertOrUpdate()===false ){
			$this->stopThisAction(500, 'The record cannot be saved');
		}
		$this->statusCode = 200;
		$this->replyValue = $this->readRecordById($recordIdText)[1];
	}

PHPCODE;
		}
		if( in_array('remove', $actLstng, true) ){
			$codeText .= <<<'PHPCODE'

	/**
	 * Removes a record: a 204. Its children are left as they are.
	 *
	 * @param String $recordIdText The key of the record, from the path.
	 */
	public function remove( String $recordIdText='' ): Void {
		if( !$this->readRecordById($recordIdText)[0]->removeRecdFlag() ){
			$this->stopThisAction(500, 'The record cannot be removed');
		}
		$this->statusCode = 204;
	}

PHPCODE;
		}
		foreach( $specList['childSet'] as $oneTbl => $oneSet ){
			if( $oneSet['ownRoute'] ){
				$codeText .= "\n\t/**\n\t * Lists the ".$oneTbl." of a record.\n\t *\n\t * @param String \$recordIdText The key of the record, from the path.\n\t */\n"
					."\tpublic function ".$oneTbl."( String \$recordIdText='' ): Void {\n"
					."\t\t\$recdKey = \$this->readRecordById(\$recordIdText)[1][self::IDNT_COLN];\n"
					."\t\t\$this->checkActionLst( \$this->childRowsArray(".self::phpLiteralText(strval($oneTbl)).", Array(\$recdKey))[\$recdKey] ?? Array() );\n\t}\n\n";
			}
		}
		$codeText .= <<<'PHPCODE'

	/**
	 * Reads a record by its key: the request ends with a 404 when there is none.
	 *
	 * @param String $recordIdText The key of the record, from the path.
	 * @return Array The record, loaded, then its key and fields.
	 */
	protected function readRecordById( String $recordIdText ): Array {
		$recdObj = new DBRec( self::TBLE_NAME, Array(), Array(), DBCon::linkNamedDbCon(self::BASE_LINK) );
		$recdVls = ($recordIdText==='') ? false : $recdObj->loadRecordList($recordIdText);
		if( !is_array($recdVls) ){
			$this->stopThisAction(404, 'Not Found');
		}
		return Array( $recdObj, array_intersect_key($recdVls, array_flip(array_merge(Array(self::IDNT_COLN), array_keys(self::FLDS_LIST)))) );
	}

	/**
	 * Gives the fields the request sent. A field given a format whose value
	 * is empty was not valid, and a request sending no field is empty: both
	 * are refused with a 400.
	 *
	 * @return Array The values, by field.
	 */
	protected function sentFieldsList(): Array {
		$sentVls = Array();
		$badFlds = Array();
		foreach( self::FLDS_LIST as $oneFld => $oneFmt ){
			$sentVal = $this->inputsRgst->readValueByKey($oneFld);
			if( $sentVal===null ){
				continue;
			}
			if( is_array($sentVal) || ($oneFmt!=='' && strval($sentVal)==='') ){
				$badFlds[] = $oneFld;
			}
			$sentVls[$oneFld] = $sentVal;
		}
		if( !empty($badFlds) || empty($sentVls) ){
			$this->stopThisAction(400, 'Missing or invalid input: '.(empty($badFlds) ? 'no field sent' : implode(', ', $badFlds)));
		}
		return $sentVls;
	}

	/**
	 * Reads the children of some records at once, in one query.
	 *
	 * @param String $childTbleTxt The child table, as CHLD_LIST names it.
	 * @param Array  $parentKeyLst The keys of the records.
	 * @return Array The children, by the key of their record.
	 */
	protected function childRowsArray( String $childTbleTxt, Array $parentKeyLst ): Array {
		$chldSet = self::CHLD_LIST[$childTbleTxt];
		if( empty($parentKeyLst) ){
			return Array();
		}
		$listObj = new DBLst( $childTbleTxt, null, null, null, null, DBCon::linkNamedDbCon(self::BASE_LINK) );
		$listObj->pickReadFields( implode(', ', array_unique(array_merge(Array($chldSet['linkedBy']), $chldSet['fieldSet']))) );
		$listObj->replaceFilters( Array($chldSet['linkedBy']=>array_values($parentKeyLst)) );
		if( $chldSet['sortedBy']!=='' ){
			$listObj->setOrderFields($chldSet['sortedBy']);
		}
		$rowList = $listObj->readRowsOfList();
		if( !is_array($rowList) ){
			$this->stopThisAction(500, 'The children cannot be read');
		}
		$chldRow = Array();
		foreach( $rowList as $oneRow ){
			$chldRow[$oneRow[$chldSet['linkedBy']]][] = $oneRow;
		}
		return $chldRow;
	}

}

PHPCODE;
		// The blocks written in this file end their lines as this file does: the Run is written with \n, whatever the checkout
		$codeText = str_replace("\r\n", "\n", $codeText);
		// The pieces meet with one blank line between two methods
		return preg_replace("/\n{3,}/", "\n\n", $codeText);
	}

	/**
	 * Writes a value as JSON, one tab for each level: the layout of the
	 * framework's own files.
	 *
	 * @param Mixed $valueToWrite The value: an empty object is written {}.
	 * @return String The JSON, ending with a line break.
	 */
	public static function tabbedJsonText( Mixed $valueToWrite ): String {
		$jsonText = strval( json_encode($valueToWrite, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) );
		return preg_replace_callback('/^(?: {4})+/m', function( Array $matchedArray ): String { return str_repeat("\t", intdiv(strlen($matchedArray[0]), 4)); }, $jsonText)."\n";
	}

	/**
	 * Writes a value as PHP code, arrays as Array(...) on one line.
	 *
	 * @param Mixed $valueToWrite A text, a number, a boolean, null or an array of them.
	 * @return String The PHP literal.
	 */
	protected static function phpLiteralText( Mixed $valueToWrite ): String {
		if( is_array($valueToWrite) ){
			$itemList = Array();
			foreach( $valueToWrite as $oneKey => $oneVal ){
				$itemList[] = (array_is_list($valueToWrite) ? '' : var_export($oneKey, true).'=>').self::phpLiteralText($oneVal);
			}
			return 'Array('.implode(', ', $itemList).')';
		}
		return is_null($valueToWrite) ? 'null' : var_export($valueToWrite, true);
	}

}
