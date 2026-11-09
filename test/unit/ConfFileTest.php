<?php

// Shell\ConfFile : the copies kept of a file written (KEEPCOPY of each, the
// newest), readable by their owner only, and the rights a secret is
// written with from its first byte.

require __DIR__.'/../lib/harness.php';

use nyu\Shell\ConfFile;

$workDir = inTemporaryDir('conffile');
$backDir = $workDir.DS.'backup';
@mkdir($workDir, 0775, true);
$wrtrObj = new ConfFile( $backDir );

/**
 * Lists the copies of a file in the backup folder, oldest first.
 *
 * @param String $backupFldr The backup folder.
 * @param String $fileNamed  The name of the file.
 * @return Array The contents of its copies.
 */
function copiesOf( String $backupFldr, String $fileNamed ): Array {
	$cpyList = Array();
	foreach( (array)scandir($backupFldr) as $oneNme ){
		if( preg_match('/^'.preg_quote($fileNamed, '/').'\.(\d{8}-\d{6})(?:-(\d+))?$/D', strval($oneNme), $namePrt) ){
			$cpyList[$namePrt[1].'-'.str_pad(strval($namePrt[2] ?? '1'), 6, '0', STR_PAD_LEFT)] = strval(file_get_contents($backupFldr.DS.$oneNme));
		}
	}
	ksort($cpyList);
	return array_values($cpyList);
}

// The copies : KEEPCOPY of each file, the newest ones
$envPath = $workDir.DS.'.env';
$wrtrObj->saveFileAtomic( $workDir.DS.'access.json.dist', 'dist-0' );
$wrtrObj->saveFileAtomic( $workDir.DS.'access.json.dist', 'dist-1' );
for( $idx=0; $idx<8; $idx++ ){
	$wrtrObj->saveFileAtomic( $envPath, 'SECRET=v'.$idx."\n" );
}
checkIdentical('the copies of a file written 8 times: the 5 newest of the 7 it replaced', copiesOf($backDir, 'env'), Array("SECRET=v2\n", "SECRET=v3\n", "SECRET=v4\n", "SECRET=v5\n", "SECRET=v6\n"));
checkIdentical('the file holds the last content', file_get_contents($envPath), "SECRET=v7\n");
checkIdentical('another file sharing the first letters keeps its own copies', copiesOf($backDir, 'access.json.dist'), Array('dist-0'));
checkIdentical('KEEPCOPY is 5', ConfFile::KEEP_COPY, 5);

// The rights : a secret and its copies, for their owner only
if( DS=='/' ){
	$wrtrObj->setEnvrnmntKey( $workDir.DS.'new.env', 'NYU_KEY', 'value' );
	$wrtrObj->setEnvrnmntKey( $workDir.DS.'new.env', 'NYU_KEY', 'other' );
	$wrtrObj->saveFileAtomic( $workDir.DS.'users.lst', '{ "users": [] }', 0640 );
	$cpyRgts = array_unique( array_map(function( String $copyNamed ) use ( $backDir ): Int { return fileperms($backDir.DS.$copyNamed) & 0777; }, array_values(array_diff((array)scandir($backDir), Array('.', '..')))) );
	checkIdentical('a new secret is readable by its owner only, a new users file by its group too', Array(fileperms($workDir.DS.'new.env') & 0777, fileperms($workDir.DS.'users.lst') & 0777), Array(0600, 0640));
	checkIdentical('every copy is readable by its owner only', array_values($cpyRgts), Array(0600));
	chmod($envPath, 0644);
	$wrtrObj->saveFileAtomic( $envPath, 'SECRET=v8' );
	checkIdentical('a file that existed keeps its own rights', fileperms($envPath) & 0777, 0644);
	checkIdentical('no temporary file is left', glob($workDir.DS.'*.tmp*') ?: Array(), Array());
}else{
	recordSkipping('rights of the secrets and of their copies', 'the rights are not read on Windows');
}

dropTestFolder($workDir);
finishChecking();
