<?php

namespace nyu\Data\Parsers;

use nyu\Data\Shape;

/**
 * PDF documents, read into text blocks through the smalot/pdfparser
 * library: the text of each page as paragraphs, a page break standing
 * between two pages.
 *
 * A PDF places its text on the page without saying what it is: headings,
 * lists and tables are read as the lines they are made of. A page that
 * is a picture of a text, as a scanned one is, gives nothing.
 */
class Pdfx extends Base {

	/**
	 * Tells the kinds of content the parser reads, as Bina::detectFileKind()
	 * names them.
	 *
	 * @return Array The kinds.
	 */
	public function signsReadArray(): Array {
		return Array('pdf');
	}

	/**
	 * Parses a PDF document. An empty content gives no block.
	 *
	 * @param String $nakedContent The bytes of the document.
	 * @param Array  $parseOptions Options: none.
	 * @return Array The envelope, of shape 'block'; 'infos' holds the title of the document under 'title', its author under 'authr', the number of its pages under 'pages', and what it tells of itself under 'props'.
	 *
	 * @throws ParseError When the library or the mbstring extension is missing, the content is not a PDF document, or the document is protected by a password.
	 */
	public function parseDataArray(String $nakedContent, Array $parseOptions=Array()): Array {
		if( $nakedContent==='' ){
			return $this->makeShapeArray('block', Array(), Array('title'=>'', 'authr'=>'', 'pages'=>0, 'props'=>Array()));
		}
		if( !class_exists('\Smalot\PdfParser\Parser') ){
			throw new ParseError('Reading PDF documents needs the smalot/pdfparser library');
		}
		// The library reads the texts of a document with this extension; what stands in for it when it is missing floods warnings
		if( !extension_loaded('mbstring') ){
			throw new ParseError('Reading PDF documents needs the mbstring extension of PHP');
		}
		if( Bina::detectFileKind(substr($nakedContent, 0, 512))!='pdf' ){
			throw new ParseError('Invalid PDF content: the content is not a PDF document');
		}
		try{
			// The pictures a document holds are not read: they are left out of memory
			$optsDict = new \Smalot\PdfParser\Config();
			$optsDict->setRetainImageContent(false);
			$pdfDcmnt = (new \Smalot\PdfParser\Parser(Array(), $optsDict))->parseContent($nakedContent);
			$pageList = $pdfDcmnt->getPages();
			$detlList = $pdfDcmnt->getDetails();
			$blockLst = Array();
			foreach($pageList as $pgeIdx=>$pgeObj){
				if( $pgeIdx>0 ){
					$blockLst[] = Shape::makeBlockArray('ruleLine', Array(), Array(), Array('break'=>true));
				}
				// An empty line stands between two paragraphs; the lines of a paragraph are those of the page
				$pageText = self::recodeTextUtf8(str_replace(Array("\r\n", "\r"), "\n", strval($pgeObj->getText())));
				foreach(preg_split('/\n[ \t]*(?:\n[ \t]*)+/', preg_replace('/[ \t]+$/m', '', $pageText)) as $parTxt){
					if( trim($parTxt)!=='' ){
						$blockLst[] = Shape::makeBlockArray('paragrph', Array(Shape::makeASpanArray(trim($parTxt))));
					}
				}
			}
		}catch( \Throwable $e ){
			$failText = (stripos($e->getMessage(), 'secured')!==false || stripos($e->getMessage(), 'encrypt')!==false) ? 'the document is protected by a password' : $e->getMessage();
			throw new ParseError('Invalid PDF content: '.$failText, 0, $e);
		}
		// What the document tells of itself: single values only, as text
		$propList = Array();
		foreach((array)$detlList as $dtlKey=>$dtlVal){
			if( is_scalar($dtlVal) && trim(strval($dtlVal))!=='' ){
				$propList[strval($dtlKey)] = is_string($dtlVal) ? self::recodeTextUtf8(trim($dtlVal)) : $dtlVal;
			}
		}
		return $this->makeShapeArray('block', $blockLst, Array('title'=>strval($propList['Title'] ?? ''), 'authr'=>strval($propList['Author'] ?? ''), 'pages'=>count($pageList), 'props'=>$propList));
	}

}
