<?php

namespace nyu\Access\Carr;

use nyu\Tools\Request;
use nyu\Access\Auth\Handler as AuthHndlr;
use Firebase\JWT\JWT as JwtCodec;
use Firebase\JWT\Key;

/**
 * Carries an identity via a signed JWT bearer token, verified by its
 * signature. When an Auth\Handler that knows its users is given, the
 * token's user is also confirmed against it on every request.
 *
 * HS256 signs and checks with one shared secret. RS256 signs with a
 * private key and checks with its public key: a project holding only the
 * public key checks the tokens another server issues, and issues none.
 *
 * A block naming its 'issuerId' and its audiences ('audience') puts them in
 * every token it issues ('iss', 'aud') and refuses a token that does not
 * name them. The previous public key ('prevFile') still checks the tokens
 * signed before the keys were replaced, found by their 'kid'; both are
 * published as a JWK Set (see publicSet).
 */
class JWT implements Handler{

	// The algorithms a block can name
	const ALGO_LIST = Array('HS256', 'RS256');
	// Shortest RSA key accepted, in bits
	const RSAS_BITS = 2048;

	protected $authObject;
	protected $usrJwtSecr;
	protected $jwtAlgoTxt;
	protected $expiryTime;
	protected $privatePem;
	protected $usrPublPem;
	protected $prevPemKey;
	protected $retireUnix;
	protected $issuerText;
	protected $audValList;
	protected $errMessage;
	protected $isWrongTry = false;

	/**
	 * Builds the token carrier from its config block.
	 *
	 * @param Array     $configValues Config block with 'signAlgo' (HS256 or RS256, default 'HS256'), 'lifeSecs' (seconds,
	 *                              default 3600), the 'hmacSecr' of HS256, or the 'privFile' and 'publFile' of RS256: the paths
	 *                              of the PEM files of the private and public keys, from the project root; 'prevFile', the
	 *                              path of the previous public key, with 'retireAt', the Unix time it was retired (it is not
	 *                              used without it, nor once 'lifeSecs' has passed since); 'issuerId' (a text) and 'audience'
	 *                              (a text or a list), not checked when empty.
	 * @param ?AuthHndlr $authStrategy Optional credential store confirming the token's user (see verifyReq).
	 */
	function __construct( Array $configValues, ?AuthHndlr $authStrategy = null ){
		$this->authObject = $authStrategy;
		$this->usrJwtSecr = $configValues['hmacSecr'] ?? '';
		$this->jwtAlgoTxt = strval($configValues['signAlgo'] ?? 'HS256');
		$this->expiryTime = $configValues['lifeSecs'] ?? 3600;
		$this->privatePem = $this->readPemKeyFile( $configValues['privFile'] ?? '' );
		$this->usrPublPem = $this->readPemKeyFile( $configValues['publFile'] ?? '' );
		// The previous key checks only what it signed before it was retired, and no longer than a token lasts
		$this->retireUnix = is_int($configValues['retireAt'] ?? null) ? $configValues['retireAt'] : 0;
		$this->prevPemKey = ( $this->retireUnix>0 && $this->retireUnix + intval($this->expiryTime)>=time() ) ? $this->readPemKeyFile( $configValues['prevFile'] ?? '' ) : '';
		$this->issuerText = is_scalar($configValues['issuerId'] ?? null) ? trim(strval($configValues['issuerId'])) : '';
		$this->audValList = $this->parseAudiences( $configValues['audience'] ?? null );
		$this->errMessage = '';
	}

