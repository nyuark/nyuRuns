<?php

namespace nyu\Data\Writers;

/**
 * What every writer does: take a content in the envelope the parsers give
 * ('shape', 'infos', 'items') and write it in its own format.
 */
interface Writer {

	/**
	 * Writes a content in the format of the writer.
	 *
	 * @param Array $dataEnvelope The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptions Options of the writer.
	 * @return String The content, as text or bytes of the format.
	 *
	 * @throws \RuntimeException When the shape is not one the writer can write.
	 */
	public function renderDocument(Array $dataEnvelope, Array $writeOptions=Array()): String;

}
