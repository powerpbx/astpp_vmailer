<?php

/**
mailer_app.php
  Copyright (C) 2008 Mark J Crane
  All rights reserved.
 
  Redistribution and use in source and binary forms, with or without
  modification, are permitted provided that the following conditions are met:
 
  1. Redistributions of source code must retain the above copyright notice,
     this list of conditions and the following disclaimer.
 
  2. Redistributions in binary form must reproduce the above copyright
     notice, this list of conditions and the following disclaimer in the
     documentation and/or other materials provided with the distribution.
 
  THIS SOFTWARE IS PROVIDED ``AS IS AND ANY EXPRESS OR IMPLIED WARRANTIES,
  INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
  AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
  AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
  OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
  SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
  INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
  CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
  ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
  POSSIBILITY OF SUCH DAMAGE.
*/

/* Additions and modifications by:
*  devops@powerpbx.org 
*
*/

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer.php';
require 'Exception.php';
require 'SMTP.php';

// Get SMTP credentials from ASTPP
include_once 'astpp.db.php';
$db = new db ();
$query = "SELECT * FROM `system` WHERE sub_group = 'Email'";
$res_conf = $db->run ( $query );
foreach ( $res_conf as $k => $v ) {
    $config [$v ['name']] = $v ['value'];
}

$smtp = $config['smtp'] ?? null;

// If SMTP is disabled then exit
if ($smtp != 0) {
    return;
}

// For example, "ssl://smtp.googlemail.com"
$fullHost   = $config['smtp_host'] ?? null;

// Without a hostname we can't send email
if (!isset($fullHost)) {
    return;
}

$parsedHost = parse_url($fullHost);

$scheme = $parsedHost['scheme'] ?? '';  //options "", "tls", "ssl"
$host = $parsedHost['host'] ?? '';
$username = $config['smtp_user'] ?? null;
$password = $config['smtp_pass'] ?? null;
$port = $config['smtp_port'] ?? null;
$from = $username;
$fromName = 'Voicemail';

ini_set('max_execution_time', 600); //10 minutes
ini_set('memory_limit', '96M');

//get email from stdin
$fd = fopen("php://stdin", "r");
$email = file_get_contents("php://stdin");
fclose($fd);

// output log
$fp = fopen(sys_get_temp_dir() . '/vmailer.log', 'w');

// prepare output buffers
ob_end_clean();
ob_start();

//parse out header and body
$tmparray = explode("\n\n", $email);
$mainheader = $tmparray[0];
$maincontent = substr($email, strlen($mainheader), strlen($email));

//parse out boundary   
$tmparray = explode("\n", $mainheader);
$contenttmp = $tmparray[1]; //Content-Type: multipart/mixed; boundary="XXXX_boundary_XXXX"
$tmparray = explode('; ', $contenttmp); //boundary="XXXX_boundary_XXXX"
$contenttmp = $tmparray[1];
$tmparray = explode('=', $contenttmp); //"XXXX_boundary_XXXX"
$boundary = $tmparray[1];
$boundary = trim($boundary,'"');

//put the main headers into an array
$mainheaderarray = explode("\n", $mainheader);
foreach ($mainheaderarray as $val) {            
    $tmparray = explode(': ', $val);
    $var[$tmparray[0]] = trim($tmparray[1]);
}

$var['To'] = str_replace("<", "", $var['To']);
$var['To'] = str_replace(">", "", $var['To']);

echo "To: ".$var['To']."\n";
echo "From: ".$var['From']."\n";
echo "Subject: ".$var['Subject']."\n";
echo "\n\n";

$tmpArray = splitMime($boundary, $maincontent);

