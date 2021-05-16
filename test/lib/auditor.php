<?php

// Static audit of the project's coding conventions, built on
// PHP's tokenizer. It lists every name that does not follow them:
//
//   classes      PascalCase
//   methods      9-character camelCase (magic __methods are ignored)
//   parameters   10-character camelCase
//   properties   8-character camelCase
//   loop vars    6- or 3-character camelCase (foreach / for iteration variables)
//   locals       7-character camelCase (6 and 3 are tolerated)
//   docblocks    required on public methods
//   types        every parameter typed, every method with a return type
//
// The audit only reports; which findings are accepted exceptions is decided
// by test/audit/ConventionTest.php.

/**
 * Tells whether a name is camelCase.
 *
 * @param String $testedName Name to test, without its '$'.
 * @return Bool True for a camelCase name.
 */
function isCamelCs( String $testedName ): Bool {
	return preg_match('/^[a-z][a-zA-Z0-9]*$/', $testedName)===1;
}

/**
 * Lists the PHP files under the given folders.
 *
 * @param String $projctRoot Project root.
 * @param Array  $folderList Folders to scan, relative to the root.
 * @return Array Full paths of the PHP files found, sorted.
 */
function listFiles( String $projctRoot, Array $folderList ): Array {
	$fileLst = Array();
	foreach( $folderList as $fldNme ){
		$walker = new RecursiveIteratorIterator( new RecursiveDirectoryIterator($projctRoot.DIRECTORY_SEPARATOR.$fldNme, FilesystemIterator::SKIP_DOTS) );
		foreach( $walker as $fleObj ){
			if( strtolower($fleObj->getExtension())==='php' ){
				$fileLst[] = $fleObj->getPathname();
			}
		}
	}
	sort($fileLst);
	return $fileLst;
}

/**
 * Audits the PHP files under the given folders.
 *
 * @param String $projctRoot Project root.
 * @param Array  $folderList Folders to scan, relative to the root.
 * @return Array Findings per kind ('class', 'method', 'param', 'prop', 'loop', 'local', 'nodoc', 'notype', 'parse'),
 *               each a list of ['file', 'line', 'scope', 'name'], plus the number of names seen under 'count'.
 */