	/**
	 * Extracts and verifies a Bearer token from the Authorization header.
	 * A token that is not valid is told as 'Invalid token', one that ended
	 * as 'Expired token': why the library refused it goes to the log only.
	 * A signed token without an expiry or a user is not valid either.
	 * With a credential store offering readIdntOfUser(), the user must still be
	 * allowed in, and its current identity (role) replaces the token's.
	 *
	 * @param Request $givenRequest The shared, already-parsed HTTP request.
	 * @return Array|Bool The decoded identity array, or false on failure.
	 */
	function checkReqstDict( Request $givenRequest ): Array|Bool {
		$this->isWrongTry = false;
		$authHedr = $givenRequest->getHeaderValue('Authorization');
		if( $authHedr===null || stripos($authHedr, 'Bearer ')!==0 ){
			$this->errMessage = 'Missing Bearer Authorization header';
			return false;
		}
		$checkKey = $this->getCheckingKey();
		if( $checkKey===null ){
			return false;
		}
		$rawToken = substr( $authHedr, 7 );
		// A token signed before the keys were replaced names the previous key in its 'kid'
		$isOldKey = false;
		if( $this->jwtAlgoTxt=='RS256' && $this->prevPemKey!='' ){
			$hedrJson = json_decode( strval(base64_decode(strtr(explode('.', $rawToken)[0], '-_', '+/'))), true );
			$askedKid = is_array($hedrJson) && is_string($hedrJson['kid'] ?? null) ? $hedrJson['kid'] : '';
			$keysList = $this->listPublicKeys();
			$isOldKey = isset($keysList[$askedKid]) && $keysList[$askedKid]===$this->prevPemKey && $askedKid!==$this->makeThumbprint();
			$checkKey = $keysList[$askedKid] ?? $checkKey;
		}
		try{
			$pyldInst = JwtCodec::decode( $rawToken, new Key($checkKey, $this->jwtAlgoTxt) );
			$idntList = (array)$pyldInst;
		}catch( \Firebase\JWT\ExpiredException $e ){
			$this->errMessage = 'Expired token';
			return false;
		}catch( \Throwable $e ){
			// What the library found is for the log : the client is only told the token is not valid
			\nyu\Tools\Logger::getLoggerOfChn('access')->debug( 'Token refused: '.get_class($e).': '.$e->getMessage() );
			$this->errMessage = 'Invalid token';
			$this->isWrongTry = true;
			return false;
		}
		// A token names its user and ends : one that does neither was not issued here
		if( !is_numeric($idntList['exp'] ?? null) || !is_scalar($idntList['userName'] ?? null) || strval($idntList['userName'])==='' ){
			\nyu\Tools\Logger::getLoggerOfChn('access')->debug( 'Token refused: signed, but without an expiry or a user' );
			$this->errMessage = 'Invalid token';
			return false;
		}
		// The previous key: only a token issued before it was retired, and ending within lifeSecs of it; a leaked key signs nothing more
		if( $isOldKey && !( is_numeric($idntList['iat'] ?? null) && $idntList['iat']<$this->retireUnix && $idntList['exp']<=$this->retireUnix + intval($this->expiryTime) ) ){
			\nyu\Tools\Logger::getLoggerOfChn('access')->debug( 'Token refused: signed with the previous key outside its time' );
			$this->errMessage = 'Invalid token';
			$this->isWrongTry = true;
			return false;
		}
		// A token issued for another project, or by another issuer, is not counted : its signature is right
		$clmnFlaw = $this->checkClaimFlaw( $idntList );
		if( $clmnFlaw!='' ){
			\nyu\Tools\Logger::getLoggerOfChn('access')->debug( 'Token refused: '.$clmnFlaw );
			$this->errMessage = 'Invalid token';
			return false;
		}
		// A credential store that knows its users confirms the token's user is still allowed in
		if( $this->authObject!==null && method_exists($this->authObject, 'readIdntOfUser') ){
			// The time the token was issued at : a password set since ends it
			$nowIdent = $this->authObject->readIdntOfUser( strval($idntList['userName'] ?? ''), intval($idntList['iat'] ?? 0) );
			if( $nowIdent===false ){
				$this->errMessage = $this->authObject->getLastErrText();
				return false;
			}
			$idntList = array_merge($idntList, $nowIdent);
		}
		return $idntList;
	}

