<?php

// Static audit of the project's coding conventions (see CONTRIBUTING.md), built on
// PHP's tokenizer. It lists every name that does not follow them:
//
//   classes      PascalCase
//   methods      9-character camelCase (magic __methods are ignored)
//   parameters   10-character camelCase
//   properties   8-character camelCase
//   loop vars    6-character camelCase (foreach / for iteration variables); those of 3 that
//                test/audit/looplabels.txt lists are accepted by ConventionTest
//   locals       7-character camelCase
//   docblocks    required on public methods
//   types        every parameter typed, every method with a return type
//
// The audit only reports; which findings are accepted exceptions is decided
// by test/audit/ConventionTest.php.

/**
 * Tells whether a name is camelCase.
 *
 * @param String $nameToVerify Name to test, without its '$'.
 * @return Bool True for a camelCase name.
 */
function checkCamelCase( String $nameToVerify ): Bool {
	return preg_match('/^[a-z][a-zA-Z0-9]*$/', $nameToVerify)===1;
}

/**
 * Lists the PHP files under the given folders.
 *
 * @param String $rootPathName Project root.
 * @param Array  $scanFoldList Folders to scan, relative to the root.
 * @return Array Full paths of the PHP files found, sorted.
 */
function getUploadFiles( String $rootPathName, Array $scanFoldList ): Array {
	$fileLst = Array();
	foreach( $scanFoldList as $fldNme ){
		$dirIter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator($rootPathName.DIRECTORY_SEPARATOR.$fldNme, FilesystemIterator::SKIP_DOTS) );
		foreach( $dirIter as $filObj ){
			if( strtolower($filObj->getExtension())==='php' ){
				$fileLst[] = $filObj->getPathname();
			}
		}
	}
	sort($fileLst);
	return $fileLst;
}

/**
 * Audits the PHP files under the given folders.
 *
 * @param String $rootPathName Project root.
 * @param Array  $scanFoldList Folders to scan, relative to the root.
 * @return Array Findings per kind ('class', 'method', 'param', 'prop', 'loop', 'local', 'nodoc', 'notype', 'parse'),
 *               each a list of ['file', 'line', 'scope', 'name'], plus the number of names seen under 'count'.
 */
