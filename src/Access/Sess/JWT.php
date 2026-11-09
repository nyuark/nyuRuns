<?php

namespace nyu\Access\Sess;

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
 * A block naming its 'issuer' and its audiences ('audnce') puts them in
 * every token it issues ('iss', 'aud') and refuses a token that does not
 * name them. The previous public key ('oldpub') still checks the tokens
 * signed before the keys were replaced, found by their 'kid'; both are
 * published as a JWK Set (see publicSet).
 */
class JWT implements Handler{

	// The algorithms a block can name
	const ALGOLIST = Array('HS256', 'RS256');
	// Shortest RSA key accepted, in bits
	const RSAMINIM = 2048;

	protected $authObjc;
	protected $jwtSecrt;
	protected $jwtAlgor;
	protected $jwtExpir;
	protected $privtPem;
	protected $publcPem;
	protected $oldPbPem;
	protected $oldRetir;
	protected $jwtIssur;
	protected $jwtAudnc;
	protected $lastMssg;
	protected $wrongCrd = false;

	/**
	 * Builds the token carrier from its config block.
	 *
	 * @param Array     $confValues Config block with 'algrtm' (HS256 or RS256, default 'HS256'), 'lifetm' (seconds,
	 *                              default 3600), the 'secret' of HS256, or the 'prvkey' and 'pubkey' of RS256: the paths
	 *                              of the PEM files of the private and public keys, from the project root; 'oldpub', the
	 *                              path of the previous public key, with 'retire', the Unix time it was retired (it is not
	 *                              used without it, nor once 'lifetm' has passed since); 'issuer' (a text) and 'audnce'
	 *                              (a text or a list), not checked when empty.
	 * @param ?AuthHndlr $authHandlr Optional credential store confirming the token's user (see verifyReq).
	 */
	function __construct( Array $confValues, ?AuthHndlr $authHandlr = null ){
		$this->authObjc = $authHandlr;
		$this->jwtSecrt = $confValues['secret'] ?? '';
		$this->jwtAlgor = strval($confValues['algrtm'] ?? 'HS256');
		$this->jwtExpir = $confValues['lifetm'] ?? 3600;
		$this->privtPem = $this->readsKeys( $confValues['prvkey'] ?? '' );
		$this->publcPem = $this->readsKeys( $confValues['pubkey'] ?? '' );
		// The previous key checks only what it signed before it was retired, and no longer than a token lasts
		$this->oldRetir = is_int($confValues['retire'] ?? null) ? $confValues['retire'] : 0;
		$this->oldPbPem = ( $this->oldRetir>0 && $this->oldRetir + intval($this->jwtExpir)>=time() ) ? $this->readsKeys( $confValues['oldpub'] ?? '' ) : '';
		$this->jwtIssur = is_scalar($confValues['issuer'] ?? null) ? trim(strval($confValues['issuer'])) : '';
		$this->jwtAudnc = $this->readsAuds( $confValues['audnce'] ?? null );
		$this->lastMssg = '';
	}

