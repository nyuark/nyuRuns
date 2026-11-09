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
			$options = new \Smalot\PdfParser\Config();
			$options->setRetainImageContent(false);
			$pdfDocu = (new \Smalot\PdfParser\Parser(Array(), $options))->parseContent($nakedContent);
			$pageLst = $pdfDocu->getPages();
			$dtlList = $pdfDocu->getDetails();
			$blckLst = Array();
			foreach($pageLst as $pgeIdx=>$pgeObj){
				if( $pgeIdx>0 ){
					$blckLst[] = Shape::makeBlockArray('rules', Array(), Array(), Array('break'=>true));
				}
				// An empty line stands between two paragraphs; the lines of a paragraph are those of the page
				$pageTxt = self::recodeTextUtf8(str_replace(Array("\r\n", "\r"), "\n", strval($pgeObj->getText())));
				foreach(preg_split('/\n[ \t]*(?:\n[ \t]*)+/', preg_replace('/[ \t]+$/m', '', $pageTxt)) as $parTxt){
					if( trim($parTxt)!=='' ){
						$blckLst[] = Shape::makeBlockArray('parag', Array(Shape::makeASpanArray(trim($parTxt))));
					}
				}
			}
		}catch( \Throwable $e ){
			$failMsg = (stripos($e->getMessage(), 'secured')!==false || stripos($e->getMessage(), 'encrypt')!==false) ? 'the document is protected by a password' : $e->getMessage();
			throw new ParseError('Invalid PDF content: '.$failMsg, 0, $e);
		}
		// What the document tells of itself: single values only, as text
		$propLst = Array();
		foreach((array)$dtlList as $dtlKey=>$dtlVal){
			if( is_scalar($dtlVal) && trim(strval($dtlVal))!=='' ){
				$propLst[strval($dtlKey)] = is_string($dtlVal) ? self::recodeTextUtf8(trim($dtlVal)) : $dtlVal;
			}
		}
		return $this->makeShapeArray('block', $blckLst, Array('title'=>strval($propLst['Title'] ?? ''), 'authr'=>strval($propLst['Author'] ?? ''), 'pages'=>count($pageLst), 'props'=>$propLst));
	}

}
