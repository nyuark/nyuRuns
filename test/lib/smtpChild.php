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
$connNum = max(1, intval($argv[3] ?? 1));
$lstAddr = strval($argv[4] ?? '127.0.0.1');

$tcpSock = @stream_socket_server('tcp://'.$lstAddr.':0', $errCode, $errText);
if( $tcpSock===false ){
	echo "0\n";
	exit(1);
}
$scktNme = strval(stream_socket_get_name($tcpSock, false));
echo substr($scktNme, strrpos($scktNme, ':')+1)."\n";
flush();

/**
 * Writes what the client sent into the transcript.
 *
 * @param String $dialogueFile Path of the transcript file.
 * @param String $clntLineText What the client sent.
 */
function noteClientLine( String $dialogueFile, String $clntLineText ): Void {
	file_put_contents($dialogueFile, $clntLineText, FILE_APPEND);
}

for( $conNum=0; $conNum<$connNum; $conNum++ ){
	$clntLnk = @stream_socket_accept($tcpSock, 10);
	if( $clntLnk===false ){
		exit(1);
	}
	stream_set_timeout($clntLnk, 10);
	fwrite($clntLnk, "220 fake.test ESMTP\r\n");
	$athStep = '';
	while( ($command = fgets($clntLnk, 4096))!==false ){
		noteClientLine($trnFile, $command);
		$smtpCmd = strtoupper(trim(strtok($command, " \r\n")));
		if( $athStep=='user' ){
			$athStep = (base64_decode(trim($command))=='user') ? 'pass' : 'bad';
			fwrite($clntLnk, "334 UGFzc3dvcmQ6\r\n");
		}elseif( $athStep=='pass' || $athStep=='bad' ){
			fwrite($clntLnk, ($athStep=='pass' && base64_decode(trim($command))=='secret') ? "235 ok\r\n" : "535 no\r\n");
			$athStep = '';
		}elseif( $smtpCmd=='EHLO' ){
			fwrite($clntLnk, "250-fake.test\r\n".($authTxt!='none' ? '250-AUTH '.$authTxt."\r\n" : '')."250 8BITMIME\r\n");
		}elseif( $smtpCmd=='AUTH' && stripos($command, 'AUTH PLAIN ')===0 ){
			fwrite($clntLnk, (base64_decode(trim(substr($command, 11)))=="\0user\0secret") ? "235 ok\r\n" : "535 no\r\n");
		}elseif( $smtpCmd=='AUTH' ){
			$athStep = 'user';
			fwrite($clntLnk, "334 VXNlcm5hbWU6\r\n");
		}elseif( $smtpCmd=='MAIL' ){
			fwrite($clntLnk, "250 ok\r\n");
		}elseif( $smtpCmd=='RCPT' ){
			fwrite($clntLnk, (stripos($command, 'RCPT TO:<refused')===0) ? "550 no such user\r\n" : "250 ok\r\n");
		}elseif( $smtpCmd=='DATA' ){
			fwrite($clntLnk, "354 go\r\n");
			while( ($dataLin = fgets($clntLnk, 4096))!==false ){
				noteClientLine($trnFile, $dataLin);
				if( $dataLin==".\r\n" ){
					break;
				}
			}
			fwrite($clntLnk, "250 queued\r\n");
		}elseif( $smtpCmd=='QUIT' ){
			fwrite($clntLnk, "221 bye\r\n");
			break;
		}else{
			fwrite($clntLnk, "502 unknown\r\n");
		}
	}
	fclose($clntLnk);
}
fclose($tcpSock);