	/**
	 * Issues a signed JWT for the given identity, expiring after the
	 * configured TTL. With RS256, the token names its key in its 'kid'
	 * header: the thumbprint of the public key (RFC 7638). The issuer and
	 * the audiences of the block are written as 'iss' and 'aud': one
	 * audience as a text, several as a list.
	 *
	 * @param Array $identityList The identity to encode into the token.
	 * @return String The issued JWT, or an empty string when the secret or the private key is not usable.
	 */
	function issueTokenText( Array $identityList ): String {
		$signClef = $this->findSigningKey();
		if( $signClef===null ){
			return '';
		}
		$pyldInst = $identityList;
		// Only the block names the issuer and the audiences, never the identity
		unset( $pyldInst['iss'], $pyldInst['aud'] );
		if( $this->issuerText!='' ){
			$pyldInst['iss'] = $this->issuerText;
		}
		if( !empty($this->audValList) ){
			$pyldInst['aud'] = (count($this->audValList)==1) ? $this->audValList[0] : $this->audValList;
		}
		$pyldInst['iat'] = time();
		$pyldInst['exp'] = time() + $this->expiryTime;
		return JwtCodec::encode( $pyldInst, $signClef, $this->jwtAlgoTxt, ($this->jwtAlgoTxt=='RS256') ? $this->makeThumbprint() : null );
	}

	/**
	 * Tells whether the block only checks tokens: RS256 with a public key
	 * and no private key, the tokens being issued by another server.
	 *
	 * @return Bool True when the block checks tokens and issues none.
	 */
	public function isCheckingOnly(): Bool {
		return $this->jwtAlgoTxt=='RS256' && $this->privatePem=='' && $this->getPubKeyOfRsa()!==null;
	}

	/**
	 * Gives the key a token is checked with: the secret (HS256, 32 bytes at
	 * least), or the public key (RS256, of RSAMINIM bits at least), drawn
	 * from the private key when the block names none.
	 *
	 * @return ?String The key, or null when it is not usable; getLastErrText() then tells why.
	 */
	protected function getCheckingKey(): ?String {
		if( $this->jwtAlgoTxt=='RS256' ){
			$checkKey = $this->getPubKeyOfRsa();
			if( $checkKey===null ){
				$this->errMessage = 'Token keys not configured';
			}
			return $checkKey;
		}
		if( $this->jwtAlgoTxt!='HS256' || !is_string($this->usrJwtSecr) || strlen($this->usrJwtSecr)<32 ){
			$this->errMessage = 'JWT secret not configured';
			return null;
		}
		return $this->usrJwtSecr;
	}

	/**
	 * Gives the key a token is signed with: the secret (HS256), or the
	 * private key (RS256, of RSAMINIM bits at least).
	 *
	 * @return ?String The key, or null when it is not usable; getLastErrText() then tells why.
	 */
	protected function findSigningKey(): ?String {
		if( $this->jwtAlgoTxt!='RS256' ){
			return $this->getCheckingKey();
		}
		if( $this->isCheckingOnly() ){
			$this->errMessage = 'Tokens are issued by another server';
			return null;
		}
		$keyInstn = ($this->privatePem!='' && function_exists('openssl_pkey_get_private')) ? @openssl_pkey_get_private($this->privatePem) : false;
		if( $keyInstn===false || !$this->isStrongRsaKey($keyInstn) ){
			$this->errMessage = 'Token keys not configured';
			return null;
		}
		return $this->privatePem;
	}

	/**
	 * Gives the public key of RS256: the one the block names, or the one
	 * drawn from its private key.
	 *
	 * @return ?String The public key as PEM, or null when there is none of RSAMINIM bits at least.
	 */
	protected function getPubKeyOfRsa(): ?String {
		if( !function_exists('openssl_pkey_get_public') ){
			return null;
		}
		if( $this->usrPublPem!='' ){
			$keyInstn = @openssl_pkey_get_public($this->usrPublPem);
		}else{
			$keyInstn = ($this->privatePem!='') ? @openssl_pkey_get_private($this->privatePem) : false;
		}
		if( $keyInstn===false || !$this->isStrongRsaKey($keyInstn) ){
			return null;
		}
		$publText = strval( openssl_pkey_get_details($keyInstn)['key'] ?? '' );
		return ($publText!='') ? $publText : null;
	}