	/**
	 * Extracts and verifies a Bearer token from the Authorization header.
	 * A token that is not valid is told as 'Invalid token', one that ended
	 * as 'Expired token': why the library refused it goes to the log only.
	 * A signed token without an expiry or a user is not valid either.
	 * With a credential store offering userIdent(), the user must still be
	 * allowed in, and its current identity (role) replaces the token's.
	 *
	 * @param Request $requestObj The shared, already-parsed HTTP request.
	 * @return Array|Bool The decoded identity array, or false on failure.
	 */
	function verifyReq( Request $requestObj ): Array|Bool {
		$this->wrongCrd = false;
		$authHed = $requestObj->getHeader('Authorization');
		if( $authHed===null || stripos($authHed, 'Bearer ')!==0 ){
			$this->lastMssg = 'Missing Bearer Authorization header';
			return false;
		}
		$chckKey = $this->canVerify();
		if( $chckKey===null ){
			return false;
		}
		$rawTokn = substr( $authHed, 7 );
		// A token signed before the keys were replaced names the previous key in its 'kid'
		$byOldKy = false;
		if( $this->jwtAlgor=='RS256' && $this->oldPbPem!='' ){
			$hedJson = json_decode( strval(base64_decode(strtr(explode('.', $rawTokn)[0], '-_', '+/'))), true );
			$askdKid = is_array($hedJson) && is_string($hedJson['kid'] ?? null) ? $hedJson['kid'] : '';
			$keysArr = $this->checkKeys();
			$byOldKy = isset($keysArr[$askdKid]) && $keysArr[$askdKid]===$this->oldPbPem && $askdKid!==$this->keysThumb();
			$chckKey = $keysArr[$askdKid] ?? $chckKey;
		}
		try{
			$payload = JwtCodec::decode( $rawTokn, new Key($chckKey, $this->jwtAlgor) );
			$idntArr = (array)$payload;
		}catch( \Firebase\JWT\ExpiredException $e ){
			$this->lastMssg = 'Expired token';
			return false;
		}catch( \Throwable $e ){
			// What the library found is for the log : the client is only told the token is not valid
			\nyu\Tools\Logger::getLogger('access')->debug( 'Token refused: '.get_class($e).': '.$e->getMessage() );
			$this->lastMssg = 'Invalid token';
			$this->wrongCrd = true;
			return false;
		}
		// A token names its user and ends : one that does neither was not issued here
		if( !is_numeric($idntArr['exp'] ?? null) || !is_scalar($idntArr['user'] ?? null) || strval($idntArr['user'])==='' ){
			\nyu\Tools\Logger::getLogger('access')->debug( 'Token refused: signed, but without an expiry or a user' );
			$this->lastMssg = 'Invalid token';
			return false;
		}
		// The previous key: only a token issued before it was retired, and ending within lifetm of it; a leaked key signs nothing more
		if( $byOldKy && !( is_numeric($idntArr['iat'] ?? null) && $idntArr['iat']<$this->oldRetir && $idntArr['exp']<=$this->oldRetir + intval($this->jwtExpir) ) ){
			\nyu\Tools\Logger::getLogger('access')->debug( 'Token refused: signed with the previous key outside its time' );
			$this->lastMssg = 'Invalid token';
			$this->wrongCrd = true;
			return false;
		}
		// A token issued for another project, or by another issuer, is not counted : its signature is right
		$clmFlaw = $this->claimFlaw( $idntArr );
		if( $clmFlaw!='' ){
			\nyu\Tools\Logger::getLogger('access')->debug( 'Token refused: '.$clmFlaw );
			$this->lastMssg = 'Invalid token';
			return false;
		}
		// A credential store that knows its users confirms the token's user is still allowed in
		if( $this->authObjc!==null && method_exists($this->authObjc, 'userIdent') ){
			// The time the token was issued at : a password set since ends it
			$nowIdnt = $this->authObjc->userIdent( strval($idntArr['user'] ?? ''), intval($idntArr['iat'] ?? 0) );
			if( $nowIdnt===false ){
				$this->lastMssg = $this->authObjc->lastError();
				return false;
			}
			$idntArr = array_merge($idntArr, $nowIdnt);
		}
		return $idntArr;
	}

	/**
	 * Issues a signed JWT for the given identity, expiring after the
	 * configured TTL. With RS256, the token names its key in its 'kid'
	 * header: the thumbprint of the public key (RFC 7638). The issuer and
	 * the audiences of the block are written as 'iss' and 'aud': one
	 * audience as a text, several as a list.
	 *
	 * @param Array $identArray The identity to encode into the token.
	 * @return String The issued JWT, or an empty string when the secret or the private key is not usable.
	 */
	function issueTokn( Array $identArray ): String {
		$signKey = $this->canSignIt();
		if( $signKey===null ){
			return '';
		}
		$payload = $identArray;
		// Only the block names the issuer and the audiences, never the identity
		unset( $payload['iss'], $payload['aud'] );
		if( $this->jwtIssur!='' ){
			$payload['iss'] = $this->jwtIssur;
		}
		if( !empty($this->jwtAudnc) ){
			$payload['aud'] = (count($this->jwtAudnc)==1) ? $this->jwtAudnc[0] : $this->jwtAudnc;
		}
		$payload['iat'] = time();
		$payload['exp'] = time() + $this->jwtExpir;
		return JwtCodec::encode( $payload, $signKey, $this->jwtAlgor, ($this->jwtAlgor=='RS256') ? $this->keysThumb() : null );
	}

	/**
	 * Tells whether the block only checks tokens: RS256 with a public key
	 * and no private key, the tokens being issued by another server.
	 *
	 * @return Bool True when the block checks tokens and issues none.
	 */
	public function checkOnly(): Bool {
		return $this->jwtAlgor=='RS256' && $this->privtPem=='' && $this->rsaPublic()!==null;
	}

	/**
	 * Gives the key a token is checked with: the secret (HS256, 32 bytes at
	 * least), or the public key (RS256, of RSAMINIM bits at least), drawn
	 * from the private key when the block names none.
	 *
	 * @return ?String The key, or null when it is not usable; lastError() then tells why.
	 */
	protected function canVerify(): ?String {
		if( $this->jwtAlgor=='RS256' ){
			$chckKey = $this->rsaPublic();
			if( $chckKey===null ){
				$this->lastMssg = 'Token keys not configured';
			}
			return $chckKey;
		}
		if( $this->jwtAlgor!='HS256' || !is_string($this->jwtSecrt) || strlen($this->jwtSecrt)<32 ){
			$this->lastMssg = 'JWT secret not configured';
			return null;
		}
		return $this->jwtSecrt;
	}

