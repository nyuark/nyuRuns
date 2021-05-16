<?php

namespace nyu\Runs\Auth;

use \nyu\App\Actionset;
use \nyu\Access\Auth\PassList;
use \nyu\Access\Sess\JWT;

class Actions extends Actionset {

	/**
	 * Checks the 'user' and 'pass' inputs against the htpasswd
	 * credential store and, on success, issues a signed JWT for the
	 * matched identity. Reports 400 when an input is missing and 401
	 * on invalid credentials.
	 */
	public function login(): Void {
		$gvnUser = $this->inputReg->readValue('user');
		$gvnPass = $this->inputReg->readValue('pass');

		if($gvnUser==null || $gvnPass==null){
			$this->statCode = 400;
			$this->statMssg = 'Missing user or pass';
			return;
		}

		$authObj = new PassList( $this->resolvCnf('basic_default') );
		$idntArr = $authObj->checkCred( Array('user'=>$gvnUser, 'pass'=>$gvnPass) );

		if($idntArr===false){
			$this->statCode = 401;
			$this->statMssg = $authObj->lastError();
			return;
		}

		$sessObj = new JWT( $this->resolvCnf('jwt_default') );
		$rawTokn = $sessObj->issueTokn( $idntArr );

		$this->statCode = 200;
		$this->asOutput = Array('token'=>$rawTokn);
	}

	protected function resolvCnf( String $confRowKey ): Array {
		$nyuConf = $GLOBALS['nyuConfig'];
		$axsCnfg = $nyuConf->getRowVal('ACCESS', strtoupper($confRowKey));
		return is_array($axsCnfg) ? $axsCnfg : Array();
	}
}