	/**
	 * Tells whether a key is an RSA key of RSAMINIM bits at least.
	 *
	 * @param \OpenSSLAsymmetricKey $rsaKeyObject The key.
	 * @return Bool Whether it can be used.
	 */
	protected function isStrongRsaKey( \OpenSSLAsymmetricKey $rsaKeyObject ): Bool {
		$keyArray = openssl_pkey_get_details($rsaKeyObject);
		return is_array($keyArray) && ($keyArray['type'] ?? null)===OPENSSL_KEYTYPE_RSA && intval($keyArray['bits'] ?? 0)>=self::RSAS_BITS;
	}

	/**
	 * Gives the thumbprint of a public key (RFC 7638), which a token
	 * names as its 'kid': the name a server publishes the key under.
	 *
	 * @param ?String $publicKeyTxt The public key as PEM; the current one when not given.
	 * @return ?String The thumbprint, base64url; null without a public key.
	 */
	protected function makeThumbprint( ?String $publicKeyTxt=null ): ?String {
		$publText = $publicKeyTxt ?? $this->getPubKeyOfRsa();
		$jwkArray = ($publText!==null) ? $this->castRsaIntoJwk($publText) : null;
		return ($jwkArray!==null) ? $jwkArray['kid'] : null;
	}

	/**
	 * Gives a public RSA key as a JWK (RFC 7517), named by its thumbprint
	 * (RFC 7638) and meant for checking RS256 signatures.
	 *
	 * @param String $publicKeyTxt The public key as PEM.
	 * @return ?Array The JWK ('kty', 'use', 'alg', 'kid', 'n', 'e'); null when it is not an RSA key of RSAMINIM bits at least.
	 */
	protected function castRsaIntoJwk( String $publicKeyTxt ): ?Array {
		$keyInstn = function_exists('openssl_pkey_get_public') ? @openssl_pkey_get_public($publicKeyTxt) : false;
		if( $keyInstn===false || !$this->isStrongRsaKey($keyInstn) ){
			return null;
		}
		$keyArray = openssl_pkey_get_details($keyInstn);
		if( !is_array($keyArray) || !isset($keyArray['rsa']['n'], $keyArray['rsa']['e']) ){
			return null;
		}
		$modelTxt = $this->castBase64Text($keyArray['rsa']['n']);
		$expnText = $this->castBase64Text($keyArray['rsa']['e']);
		// The members the thumbprint is made of, in the order of their names, without spaces
		$jwkStrng = '{"e":"'.$expnText.'","kty":"RSA","n":"'.$modelTxt.'"}';
		return Array( 'kty'=>'RSA', 'use'=>'sig', 'alg'=>'RS256', 'kid'=>$this->castBase64Text(hash('sha256', $jwkStrng, true)), 'n'=>$modelTxt, 'e'=>$expnText );
	}

	/**
	 * Gives the public keys a token of RS256 is checked with, by their
	 * 'kid': the current one first, then the previous one ('prevFile'), while
	 * the tokens it signed before it was retired may still last.
	 *
	 * @return Array The keys as PEM, by thumbprint; empty with HS256 or without a usable key.
	 */
	protected function listPublicKeys(): Array {
		if( $this->jwtAlgoTxt!='RS256' ){
			return Array();
		}
		$keysList = Array();
		$pemStrng = $this->getPubKeyOfRsa();
		foreach( Array($pemStrng, ($this->prevPemKey!='') ? $this->prevPemKey : null) as $oneTxt ){
			$keyIdent = ($oneTxt!==null) ? $this->makeThumbprint($oneTxt) : null;
			if( $keyIdent!==null && !isset($keysList[$keyIdent]) ){
				$keysList[$keyIdent] = $oneTxt;
			}
		}
		return $keysList;
	}

