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
	 * @param String $rawContent The content, as read from its source.
	 * @param Array  $parseOptns Options of the parser.
	 * @return Array The envelope: 'shape', 'infos' and 'items'.
	 *
	 * @throws ParseError When the content is not valid.
	 */
	public function parseData(String $rawContent, Array $parseOptns=Array()): Array;

	/**
	 * Parses the content of a source read piece by piece.
	 *
	 * @param Fluxd $sourceFlux The source.
	 * @param Array $parseOptns Options of the parser.
	 * @return Array The envelope: 'shape', 'infos' and 'items'.
	 *
	 * @throws ParseError When the content is not valid.
	 */
	public function parseFlux(Fluxd $sourceFlux, Array $parseOptns=Array()): Array;

}
