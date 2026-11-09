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
	const SIZE_LIST = Array('isoPgeA3'=>'A3', 'isoPgeA4'=>'A4', 'isoPgeA5'=>'A5', 'usLetter'=>'letter', 'legalUsa'=>'legal');

	/**
	 * Writes a content as a PDF document.
	 *
	 * Options:
	 *   'paper' => the size of the pages: 'isoPgeA3', 'isoPgeA4', 'isoPgeA5',
	 *              'usLetter' or 'legalUsa'; 'isoPgeA4' when left out;
	 *   'lands' => lays the pages out on their long side;
	 *   and those of the HTML writer: 'class', 'heads', 'shown'.
	 *
	 * @param Array $dataEnvelope The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptions Options: 'paper', 'lands', 'class', 'heads', 'shown'.
	 * @return String The bytes of the document.
	 *
	 * @throws WriteError When the library or the mbstring extension is missing, the shape is not a known one, the size of paper is not a known one, or the document cannot be laid out.
	 */
	public function renderDocument(Array $dataEnvelope, Array $writeOptions=Array()): String {
		$this->checkShapeText($dataEnvelope, Array('block', 'sheet', 'nodes'), 'PDF');
		$sizeKey = strval($writeOptions['paper'] ?? 'isoPgeA4');
		// The size whatever its case, as SIZE_LIST writes it
		$sizeKey = strval( array_values(preg_grep('/^'.preg_quote($sizeKey, '/').'$/iD', array_keys(self::SIZE_LIST)))[0] ?? $sizeKey );
		if( !isset(self::SIZE_LIST[$sizeKey]) ){
			throw new WriteError('Invalid PDF option: unknown paper '.$sizeKey);
		}
		if( !class_exists('\Dompdf\Dompdf') ){
			throw new WriteError('Writing PDF documents needs the dompdf/dompdf library');
		}
		if( !extension_loaded('mbstring') ){
			throw new WriteError('Writing PDF documents needs the mbstring extension of PHP');
		}
		$htmPage = (new Html())->renderDocument($dataEnvelope, Array('whole'=>true, 'frmat'=>true) + array_intersect_key($writeOptions, array_flip(Array('class', 'heads', 'shown'))));
		try{
			$options = new \Dompdf\Options();
			// The document is made of what it is given, and of nothing it could go and fetch or run
			$options->setIsRemoteEnabled(false);
			$options->setIsPhpEnabled(false);
			$options->setIsJavascriptEnabled(false);
			$options->setAllowedProtocols(Array());
			// A font holding the accents is the one a text falls back on; what the library keeps of its fonts goes to the temporary folder
			$options->setDefaultFont('DejaVu Sans');
			$options->setFontCache(sys_get_temp_dir());
			$options->setTempDir(sys_get_temp_dir());
			$pdfDocu = new \Dompdf\Dompdf($options);
			$pdfDocu->loadHtml($htmPage, 'UTF-8');
			$pdfDocu->setPaper(self::SIZE_LIST[$sizeKey], empty($writeOptions['lands']) ? 'portrait' : 'landscape');
			$pdfDocu->render();
			return strval($pdfDocu->output());
		}catch( \Throwable $e ){
			throw new WriteError('Content that cannot be written as PDF: '.$e->getMessage(), 0, $e);
		}
	}

}
