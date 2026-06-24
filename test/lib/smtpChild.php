<?php

// A fake SMTP server for test/unit/MailSmtpTest.php, run as a child process:
//
//   php test/lib/smtpChild.php <transcript file> <logins offered> <connections> [address]
//
// It listens on a free port of the address (127.0.0.1 by default), prints
// that port on its first line, then answers the given number of connections
// in turn and exits. Everything the client sends is appended to the
// transcript file. Logins offered: 'PLAIN LOGIN', 'LOGIN', or 'none'.
// The account "user" / "secret" is the only one accepted; a recipient whose
// address starts with "refused" is refused with a 550.

$trnFile = strval($argv[1] ?? '');
$authTxt = strval($argv[2] ?? 'PLAIN LOGIN');
$connNmb = max(1, intval($argv[3] ?? 1));
$lstAddr = strval($argv[4] ?? '127.0.0.1');

$srvSock = @stream_socket_server('tcp://'.$lstAddr.':0', $errCode, $errText);
if( $srvSock===false ){
	echo "0\n";
	exit(1);
}
$sockNme = strval(stream_socket_get_name($srvSock, false));
echo substr($sockNme, strrpos($sockNme, ':')+1)."\n";
flush();

/**
 * Writes what the client sent into the transcript.
 *
 * @param String $trnscrFile Path of the transcript file.
 * @param String $clientLine What the client sent.
 */
function noteLine( String $trnscrFile, String $clientLine ): Void {
	file_put_contents($trnscrFile, $clientLine, FILE_APPEND);
}

for( $conNum=0; $conNum<$connNmb; $conNum++ ){
	$cltLink = @stream_socket_accept($srvSock, 10);
	if( $cltLink===false ){
		exit(1);
	}
	stream_set_timeout($cltLink, 10);
	fwrite($cltLink, "220 fake.test ESMTP\r\n");
	$authStp = '';
	while( ($cmdLine = fgets($cltLink, 4096))!==false ){
		noteLine($trnFile, $cmdLine);
		$cmdWord = strtoupper(trim(strtok($cmdLine, " \r\n")));
		if( $authStp=='user' ){
			$authStp = (base64_decode(trim($cmdLine))=='user') ? 'pass' : 'bad';
			fwrite($cltLink, "334 UGFzc3dvcmQ6\r\n");
		}elseif( $authStp=='pass' || $authStp=='bad' ){
			fwrite($cltLink, ($authStp=='pass' && base64_decode(trim($cmdLine))=='secret') ? "235 ok\r\n" : "535 no\r\n");
			$authStp = '';
		}elseif( $cmdWord=='EHLO' ){
			fwrite($cltLink, "250-fake.test\r\n".($authTxt!='none' ? '250-AUTH '.$authTxt."\r\n" : '')."250 8BITMIME\r\n");
		}elseif( $cmdWord=='AUTH' && stripos($cmdLine, 'AUTH PLAIN ')===0 ){
			fwrite($cltLink, (base64_decode(trim(substr($cmdLine, 11)))=="\0user\0secret") ? "235 ok\r\n" : "535 no\r\n");
		}elseif( $cmdWord=='AUTH' ){
			$authStp = 'user';
			fwrite($cltLink, "334 VXNlcm5hbWU6\r\n");
		}elseif( $cmdWord=='MAIL' ){
			fwrite($cltLink, "250 ok\r\n");
		}elseif( $cmdWord=='RCPT' ){
			fwrite($cltLink, (stripos($cmdLine, 'RCPT TO:<refused')===0) ? "550 no such user\r\n" : "250 ok\r\n");
		}elseif( $cmdWord=='DATA' ){
			fwrite($cltLink, "354 go\r\n");
			while( ($datLine = fgets($cltLink, 4096))!==false ){
				noteLine($trnFile, $datLine);
				if( $datLine==".\r\n" ){
					break;
				}
			}
			fwrite($cltLink, "250 queued\r\n");
		}elseif( $cmdWord=='QUIT' ){
			fwrite($cltLink, "221 bye\r\n");
			break;
		}else{
			fwrite($cltLink, "502 unknown\r\n");
		}
	}
	fclose($cltLink);
}
fclose($srvSock);
