<?php

namespace nyu\Data\Writers;

/**
 * PDF documents, written through the dompdf/dompdf library: the content
 * is written as a whole HTML page, with the style sheet of its styles,
 * and that page is laid out on paper.
 *
 * Nothing is fetched while the document is made: neither from the
 * network nor from the disk. An image is therefore not drawn; its
 * description is.
 */
class Pdfx extends Base {

	// The sizes of paper, by the code that asks for them
	const SIZELIST = Array('isoa3'=>'A3', 'isoa4'=>'A4', 'isoa5'=>'A5', 'lettr'=>'letter', 'legal'=>'legal');

	/**
	 * Writes a content as a PDF document.
	 *
	 * Options:
	 *   'paper' => the size of the pages: 'isoa3', 'isoa4', 'isoa5',
	 *              'lettr' or 'legal'; 'isoa4' when left out;
	 *   'lands' => lays the pages out on their long side;
	 *   and those of the HTML writer: 'class', 'heads', 'shown'.
	 *
	 * @param Array $shapedData The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptns Options: 'paper', 'lands', 'class', 'heads', 'shown'.
	 * @return String The bytes of the document.
	 *
	 * @throws WriteError When the library or the mbstring extension is missing, the shape is not a known one, the size of paper is not a known one, or the document cannot be laid out.
	 */
	public function writeData(Array $shapedData, Array $writeOptns=Array()): String {
		$this->checkShpe($shapedData, Array('block', 'sheet', 'nodes'), 'PDF');
		$sizeKey = strtolower(strval($writeOptns['paper'] ?? 'isoa4'));
		if( !isset(self::SIZELIST[$sizeKey]) ){
			throw new WriteError('Invalid PDF option: unknown paper '.$sizeKey);
		}
		if( !class_exists('\Dompdf\Dompdf') ){
			throw new WriteError('Writing PDF documents needs the dompdf/dompdf library');
		}
		if( !extension_loaded('mbstring') ){
			throw new WriteError('Writing PDF documents needs the mbstring extension of PHP');
		}
		$htmPage = (new Html())->writeData($shapedData, Array('whole'=>true, 'frmat'=>true) + array_intersect_key($writeOptns, array_flip(Array('class', 'heads', 'shown'))));
		try{
			$libOpts = new \Dompdf\Options();
			// The document is made of what it is given, and of nothing it could go and fetch or run
			$libOpts->setIsRemoteEnabled(false);
			$libOpts->setIsPhpEnabled(false);
			$libOpts->setIsJavascriptEnabled(false);
			$libOpts->setAllowedProtocols(Array());
			// A font holding the accents is the one a text falls back on; what the library keeps of its fonts goes to the temporary folder
			$libOpts->setDefaultFont('DejaVu Sans');
			$libOpts->setFontCache(sys_get_temp_dir());
			$libOpts->setTempDir(sys_get_temp_dir());
			$pdfDocu = new \Dompdf\Dompdf($libOpts);
			$pdfDocu->loadHtml($htmPage, 'UTF-8');
			$pdfDocu->setPaper(self::SIZELIST[$sizeKey], empty($writeOptns['lands']) ? 'portrait' : 'landscape');
			$pdfDocu->render();
			return strval($pdfDocu->output());
		}catch( \Throwable $e ){
			throw new WriteError('Content that cannot be written as PDF: '.$e->getMessage(), 0, $e);
		}
	}

}