	/**
	 * Gives the public keys of the block as a JWK Set (RFC 7517), for other
	 * projects to check its tokens without a copy of a key file: the keys
	 * of RS256 only, when the block issues tokens. A secret of HS256 is
	 * never published, nor the key of another server.
	 *
	 * @return Array The set, under 'keys'; the list is empty when there is nothing to publish.
	 */
	public function getJwksOfBlock(): Array {
		$keysList = Array();
		if( !$this->isCheckingOnly() ){
			foreach( $this->listPublicKeys() as $oneTxt ){
				$keysList[] = $this->castRsaIntoJwk($oneTxt);
			}
		}
		return Array( 'keys'=>array_values(array_filter($keysList)) );
	}

	/**
	 * Tells why the issuer or the audience of a token is not the one of
	 * the block: 'iss' must be its 'issuerId', and 'aud' (a text or a list)
	 * name one of its audiences. Neither is checked while the block names
	 * none.
	 *
	 * @param Array $identityList The decoded claims of the token.
	 * @return String The reason, for the log; empty when the token is the block's.
	 */
	protected function checkClaimFlaw( Array $identityList ): String {
		if( $this->issuerText!='' ){
			$tokenIsu = $identityList['iss'] ?? null;
			if( !is_string($tokenIsu) || $tokenIsu=='' ){
				return 'no issuer';
			}
			if( $tokenIsu!==$this->issuerText ){
				return 'issuer '.json_encode($tokenIsu).' is not '.json_encode($this->issuerText);
			}
		}
		if( !empty($this->audValList) ){
			$audnList = $this->parseAudiences( $identityList['aud'] ?? null );
			if( empty($audnList) ){
				return 'no audience';
			}
			if( empty(array_intersect($audnList, $this->audValList)) ){
				return 'audience '.json_encode((count($audnList)==1) ? $audnList[0] : $audnList).' is not one of '.json_encode($this->audValList);
			}
		}
		return '';
	}

	/**
	 * Reads audiences, as the config or a token gives them: a text, or a
	 * list of texts.
	 *
	 * @param Mixed $configValues The value given.
	 * @return Array The audiences, non-empty texts, each once.
	 */
	protected function parseAudiences( Mixed $configValues ): Array {
		$audnList = Array();
		foreach( (is_array($configValues) ? $configValues : Array($configValues)) as $oneVal ){
			if( is_string($oneVal) && trim($oneVal)!='' && !in_array(trim($oneVal), $audnList, true) ){
				$audnList[] = trim($oneVal);
			}
		}
		return $audnList;
	}

	/**
	 * Encodes bytes as base64url, without padding.
	 *
	 * @param String $usrBinaryTxt The bytes.
	 * @return String The encoded text.
	 */
	protected function castBase64Text( String $usrBinaryTxt ): String {
		return rtrim( strtr(base64_encode($usrBinaryTxt), '+/', '-_'), '=' );
	}

	/**
	 * Reads a key file the block names, from the project root.
	 *
	 * @param Mixed $settingsPath The path the block gives.
	 * @return String The content of the file, empty when there is none.
	 */
	protected function readPemKeyFile( Mixed $settingsPath ): String {
		$keyTrail = is_scalar($settingsPath) ? trim(strval($settingsPath)) : '';
		if( $keyTrail=='' ){
			return '';
		}
		if( !preg_match('#^([A-Za-z]:|[/\\\\])#', $keyTrail) && defined('TOP_DIR') ){
			$keyTrail = TOP_DIR.DIRECTORY_SEPARATOR.str_replace(Array('/', '\\'), DIRECTORY_SEPARATOR, $keyTrail);
		}
		return is_file($keyTrail) ? strval(@file_get_contents($keyTrail)) : '';
	}

	/**
	 * Tells whether the last refusal was a token that was not issued here
	 * (wrong signature, not a token), as opposed to a missing one, one that
	 * ended, or a user no longer allowed in: only a forged token counts
	 * against the address that presented it (see Access\Limiter).
	 *
	 * @return Bool True when a token was presented and found forged.
	 */
	public function hasWrongSecret(): Bool {
		return $this->isWrongTry;
	}

	/**
	 * Gives the message of the last error.
	 *
	 * @return String The last error message.
	 */
	function getLastErrText(): String {
		return $this->errMessage;
	}
}
