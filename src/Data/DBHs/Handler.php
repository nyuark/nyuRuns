<?php

namespace nyu\Data\DBHs;

interface Handler{
	
	public function __construct(String $serverHost, String $serverUser, String $serverPass, String $serverBase);
	
	public function openDBase(String $serverHost, String $serverUser, String $serverPass, String $serverBase);
//	public function makeQuery();
	public function sendQuery(String $builtQuery);
	public function readQuery();
	public function lastError();
	public function escapeStr(String $str2Escape);
	public function exitDBase();
	
}