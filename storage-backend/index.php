<?php
/*
 * This script serves as storing backend for the xmpp extension
 * XEP-0313 Http Upload
 *
 * The following return codes are used for requesting a slot:
 * 200: Success - response body contains PUT URL, GET URL formatted in Json
 * 400: In case a mandatory parameter is not set.  (error code: 4, parameters: missing_parameter). Mandatory parameters are:
 *   xmpp_server_key
 *   filename
 *   size
 *   content_type
 *   user_jid
 * 403: In case the XMPP Server Key is not valid
 * 406:
 *   File is empty (error code: 1)
 *   File too large (error code: 2, parameters: max_file_size)
 *   Invalid character found in filename (error code: 3, parameters: invalid_character)
 * 500: Any other server error
 *   Upload directory for slot cannot be created
 *   Slot registry file cannot be created
 * 
 * The following return codes are used for uploading a file:
 * 201: Success - File Created
 * 403: If a slot is already used or file upload contains other data than in the request slot.
 *   The slot was used before (file already exists)
 *   The slot does not exist
 *   File size differs from slot request
 *   Mime Type differs from slot request
 */
 
$method = $_SERVER['REQUEST_METHOD'];

// Load configuration
$config = require(__DIR__.'/config/config.inc.php');
// Initialize directory config
$config['storage_base_path'] = __DIR__.'/files/';
$config['slot_registry_dir'] = __DIR__.'/slots/';
$config['base_url_put'] = getServerProtocol()."://".getRequestHostname().getRequestUriWithoutFilename().'files/';
$config['base_url_get'] = $config['base_url_put'];

switch ($method) {
  case 'POST':
    // parse post parameters
    // check if all parameters are present - return 400 (bad request) if a parameter is missing / empty
	$xmppServerKey = getMandatoryPostParameter('xmpp_server_key');
	$filename = rawurlencode(getMandatoryPostParameter('filename'));
	$filesize = getMandatoryPostParameter('size');
	$type = getOptionalPostParameter('content_type');
	$userJid = getMandatoryPostParameter('user_jid');
    // Check if xmppServerKey is allowed to request slots
    if (false === checkXmppServerKey($config['valid_xmpp_server_keys'], $xmppServerKey)) {
      sendHttpReturnCodeAndJson(403, 'Server is not allowed to request an upload slot');
    }
    // check file size - return 406 (not acceptable) if file too small
    if ($filesize <= 0) {
      sendHttpReturnCodeAndJson(406, ['msg' => 'File is empty.', 'err_code' => 1]);
	}
    // check file size - return 406 (not acceptable) if file too large
    if ($filesize > $config['max_upload_file_size']) {
      sendHttpReturnCodeAndJson(406, ['msg' => 'File too large.', 'err_code' => 2, 'parameters' => ['max_file_size' => $config['max_upload_file_size']]]);
	}
    // check file name - return 406 (not acceptable) if file contains invalid characters
    foreach ($config['invalid_characters_in_filename'] as $invalidCharacter) {
      if (stripos($filename, $invalidCharacter) !== false) {
        sendHttpReturnCodeAndJson(406, ['msg' => 'Invalid character found in filename.', 'err_code' => 3, 'parameters' => ['invalid_character' => $invalidCharacter]]);
      }
    }
    // generate slot uuid, register slot uuid and expected file size and expected mime type
    $basePath = $config['storage_base_path'];
    $slotUUID = generate_uuid();
    registerSlot($slotUUID, $filename, $filesize, $type, $userJid, $config);
    if (!mkdir(getUploadFilePath($slotUUID, $config))) {
      sendHttpReturnCodeAndJson(500, "Could not create directory for upload.");
    }
    // return 200 for success and get / put url Json formatted ( ['get'=>url, 'put'=>url] )
    $result = ['put' => $config['base_url_put'].$slotUUID.'/'.$filename,
                    'get' => $config['base_url_get'].$slotUUID.'/'.$filename];
    echo json_encode($result);
    break;
  case 'PUT':
    // check slot uuid - return 403 if not existing
    $uri = $_SERVER["REQUEST_URI"];
    $slotUUID = getUUIDFromUri($uri);
    $filename = getFilenameFromUri($uri);
    if (!slotExists($slotUUID, $config)) {
      sendHttpReturnCodeAndJson(403, "The slot does not exist.");
    }
    $slotParameters = loadSlotParameters($slotUUID, $config);
    if (!checkFilenameParameter($filename, $slotParameters)) {
      sendHttpReturnCodeAndJson(403, "Uploaded filename differs from requested slot filename.");
    }
    $uploadFilePath = getUploadFilePath($slotUUID, $config, $slotParameters['filename']);
    if (file_exists($uploadFilePath)) {
      sendHttpReturnCodeAndJson(403, "The slot was already used.");
    }
    // save file
    $incomingFileStream = fopen("php://input", "r");
    $targetFileStream = fopen($uploadFilePath, "w");
    $uploadedFilesize = stream_copy_to_stream($incomingFileStream, $targetFileStream, $slotParameters['filesize'] + 1); // max. 1 byte more than expected to avoid spamming
    fclose($targetFileStream);
    // check actual file size with registered file size - return 413
    if ($uploadedFilesize != $slotParameters['filesize']) {
      unlink($uploadFilePath);
      sendHttpReturnCodeAndJson(403, "Uploaded file size differs from requested slot size.");
    }
    // check actual mime type with registered mime type
    if (!is_null($slotParameters['content_type']) && !empty($slotParameters['content_type']) && mime_content_type($uploadFilePath) != $slotParameters['content_type']) {
      unlink($uploadFilePath);
      sendHttpReturnCodeAndJson(403, "Uploaded file content type differs from requested slot content type.");
    }
    // return 500 in case of any error
    // return 201 for success
    sendHttpReturnCodeAndMessage(201);
    break;
  default:
    sendHttpReturnCodeAndJson(403, "Access not allowed.");
    break;
}

