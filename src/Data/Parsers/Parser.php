<?php

namespace nyu\Data\Parsers;

use nyu\Data\Fluxd;

/**
 * What every parser does: read a content and give it back as one of three
 * shapes, in the same envelope:
 *
 * - 'shape' => 'nodes' (a tree of keys and values), 'sheet' (named tables
 *              of rows and cells) or 'block' (text blocks, in order);
 * - 'infos' => details about the source, such as sheet names or a title;
 * - 'items' => the content itself.
 *
 * A content that is not valid for its format raises a ParseError.
 */
interface Parser {

	/**
	 * Parses a content already in hand.
	 *
	 * @param String $nakedContent The content, as read from its source.
	 * @param Array  $parseOptions Options of the parser.
	 * @return Array The envelope: 'shape', 'infos' and 'items'.
	 *
	 * @throws ParseError When the content is not valid.
	 */
	public function parseDataArray(String $nakedContent, Array $parseOptions=Array()): Array;

	/**
	 * Parses the content of a source read piece by piece.
	 *
	 * @param Fluxd $sourceStream The source.
	 * @param Array $parseOptions Options of the parser.
	 * @return Array The envelope: 'shape', 'infos' and 'items'.
	 *
	 * @throws ParseError When the content is not valid.
	 */
	public function parseFluxArray(Fluxd $sourceStream, Array $parseOptions=Array()): Array;

}