function auditCode( String $projctRoot, Array $folderList ): Array {
	$superGl = Array('$this', '$_SERVER', '$_GET', '$_POST', '$_FILES', '$_COOKIE', '$_SESSION', '$_REQUEST', '$_ENV', '$GLOBALS', '$argv', '$argc', '$http_response_header');
	$modifrs = Array(T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL);
	$finding = Array('class'=>Array(), 'method'=>Array(), 'param'=>Array(), 'prop'=>Array(), 'loop'=>Array(), 'local'=>Array(), 'nodoc'=>Array(), 'notype'=>Array(), 'parse'=>Array());
	$counter = Array('class'=>0, 'method'=>0, 'param'=>0, 'prop'=>0, 'loop'=>0, 'local'=>0);

	foreach( listFiles($projctRoot, $folderList) as $fleNme ){
		$relPath = str_replace('\\', '/', substr($fleNme, strlen($projctRoot)+1));
		try{
			$allToks = PhpToken::tokenize( file_get_contents($fleNme), TOKEN_PARSE );
		}catch( Throwable $e ){
			$finding['parse'][] = Array('file'=>$relPath, 'line'=>$e->getLine(), 'scope'=>'', 'name'=>$e->getMessage());
			continue;
		}
		$tokList = array_values( array_filter($allToks, function($tokenObjct){ return !$tokenObjct->is(Array(T_WHITESPACE, T_COMMENT)); }) );
		$tokSize = count($tokList);

		$depthNb = 0;			// current brace depth
		$clsDpth = Array();		// brace depths at which a class body opened
		$fncDpth = Array();		// brace depths at which a function body opened
		$clsWait = false;		// a class header was read, its body has not opened yet
		$curFunc = null;		// name of the outermost function being read
		$knownPr = Array();		// parameters of the functions being read
		$seenLcl = Array();		// locals already reported in the current function
		$seenLop = Array();		// loop variables already reported in the current function

		for( $idx=0; $idx<$tokSize; $idx++ ){
			$tok = $tokList[$idx];

			// Class, interface, trait or enum header
			if( $tok->is(Array(T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM)) && !($idx>0 && $tokList[$idx-1]->id===T_DOUBLE_COLON) ){
				if( isset($tokList[$idx+1]) && $tokList[$idx+1]->id===T_STRING ){
					$clsName = $tokList[$idx+1]->text;
					$counter['class']++;
					if( !preg_match('/^[A-Z][a-zA-Z0-9]*$/', $clsName) ){
						$finding['class'][] = Array('file'=>$relPath, 'line'=>$tok->line, 'scope'=>'', 'name'=>$clsName);
					}
				}
				$clsWait = true;
				continue;
			}

			// Function, method, closure or arrow function
			if( $tok->id===T_FUNCTION || $tok->id===T_FN ){
				$jdx = $idx+1;
				if( $tokList[$jdx]->text==='&' ){
					$jdx++;
				}
				$fncName = null;
				if( $tokList[$jdx]->text!=='(' ){
					$fncName = $tokList[$jdx]->text;
					$jdx++;
				}
				$modList = Array();
				$kdx = $idx-1;
				while( $kdx>=0 && $tokList[$kdx]->is($modifrs) ){
					$modList[] = strtolower($tokList[$kdx]->text);
					$kdx--;
				}
				$hasDocs = $kdx>=0 && $tokList[$kdx]->id===T_DOC_COMMENT;
				$inClass = !empty($clsDpth) && empty($fncDpth);
				$isPublc = $inClass && empty(array_intersect($modList, Array('private', 'protected')));
				$isMagic = $fncName!==null && str_starts_with($fncName, '__');
				$fncLabl = $fncName ?? '{closure}';

				if( $fncName!==null && !$isMagic ){
					$counter['method']++;
					if( strlen($fncName)!==9 || !isCamelCs($fncName) ){
						$finding['method'][] = Array('file'=>$relPath, 'line'=>$tok->line, 'scope'=>'', 'name'=>$fncName);
					}
				}
				if( $fncName!==null && $isPublc && !$hasDocs ){
					$finding['nodoc'][] = Array('file'=>$relPath, 'line'=>$tok->line, 'scope'=>'', 'name'=>$fncName);
				}

				// Parameter list
				$parDpth = 0;
				$parList = Array();
				for( ; $jdx<$tokSize; $jdx++ ){
					$parTok = $tokList[$jdx];
					if( $parTok->text==='(' ){
						$parDpth++;
					}elseif( $parTok->text===')' ){
						$parDpth--;
						if( $parDpth===0 ){
							break;
						}
					}elseif( $parTok->id===T_VARIABLE && $parDpth===1 ){
						$parName = substr($parTok->text, 1);
						$parList[] = $parTok->text;
						$counter['param']++;
						$qdx = $jdx-1;
						while( in_array($tokList[$qdx]->text, Array('&', '...'), true) ){
							$qdx--;
						}
						$isTyped = !in_array($tokList[$qdx]->text, Array('(', ','), true) && !$tokList[$qdx]->is(Array(T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY));
						if( strlen($parName)!==10 || !isCamelCs($parName) ){
							$finding['param'][] = Array('file'=>$relPath, 'line'=>$parTok->line, 'scope'=>$fncLabl, 'name'=>$parName);
						}
						if( !$isTyped && $fncName!==null ){
							$finding['notype'][] = Array('file'=>$relPath, 'line'=>$parTok->line, 'scope'=>$fncLabl, 'name'=>'$'.$parName);
						}
					}
				}

				// Closure "use" list, then the return type
				$jdx++;
				if( isset($tokList[$jdx]) && $tokList[$jdx]->id===T_USE ){
					while( $tokList[$jdx]->text!==')' ){
						if( $tokList[$jdx]->id===T_VARIABLE ){
							$parList[] = $tokList[$jdx]->text;
						}
						$jdx++;
					}
					$jdx++;
				}
				$hasRetn = isset($tokList[$jdx]) && $tokList[$jdx]->text===':';
				if( !$hasRetn && $fncName!==null && !$isMagic ){
					$finding['notype'][] = Array('file'=>$relPath, 'line'=>$tok->line, 'scope'=>$fncLabl, 'name'=>'(return type)');
				}

				if( $tok->id===T_FUNCTION ){
					while( $jdx<$tokSize && !in_array($tokList[$jdx]->text, Array('{', ';'), true) ){
						$jdx++;
					}
					if( $jdx<$tokSize && $tokList[$jdx]->text==='{' ){
						$depthNb++;
						$fncDpth[] = $depthNb;
						if( count($fncDpth)===1 ){
							$curFunc = $fncLabl;
							$knownPr = $parList;
							$seenLcl = Array();
							$seenLop = Array();
						}else{
							$knownPr = array_merge($knownPr, $parList);
						}
					}
				}else{
					$knownPr = array_merge($knownPr, $parList);
				}
				$idx = $jdx;
				continue;
			}

			// Braces
			if( $tok->text==='{' || $tok->is(Array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES)) ){
				$depthNb++;
				if( $clsWait ){
					$clsDpth[] = $depthNb;
					$clsWait = false;
				}
				continue;
			}
			if( $tok->text==='}' ){
				if( !empty($fncDpth) && end($fncDpth)===$depthNb ){
					array_pop($fncDpth);
				}
				if( !empty($clsDpth) && end($clsDpth)===$depthNb ){
					array_pop($clsDpth);
				}
				$depthNb--;
				continue;
			}

			// Variables
			if( $tok->id!==T_VARIABLE ){
				continue;
			}
			if( $idx>0 && $tokList[$idx-1]->id===T_DOUBLE_COLON ){
				continue;	// static property access
			}
			if( in_array($tok->text, $superGl, true) ){
				continue;
			}
			$varName = substr($tok->text, 1);

			// Property declaration : directly inside a class body
			if( !empty($clsDpth) && empty($fncDpth) ){
				if( end($clsDpth)===$depthNb ){
					$counter['prop']++;
					if( strlen($varName)!==8 || !isCamelCs($varName) ){
						$finding['prop'][] = Array('file'=>$relPath, 'line'=>$tok->line, 'scope'=>'', 'name'=>$varName);
					}
				}
				continue;
			}
			if( in_array($tok->text, $knownPr, true) ){
				continue;
			}
			$scopeNm = !empty($fncDpth) ? $curFunc : '{file scope}';
			$scopeKy = $scopeNm.'|'.$tok->text;

			// Iteration variable : foreach (... as [$k =>] $v) or for ($i = ...
			$prvTok1 = $tokList[$idx-1];
			$prvTok2 = $tokList[$idx-2] ?? null;
			$prvTok3 = $tokList[$idx-3] ?? null;
			$prvTok4 = $tokList[$idx-4] ?? null;
			$isLoopV = false;
			if( $prvTok1->id===T_AS || ($prvTok1->text==='&' && $prvTok2 && $prvTok2->id===T_AS) ){
				$isLoopV = true;
			}
			if( $prvTok1->id===T_DOUBLE_ARROW && $prvTok2 && $prvTok2->id===T_VARIABLE && $prvTok3 && $prvTok3->id===T_AS ){
				$isLoopV = true;
			}
			if( $prvTok1->text==='&' && $prvTok2 && $prvTok2->id===T_DOUBLE_ARROW && $prvTok4 && $prvTok4->id===T_AS ){
				$isLoopV = true;
			}
			if( $prvTok1->text==='(' && $prvTok2 && $prvTok2->id===T_FOR ){
				$isLoopV = true;
			}

			if( $isLoopV && !isset($seenLop[$scopeKy]) ){
				$seenLop[$scopeKy] = true;
				$counter['loop']++;
				if( !in_array(strlen($varName), Array(3, 6), true) || !isCamelCs($varName) ){
					$finding['loop'][] = Array('file'=>$relPath, 'line'=>$tok->line, 'scope'=>$scopeNm, 'name'=>$varName);
				}
			}
			if( !$isLoopV && !isset($seenLcl[$scopeKy]) && !isset($seenLop[$scopeKy]) ){
				$seenLcl[$scopeKy] = true;
				$counter['local']++;
				if( !in_array(strlen($varName), Array(7, 6, 3), true) || !isCamelCs($varName) ){
					$finding['local'][] = Array('file'=>$relPath, 'line'=>$tok->line, 'scope'=>$scopeNm, 'name'=>$varName);
				}
			}
		}
	}

	$finding['count'] = $counter;
	return $finding;
}