function checkXmppServerKey($validXmppServerKeys, $xmppServerKey) {
  foreach ($validXmppServerKeys as $validXmppServerKey) {
    if ($validXmppServerKey == $xmppServerKey) {
      return true;
    }
  }
  return false;
}

function checkFilenameParameter($filename, $slotParameters) {
  $filename = $filename; // the filename is a http get parameter and therefore encoded
  return $slotParameters['filename'] == $filename;
}

function loadSlotParameters($slotUUID, $config) {
  $slotParameters = require(getSlotFilePath($slotUUID, $config));
  $slotParameters['filename'] = $slotParameters['filename'];
  
  return $slotParameters;
}

function getMandatoryPostParameter($parameterName) {
  $parameter = $_POST[$parameterName];
  if (!isset($parameter) || is_null($parameter) || empty($parameter)) {
    sendHttpReturnCodeAndJson(400, ['msg' => 'Missing parameter.', 'err_code' => 4, 'parameters' => ['missing_parameter' => $parameterName]]);
  }
  return $parameter;
}

function getOptionalPostParameter($parameterName) {
  $parameter = $_POST[$parameterName];
  if (!isset($parameter) || is_null($parameter) || empty($parameter)) {
    $parameter = NULL;
  }
  return $parameter;
}

function sendHttpReturnCodeAndJson($code, $data) {
  if (!is_array($data)) {
    $data = ['msg' => $data];
  }
  sendHttpReturnCodeAndMessage($code, json_encode($data));
}

function sendHttpReturnCodeAndMessage($code, $text = '') {
  http_response_code($code);
  exit($text);
}

function getUUIDFromUri($uri) {
  $pattern = "/[a-f0-9]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/";
  preg_match($pattern, $uri, $matches);
  return $matches[0];
}

function getFilenameFromUri($uri) {
  $lastSlash = strrpos($uri, '/') + 1;
  return substr($uri, $lastSlash);
}

function registerSlot($slotUUID, $filename, $filesize, $contentType, $userJid, $config) {
  $contents = "<?php\n/*\n * This is an autogenerated file - do not edit\n */\n\n";
  $contents .= 'return [\'filename\' => \''.$filename.'\', \'filesize\' => \''.$filesize.'\', ';
  $contents .= '\'content_type\' => \''.$contentType.'\', \'user_jid\' => \''.$userJid.'\'];\n?>';
  if (!file_put_contents(getSlotFilePath($slotUUID, $config), $contents)) {
    sendHttpReturnCodeAndMessage(500, "Could not create slot registry entry.");
  }
}

function slotExists($slotUUID, $config) {
  return file_exists(getSlotFilePath($slotUUID, $config));
}

function getSlotFilePath($slotUUID, $config) {
  return $config['slot_registry_dir'].$slotUUID;
}

function getUploadFilePath($slotUUID, $config, $filename = NULL) {
  $path = $config['storage_base_path'].$slotUUID;
  if (!is_null($filename)) {
    $path .= '/'.$filename;
  }
  return $path;
}

/**
 * Inspired by https://github.com/owncloud/core/blob/master/lib/private/appframework/http/request.php#L523
 */
function getServerProtocol() {
  if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
      if (strpos($_SERVER['HTTP_X_FORWARDED_PROTO'], ',') !== false) {
          $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO']);
          $proto = strtolower(trim($parts[0]));
      } else {
          $proto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']);
      }
      // Verify that the protocol is always HTTP or HTTPS
      // default to http if an invalid value is provided
      return $proto === 'https' ? 'https' : 'http';
  }
  if (isset($_SERVER['HTTPS'])
      && $_SERVER['HTTPS'] !== null
      && $_SERVER['HTTPS'] !== 'off'
      && $_SERVER['HTTPS'] !== '') {
      return 'https';
  }
  return 'http';
}

function getRequestHostname() {
  if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
    return strtolower($_SERVER['HTTP_X_FORWARDED_HOST']);
  }
  return strtolower($_SERVER['HTTP_HOST']);
}

function getRequestUriWithoutFilename() {
  return strtolower(substr($_SERVER['REQUEST_URI'], 0, strrpos($_SERVER['REQUEST_URI'], '/') + 1));
}

/**
 * Copied from http://rogerstringer.com/2013/11/15/generate-uuids-php/
 */ 
function generate_uuid() {
  return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
    mt_rand( 0, 0xffff ),
    mt_rand( 0, 0x0fff ) | 0x4000,
    mt_rand( 0, 0x3fff ) | 0x8000,
    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
  );
}
?>