	/**
	 * Gives the key a token is signed with: the secret (HS256), or the
	 * private key (RS256, of RSAMINIM bits at least).
	 *
	 * @return ?String The key, or null when it is not usable; lastError() then tells why.
	 */
	protected function canSignIt(): ?String {
		if( $this->jwtAlgor!='RS256' ){
			return $this->canVerify();
		}
		if( $this->checkOnly() ){
			$this->lastMssg = 'Tokens are issued by another server';
			return null;
		}
		$keyObjc = ($this->privtPem!='' && function_exists('openssl_pkey_get_private')) ? @openssl_pkey_get_private($this->privtPem) : false;
		if( $keyObjc===false || !$this->strongKey($keyObjc) ){
			$this->lastMssg = 'Token keys not configured';
			return null;
		}
		return $this->privtPem;
	}

	/**
	 * Gives the public key of RS256: the one the block names, or the one
	 * drawn from its private key.
	 *
	 * @return ?String The public key as PEM, or null when there is none of RSAMINIM bits at least.
	 */
	protected function rsaPublic(): ?String {
		if( !function_exists('openssl_pkey_get_public') ){
			return null;
		}
		if( $this->publcPem!='' ){
			$keyObjc = @openssl_pkey_get_public($this->publcPem);
		}else{
			$keyObjc = ($this->privtPem!='') ? @openssl_pkey_get_private($this->privtPem) : false;
		}
		if( $keyObjc===false || !$this->strongKey($keyObjc) ){
			return null;
		}
		$pubText = strval( openssl_pkey_get_details($keyObjc)['key'] ?? '' );
		return ($pubText!='') ? $pubText : null;
	}

	/**
	 * Tells whether a key is an RSA key of RSAMINIM bits at least.
	 *
	 * @param \OpenSSLAsymmetricKey $opensslKey The key.
	 * @return Bool Whether it can be used.
	 */
	protected function strongKey( \OpenSSLAsymmetricKey $opensslKey ): Bool {
		$keyInfo = openssl_pkey_get_details($opensslKey);
		return is_array($keyInfo) && ($keyInfo['type'] ?? null)===OPENSSL_KEYTYPE_RSA && intval($keyInfo['bits'] ?? 0)>=self::RSAMINIM;
	}

	/**
	 * Gives the thumbprint of a public key (RFC 7638), which a token
	 * names as its 'kid': the name a server publishes the key under.
	 *
	 * @param ?String $pubKeyText The public key as PEM; the current one when not given.
	 * @return ?String The thumbprint, base64url; null without a public key.
	 */
	protected function keysThumb( ?String $pubKeyText=null ): ?String {
		$pubText = $pubKeyText ?? $this->rsaPublic();
		$jwkVals = ($pubText!==null) ? $this->rsaToJwks($pubText) : null;
		return ($jwkVals!==null) ? $jwkVals['kid'] : null;
	}

	/**
	 * Gives a public RSA key as a JWK (RFC 7517), named by its thumbprint
	 * (RFC 7638) and meant for checking RS256 signatures.
	 *
	 * @param String $pubKeyText The public key as PEM.
	 * @return ?Array The JWK ('kty', 'use', 'alg', 'kid', 'n', 'e'); null when it is not an RSA key of RSAMINIM bits at least.
	 */
	protected function rsaToJwks( String $pubKeyText ): ?Array {
		$keyObjc = function_exists('openssl_pkey_get_public') ? @openssl_pkey_get_public($pubKeyText) : false;
		if( $keyObjc===false || !$this->strongKey($keyObjc) ){
			return null;
		}
		$keyInfo = openssl_pkey_get_details($keyObjc);
		if( !is_array($keyInfo) || !isset($keyInfo['rsa']['n'], $keyInfo['rsa']['e']) ){
			return null;
		}
		$modText = $this->base64Url($keyInfo['rsa']['n']);
		$expText = $this->base64Url($keyInfo['rsa']['e']);
		// The members the thumbprint is made of, in the order of their names, without spaces
		$jwkText = '{"e":"'.$expText.'","kty":"RSA","n":"'.$modText.'"}';
		return Array( 'kty'=>'RSA', 'use'=>'sig', 'alg'=>'RS256', 'kid'=>$this->base64Url(hash('sha256', $jwkText, true)), 'n'=>$modText, 'e'=>$expText );
	}

