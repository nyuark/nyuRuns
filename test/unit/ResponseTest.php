<?php

// Output\Response : body formatting, templates and the derived Content-Type.
// Headers themselves are checked over HTTP (see test/http), since PHP does not
// keep them when run from the command line.

require __DIR__.'/../lib/harness.php';

use nyu\Output\Response;

/**
 * Builds a response body and returns what would be sent.
 *
 * @param Array $renderVals Render configuration for the Response.
 * @param Array $dataValues Data to format.
 * @return String The body.
 */
function builtBody( Array $renderVals, Array $dataValues ): String {
	$respons = new Response($renderVals);
	$respons->formatOut($dataValues);
	$respons->buildTmpl();
	ob_start();
	$respons->sendOutpt();
	return ob_get_clean();
}

/**
 * Reads the Content-Type a Response would derive.
 *
 * @param Array $renderVals Render configuration for the Response.
 * @return String The Content-Type value.
 */
function foundType( Array $renderVals ): String {
	$respons = new Response($renderVals);
	$typeFnc = new ReflectionMethod($respons, 'contnType');
	$typeFnc->setAccessible(true);
	return $typeFnc->invoke($respons);
}

checkSame('JSON body',               builtBody(Array(), Array('a'=>1, 'b'=>Array('c'))), '{"a":1,"b":["c"]}');
checkSame('JSON is the default',     builtBody(Array('return'=>'json'), Array()), '[]');
checkSame('format name is case-insensitive', builtBody(Array('return'=>'Json'), Array('a'=>1)), '{"a":1}');
checkSame('other format is serialized', unserialize(builtBody(Array('return'=>'php'), Array('a'=>1))), Array('a'=>1));
checkLike('excel template wraps the data', builtBody(Array('tmplte'=>'excel'), Array('a'=>1)), '/^<html.*\{"a":1\}/s');

// An unknown template : the data is still sent, through the plain template, and a warning is raised
checkSame('unknown template falls back to plain', builtBody(Array('tmplte'=>'nosuchtemplate'), Array('a'=>1)), '{"a":1}');
checkSame('unknown template raises one warning', count(preg_grep('/Unknown output template "nosuchtemplate", using "plain"/', takeNotes())), 1);

// The template must be usable twice in one request (error reply after a body was prepared)
$respons = new Response( Array('tmplte'=>'plain', 'return'=>'json') );
$respons->formatOut( Array('first'=>1) );
$respons->buildTmpl();
$respons->formatOut( Array('error'=>'second', 'code'=>500) );
$respons->buildTmpl();
ob_start();
$respons->sendOutpt();
checkSame('body built twice keeps the last one', ob_get_clean(), '{"error":"second","code":500}');

checkSame('type for plain + json',   foundType(Array('tmplte'=>'plain', 'return'=>'json')), 'application/json; charset=utf-8');
checkSame('type for the defaults',   foundType(Array()), 'application/json; charset=utf-8');
checkSame('type for plain + other',  foundType(Array('return'=>'php')), 'text/plain; charset=utf-8');
checkSame('type for excel',          foundType(Array('tmplte'=>'excel')), 'application/vnd.ms-excel');
checkSame('type for mswrd',          foundType(Array('tmplte'=>'mswrd')), 'application/msword');
checkSame('type for optab',          foundType(Array('tmplte'=>'optab')), 'application/vnd.oasis.opendocument.spreadsheet');

endChecks();