// loop through each mime part
$i=0;
foreach ($tmpArray as $mimepart) {

  $subHeader = extractMimeHeader($mimepart);
  $mimeHeaderArray = explode("\n", trim($subHeader));

  $x=0;
  foreach ($mimeHeaderArray as $val) {
      if(stristr($val, ':') === FALSE) {
          $tmparray = explode('=', $val); //':' not found
          if (trim($tmparray[0]) == "boundary") {
              $subboundary = $tmparray[1];
              $subboundary = trim($subboundary,'"');
          }
      }
      else {            
          $tmparray = explode(':', $val); //':' found
      }

      $var[trim($tmparray[0])] = trim($tmparray[1]);
  }

  $contenttypearray = explode(' ', $mimeHeaderArray[0]);

  if ($contenttypearray[0] == "Content-Type:") {
      $contenttype = trim($contenttypearray[1]);

      switch ($contenttype) {
      case "multipart/alternative;":

          $content = trim(substr($mimepart, strlen($subHeader), strlen($mimepart)));

          $tmpSubArray = splitMime($subboundary, $content);

          foreach ($tmpSubArray as $mimesubsubpart) {

            $subSubHeader = extractMimeHeader($mimesubsubpart);
            $subSubMimeHeaderArray = explode("\n", trim($subSubHeader));
   
            $subsubcontenttypearray = explode(' ', $subSubMimeHeaderArray[0]);

            if ($subsubcontenttypearray[0] == "Content-Type:") {
                $subsubcontenttype = trim($subsubcontenttypearray[1]);                    
                switch ($subsubcontenttype) {

                case "text/plain;":
                  $textplain = trim(substr($mimesubsubpart, strlen($subSubHeader), strlen($mimesubsubpart)));
                  break;
 
                case "text/html;":
                  $texthtml = trim(substr($mimesubsubpart, strlen($subSubHeader), strlen($mimesubsubpart)));
                  break;
                }
            }
          }

          break;

      case "audio/x-wave;":
          $strwav = trim(substr($mimepart, strlen($subHeader), strlen($mimepart)));
          break;
      }
  }

  $i++;
}

//send the email

$mail = new PHPMailer();
$mail->isSMTP(); // set mailer to use SMTP
$mail->SMTPDebug  = 2;
$mail->SMTPAuth = true;
$mail->Host   = $host;
$mail->SMTPSecure = $scheme;
$mail->Username = $username;
$mail->Password = $password;
$mail->Port = $port;
$mail->Subject    = $var['Subject'];
$mail->AltBody    = $textplain;
$mail->msgHTML($texthtml);

$tmp_to = $var['To'];
$tmp_to = str_replace(";", ",", $tmp_to);
$tmp_to_array = explode(",", $tmp_to);

foreach($tmp_to_array as $tmp_to_row) {
     if (strlen($tmp_to_row) > 0) {
             $mail->addAddress($tmp_to_row);
     }
}

/*
$tmp_from = $var['From'];
$tmp_from = str_replace(['"', '<', '>'], '', $tmp_from);
$tmp_from_array = explode(' ', $tmp_from);
$fromName = $tmp_from_array[0] ?? $fromName;
$from = $tmp_from_array[1] ?? $from;
*/

$mail->setFrom($from, $fromName);

if (strlen($strwav) > 0) {
     $filename='voicemail.wav'; $encoding = "base64"; $type = "audio/x-wav";
     $mail->addStringAttachment(base64_decode($strwav), $filename, $encoding, $type);
}

unset($strwav);

if(!$mail->send()) {
    echo "Mailer Error: " . $mail->ErrorInfo;
} else {
    echo "Message sent!";
}
      
$content = ob_get_contents();
ob_end_clean();

fwrite($fp, $content);
fclose($fp);

// End of line by line php code 

// split mime type multi-part into each part
function splitMime($boundary, $content) 
{
    $content = str_replace($boundary."--", $boundary, $content);
    $array = explode("--".$boundary, $content);
    return $array;
}

function extractMimeHeader($mimepart)
{
    $array = explode("\n\n", $mimepart);
    $header = $array[0];
    return $header;
}