function auditCodeFiles( String $rootPathName, Array $scanFoldList ): Array {
	$sprGlbl = Array('$this', '$_SERVER', '$_GET', '$_POST', '$_FILES', '$_COOKIE', '$_SESSION', '$_REQUEST', '$_ENV', '$GLOBALS', '$argv', '$argc', '$http_response_header');
	$modifrs = Array(T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL);
	$finding = Array('class'=>Array(), 'method'=>Array(), 'param'=>Array(), 'prop'=>Array(), 'loop'=>Array(), 'local'=>Array(), 'nodoc'=>Array(), 'notype'=>Array(), 'parse'=>Array());
	$counter = Array('class'=>0, 'method'=>0, 'param'=>0, 'prop'=>0, 'loop'=>0, 'local'=>0);

	foreach( getUploadFiles($rootPathName, $scanFoldList) as $filNme ){
		$relPath = str_replace('\\', '/', substr($filNme, strlen($rootPathName)+1));
		try{
			$allTkns = PhpToken::tokenize( file_get_contents($filNme), TOKEN_PARSE );
		}catch( Throwable $e ){
			$finding['parse'][] = Array('file'=>$relPath, 'line'=>$e->getLine(), 'scope'=>'', 'name'=>$e->getMessage());
			continue;
		}
		$tknList = array_values( array_filter($allTkns, function($phpTokenItem){ return !$phpTokenItem->is(Array(T_WHITESPACE, T_COMMENT)); }) );
		$tknSize = count($tknList);

		$depthNb = 0;			// current brace depth
		$clsDpth = Array();		// brace depths at which a class body opened
		$fnDepth = Array();		// brace depths at which a function body opened
		$clsWait = false;		// a class header was read, its body has not opened yet
		$outerFn = null;		// name of the outermost function being read
		$argsSet = Array();		// parameters of the functions being read
		$seenLcl = Array();		// locals already reported in the current function
		$senLoop = Array();		// loop variables already reported in the current function

		for( $idxInc=0; $idxInc<$tknSize; $idxInc++ ){
			$thisTkn = $tknList[$idxInc];

			// Class, interface, trait or enum header
			if( $thisTkn->is(Array(T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM)) && !($idxInc>0 && $tknList[$idxInc-1]->id===T_DOUBLE_COLON) ){
				if( isset($tknList[$idxInc+1]) && $tknList[$idxInc+1]->id===T_STRING ){
					$clsName = $tknList[$idxInc+1]->text;
					$counter['class']++;
					if( !preg_match('/^[A-Z][a-zA-Z0-9]*$/', $clsName) ){
						$finding['class'][] = Array('file'=>$relPath, 'line'=>$thisTkn->line, 'scope'=>'', 'name'=>$clsName);
					}
				}
				$clsWait = true;
				continue;
			}

			// Function, method, closure or arrow function
			if( $thisTkn->id===T_FUNCTION || $thisTkn->id===T_FN ){
				$nextIdx = $idxInc+1;
				if( $tknList[$nextIdx]->text==='&' ){
					$nextIdx++;
				}
				$funcNme = null;
				if( $tknList[$nextIdx]->text!=='(' ){
					$funcNme = $tknList[$nextIdx]->text;
					$nextIdx++;
				}
				$mdlList = Array();
				$prevIdx = $idxInc-1;
				while( $prevIdx>=0 && $tknList[$prevIdx]->is($modifrs) ){
					$mdlList[] = strtolower($tknList[$prevIdx]->text);
					$prevIdx--;
				}
				$docFlag = $prevIdx>=0 && $tknList[$prevIdx]->id===T_DOC_COMMENT;
				$inClass = !empty($clsDpth) && empty($fnDepth);
				$isPublc = $inClass && empty(array_intersect($mdlList, Array('private', 'protected')));
				$isMagic = $funcNme!==null && str_starts_with($funcNme, '__');
				$fnLabel = $funcNme ?? '{closure}';

				if( $funcNme!==null && !$isMagic ){
					$counter['method']++;
					if( strlen($funcNme)!==14 || !checkCamelCase($funcNme) ){
						$finding['method'][] = Array('file'=>$relPath, 'line'=>$thisTkn->line, 'scope'=>'', 'name'=>$funcNme);
					}
				}
				if( $funcNme!==null && $isPublc && !$docFlag ){
					$finding['nodoc'][] = Array('file'=>$relPath, 'line'=>$thisTkn->line, 'scope'=>'', 'name'=>$funcNme);
				}

				// Parameter list
				$nesting = 0;
				$argList = Array();
				for( ; $nextIdx<$tknSize; $nextIdx++ ){
					$argTokn = $tknList[$nextIdx];
					if( $argTokn->text==='(' ){
						$nesting++;
					}elseif( $argTokn->text===')' ){
						$nesting--;
						if( $nesting===0 ){
							break;
						}
					}elseif( $argTokn->id===T_VARIABLE && $nesting===1 ){
						$argName = substr($argTokn->text, 1);
						$argList[] = $argTokn->text;
						$counter['param']++;
						$typeIdx = $nextIdx-1;
						while( in_array($tknList[$typeIdx]->text, Array('&', '...'), true) ){
							$typeIdx--;
						}
						$isTyped = !in_array($tknList[$typeIdx]->text, Array('(', ','), true) && !$tknList[$typeIdx]->is(Array(T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY));
						if( strlen($argName)!==12 || !checkCamelCase($argName) ){
							$finding['param'][] = Array('file'=>$relPath, 'line'=>$argTokn->line, 'scope'=>$fnLabel, 'name'=>$argName);
						}
						if( !$isTyped && $funcNme!==null ){
							$finding['notype'][] = Array('file'=>$relPath, 'line'=>$argTokn->line, 'scope'=>$fnLabel, 'name'=>'$'.$argName);
						}
					}
				}

				// Closure "use" list, then the return type
				$nextIdx++;
				if( isset($tknList[$nextIdx]) && $tknList[$nextIdx]->id===T_USE ){
					while( $tknList[$nextIdx]->text!==')' ){
						if( $tknList[$nextIdx]->id===T_VARIABLE ){
							$argList[] = $tknList[$nextIdx]->text;
						}
						$nextIdx++;
					}
					$nextIdx++;
				}
				$hasRtrn = isset($tknList[$nextIdx]) && $tknList[$nextIdx]->text===':';
				if( !$hasRtrn && $funcNme!==null && !$isMagic ){
					$finding['notype'][] = Array('file'=>$relPath, 'line'=>$thisTkn->line, 'scope'=>$fnLabel, 'name'=>'(return type)');
				}

				if( $thisTkn->id===T_FUNCTION ){
					while( $nextIdx<$tknSize && !in_array($tknList[$nextIdx]->text, Array('{', ';'), true) ){
						$nextIdx++;
					}
					if( $nextIdx<$tknSize && $tknList[$nextIdx]->text==='{' ){
						$depthNb++;
						$fnDepth[] = $depthNb;
						if( count($fnDepth)===1 ){
							$outerFn = $fnLabel;
							$argsSet = $argList;
							$seenLcl = Array();
							$senLoop = Array();
						}else{
							$argsSet = array_merge($argsSet, $argList);
						}
					}
				}else{
					$argsSet = array_merge($argsSet, $argList);
				}
				$idxInc = $nextIdx;
				continue;
			}

			// Braces
			if( $thisTkn->text==='{' || $thisTkn->is(Array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES)) ){
				$depthNb++;
				if( $clsWait ){
					$clsDpth[] = $depthNb;
					$clsWait = false;
				}
				continue;
			}
			if( $thisTkn->text==='}' ){
				if( !empty($fnDepth) && end($fnDepth)===$depthNb ){
					array_pop($fnDepth);
				}
				if( !empty($clsDpth) && end($clsDpth)===$depthNb ){
					array_pop($clsDpth);
				}
				$depthNb--;
				continue;
			}

			// Variables
			if( $thisTkn->id!==T_VARIABLE ){
				continue;
			}
			if( $idxInc>0 && $tknList[$idxInc-1]->id===T_DOUBLE_COLON ){
				continue;	// static property access
			}
			if( in_array($thisTkn->text, $sprGlbl, true) ){
				continue;
			}
			$vrblNme = substr($thisTkn->text, 1);

			// Property declaration : directly inside a class body
			if( !empty($clsDpth) && empty($fnDepth) ){
				if( end($clsDpth)===$depthNb ){
					$counter['prop']++;
					if( strlen($vrblNme)!==10 || !checkCamelCase($vrblNme) ){
						$finding['prop'][] = Array('file'=>$relPath, 'line'=>$thisTkn->line, 'scope'=>'', 'name'=>$vrblNme);
					}
				}
				continue;
			}
			if( in_array($thisTkn->text, $argsSet, true) ){
				continue;
			}
			$scpName = !empty($fnDepth) ? $outerFn : '{file scope}';
			$scopeId = $scpName.'|'.$thisTkn->text;

			// Iteration variable : foreach (... as [$k =>] $v) or for ($i = ...
			$prvTkn1 = $tknList[$idxInc-1];
			$prvTkn2 = $tknList[$idxInc-2] ?? null;
			$prvTkn3 = $tknList[$idxInc-3] ?? null;
			$prvTkn4 = $tknList[$idxInc-4] ?? null;
			$isLoopV = false;
			if( $prvTkn1->id===T_AS || ($prvTkn1->text==='&' && $prvTkn2 && $prvTkn2->id===T_AS) ){
				$isLoopV = true;
			}
			if( $prvTkn1->id===T_DOUBLE_ARROW && $prvTkn2 && $prvTkn2->id===T_VARIABLE && $prvTkn3 && $prvTkn3->id===T_AS ){
				$isLoopV = true;
			}
			if( $prvTkn1->text==='&' && $prvTkn2 && $prvTkn2->id===T_DOUBLE_ARROW && $prvTkn4 && $prvTkn4->id===T_AS ){
				$isLoopV = true;
			}
			if( $prvTkn1->text==='(' && $prvTkn2 && $prvTkn2->id===T_FOR ){
				$isLoopV = true;
			}

			if( $isLoopV && !isset($senLoop[$scopeId]) ){
				$senLoop[$scopeId] = true;
				$counter['loop']++;
				if( strlen($vrblNme)!==6 || !checkCamelCase($vrblNme) ){
					$finding['loop'][] = Array('file'=>$relPath, 'line'=>$thisTkn->line, 'scope'=>$scpName, 'name'=>$vrblNme);
				}
			}
			if( !$isLoopV && !isset($seenLcl[$scopeId]) && !isset($senLoop[$scopeId]) ){
				$seenLcl[$scopeId] = true;
				$counter['local']++;
				if( strlen($vrblNme)!==7 || !checkCamelCase($vrblNme) ){
					$finding['local'][] = Array('file'=>$relPath, 'line'=>$thisTkn->line, 'scope'=>$scpName, 'name'=>$vrblNme);
				}
			}
		}
	}

	$finding['count'] = $counter;
	return $finding;
}