	/**
	 * Gives the public keys a token of RS256 is checked with, by their
	 * 'kid': the current one first, then the previous one ('oldpub'), while
	 * the tokens it signed before it was retired may still last.
	 *
	 * @return Array The keys as PEM, by thumbprint; empty with HS256 or without a usable key.
	 */
	protected function checkKeys(): Array {
		if( $this->jwtAlgor!='RS256' ){
			return Array();
		}
		$keysArr = Array();
		$currPem = $this->rsaPublic();
		foreach( Array($currPem, ($this->oldPbPem!='') ? $this->oldPbPem : null) as $pemTxt ){
			$keysKid = ($pemTxt!==null) ? $this->keysThumb($pemTxt) : null;
			if( $keysKid!==null && !isset($keysArr[$keysKid]) ){
				$keysArr[$keysKid] = $pemTxt;
			}
		}
		return $keysArr;
	}

	/**
	 * Gives the public keys of the block as a JWK Set (RFC 7517), for other
	 * projects to check its tokens without a copy of a key file: the keys
	 * of RS256 only, when the block issues tokens. A secret of HS256 is
	 * never published, nor the key of another server.
	 *
	 * @return Array The set, under 'keys'; the list is empty when there is nothing to publish.
	 */
	public function publicSet(): Array {
		$keysLst = Array();
		if( !$this->checkOnly() ){
			foreach( $this->checkKeys() as $pemTxt ){
				$keysLst[] = $this->rsaToJwks($pemTxt);
			}
		}
		return Array( 'keys'=>array_values(array_filter($keysLst)) );
	}

	/**
	 * Tells why the issuer or the audience of a token is not the one of
	 * the block: 'iss' must be its 'issuer', and 'aud' (a text or a list)
	 * name one of its audiences. Neither is checked while the block names
	 * none.
	 *
	 * @param Array $identArray The decoded claims of the token.
	 * @return String The reason, for the log; empty when the token is the block's.
	 */
	protected function claimFlaw( Array $identArray ): String {
		if( $this->jwtIssur!='' ){
			$tokIssr = $identArray['iss'] ?? null;
			if( !is_string($tokIssr) || $tokIssr=='' ){
				return 'no issuer';
			}
			if( $tokIssr!==$this->jwtIssur ){
				return 'issuer '.json_encode($tokIssr).' is not '.json_encode($this->jwtIssur);
			}
		}
		if( !empty($this->jwtAudnc) ){
			$tokAuds = $this->readsAuds( $identArray['aud'] ?? null );
			if( empty($tokAuds) ){
				return 'no audience';
			}
			if( empty(array_intersect($tokAuds, $this->jwtAudnc)) ){
				return 'audience '.json_encode((count($tokAuds)==1) ? $tokAuds[0] : $tokAuds).' is not one of '.json_encode($this->jwtAudnc);
			}
		}
		return '';
	}

	/**
	 * Reads audiences, as the config or a token gives them: a text, or a
	 * list of texts.
	 *
	 * @param Mixed $configVals The value given.
	 * @return Array The audiences, non-empty texts, each once.
	 */
	protected function readsAuds( Mixed $configVals ): Array {
		$audList = Array();
		foreach( (is_array($configVals) ? $configVals : Array($configVals)) as $audVal ){
			if( is_string($audVal) && trim($audVal)!='' && !in_array(trim($audVal), $audList, true) ){
				$audList[] = trim($audVal);
			}
		}
		return $audList;
	}

	/**
	 * Encodes bytes as base64url, without padding.
	 *
	 * @param String $binaryText The bytes.
	 * @return String The encoded text.
	 */
	protected function base64Url( String $binaryText ): String {
		return rtrim( strtr(base64_encode($binaryText), '+/', '-_'), '=' );
	}

	/**
	 * Reads a key file the block names, from the project root.
	 *
	 * @param Mixed $configPath The path the block gives.
	 * @return String The content of the file, empty when there is none.
	 */
	protected function readsKeys( Mixed $configPath ): String {
		$keyPath = is_scalar($configPath) ? trim(strval($configPath)) : '';
		if( $keyPath=='' ){
			return '';
		}
		if( !preg_match('#^([A-Za-z]:|[/\\\\])#', $keyPath) && defined('TOP_DIR') ){
			$keyPath = TOP_DIR.DIRECTORY_SEPARATOR.str_replace(Array('/', '\\'), DIRECTORY_SEPARATOR, $keyPath);
		}
		return is_file($keyPath) ? strval(@file_get_contents($keyPath)) : '';
	}

	/**
	 * Tells whether the last refusal was a token that was not issued here
	 * (wrong signature, not a token), as opposed to a missing one, one that
	 * ended, or a user no longer allowed in: only a forged token counts
	 * against the address that presented it (see Access\Limiter).
	 *
	 * @return Bool True when a token was presented and found forged.
	 */
	public function wrongCred(): Bool {
		return $this->wrongCrd;
	}

	/**
	 * Gives the message of the last error.
	 *
	 * @return String The last error message.
	 */
	function lastError(): String {
		return $this->lastMssg;
	}
}
