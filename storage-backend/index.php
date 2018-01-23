<?php
/*
 * This script serves as storing backend for the xmpp extension
 * XEP-0363 Http Upload and for the extension to delete a file previously uploaded via HTTP upload
 *
 * The following return codes are used for requesting an upload slot (parameter 'slot_type' = 'upload' or empty):
 * 200: Success - response body contains PUT URL, GET URL formatted in Json
 * 400: In case a mandatory parameter is not set.  (error code: 4, parameters: missing_parameter). Mandatory parameters are:
 *   xmpp_server_key
 *   filename
 *   size
 *   content_type
 *   user_jid
 *   recipient_jid
 * 403: In case the XMPP Server Key is not valid
 * 406:
 *   File is empty (error code: 1)
 *   File too large (error code: 2, parameters: max_file_size)
 *   Invalid character found in filename (error code: 3, parameters: invalid_character)
 * 500: Any other server error
 *   Upload directory for slot cannot be created
 *   Slot registry file cannot be created
 * 
 * The following return codes are used for requesting an delete slot (parameter 'slot_type' = 'delete'):
 * 200: Success - response body contains delete_token formatted in Json
 * 400: In case a mandatory parameter is not set.  (error code: 4, parameters: missing_parameter). Mandatory parameters are:
 *   xmpp_server_key
 *   file_url
 *   user_jid
 * 403: In case the XMPP Server Key is not valid
 * 500: Any other server error
 *   Slot registry file cannot be updated with delete token and token validity time
 
 * The following return codes are used for uploading a file:
 * 201: Success - File Created
 * 403: If a slot is already used or file upload contains other data than in the request slot.
 *   The slot was used before (file already exists)
 *   The slot does not exist
 *   File size differs from slot request
 *   Mime Type differs from slot request
 * 
 * The following return codes are used for deleting a file:
 * 204: Success - No Content
 * 403:
 *   In case the XMPP Server Key is not valid
 *   The user is not allowed to delete a file (e.g. files can only be deleted by the creator and deletion is requested by someone else)
 *   There is no slot file for the file
 *   The filename stored in the slot file differs from the filename of the request
 * 404: If the file does not exist
 * 500: If an error occured while deleting
 */
include_once(__DIR__.'/lib/functions.common.inc.php');
include_once(__DIR__.'/lib/functions.http.inc.php');
include_once(__DIR__.'/lib/functions.filetransfer.inc.php');
include_once(__DIR__.'/lib/xmpp.util.inc.php');
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
	$userJid = getMandatoryPostParameter('user_jid');
	$slotType = getOptionalPostParameter('slot_type', 'upload');
    
    // Check if xmppServerKey is allowed to request slots
    if (false === checkXmppServerKey($config['valid_xmpp_server_keys'], $xmppServerKey)) {
      sendHttpReturnCodeAndJson(403, 'Server is not allowed to request an '.$slotType.' slot');
    }
    
    switch ($slotType) {
      case 'list':
        $slots = readSlots($userJid);
        $result = ['list' => $slots];
        break;
      case 'upload':
      default:
        // Check if all parameters needed for an upload are present - return 400 (bad request) if a parameter is missing / empty
        $filename = rawurlencode(getMandatoryPostParameter('filename'));
        $filesize = getMandatoryPostParameter('size');
        $mimeType = getOptionalPostParameter('content_type');
        $recipientJid = getMandatoryPostParameter('recipient_jid');
        
        // check file name - return 406 (not acceptable) if file contains invalid characters
        foreach ($config['invalid_characters_in_filename'] as $invalidCharacter) {
          if (stripos($filename, $invalidCharacter) !== false) {
            sendHttpReturnCodeAndJson(406, ['msg' => 'Invalid character found in filename.', 'err_code' => 3, 'parameters' => ['invalid_character' => $invalidCharacter]]);
          }
        }
        // check file size - return 406 (not acceptable) if file too small
        if ($filesize <= 0) {
          sendHttpReturnCodeAndJson(406, ['msg' => 'File is empty.', 'err_code' => 1]);
        }
        // check file size - return 406 (not acceptable) if file too large
        if ($filesize > $config['max_upload_file_size']) {
          sendHttpReturnCodeAndJson(406, ['msg' => 'File too large.', 'err_code' => 2, 'parameters' => ['max_file_size' => $config['max_upload_file_size']]]);
        }
        // generate slot uuid, register slot uuid and expected file size and expected mime type
        $slotUUID = generate_uuid();
        registerSlot($slotUUID, $filename, $filesize, $mimeType, $userJid, $recipientJid, $config);
        if (!mkdir(getUploadFilePath($slotUUID, $config))) {
          sendHttpReturnCodeAndJson(500, "Could not create directory for upload.");
        }
        // return 200 for success and get / put url Json formatted ( ['get'=>url, 'put'=>url] )
        $result = ['put' => $config['base_url_put'].$slotUUID.'/'.$filename,
                   'get' => $config['base_url_get'].$slotUUID.'/'.$filename];
    }
    
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
    $uploadFilePath = rawurldecode(getUploadFilePath($slotUUID, $config, $slotParameters['filename']));
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
  case 'DELETE':
    // check slot uuid - return 403 if not existing
    $uri = $_SERVER["REQUEST_URI"];
    $slotUUID = getUUIDFromUri($uri);
    $filename = getFilenameFromUri($uri);
    $xmppServerKey = $_SERVER["HTTP_X_XMPP_SERVER_KEY"];
    $userJid = $_SERVER["HTTP_X_USER_JID"];

    // Check if xmppServerKey is allowed to request slots
    if (false === checkXmppServerKey($config['valid_xmpp_server_keys'], $xmppServerKey)) {
      sendHttpReturnCodeAndJson(403, 'Server is not allowed to delete a file');
    }

    $slotParameters = loadSlotParameters($slotUUID, $config);
    if ($config['delete_only_by_creator']) {
      if (getBareJid($slotParameters['user_jid']) != getBareJid($userJid)) {
        sendHttpReturnCodeAndJson(403, "Deletion of that file is only allowed by the user created it.");
      }
    }

    if (!slotExists($slotUUID, $config)) {
      sendHttpReturnCodeAndJson(403, "The slot does not exist.");
    }
    
    if (!checkFilenameParameter($filename, $slotParameters)) {
      sendHttpReturnCodeAndJson(403, "Filename to delete differs from requested slot filename.");
    }
    $uploadFilePath = rawurldecode(getUploadFilePath($slotUUID, $config, $slotParameters['filename']));
    if (!file_exists($uploadFilePath)) {
      sendHttpReturnCodeAndJson(404, "The file does not exist.");
    }

    // Delete file
    if (unlink($uploadFilePath)) {
      // Clean up the server - ignore errors
      @rmdir(getUploadFilePath($slotUUID, $config));
      // return 204 for success
      sendHttpReturnCodeAndMessage(204);
    } else {
      sendHttpReturnCodeAndJson(500, "Could not delete file.");
    }
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

function getMandatoryPostParameter($parameterName) {
  $parameter = $_POST[$parameterName];
  if (!isset($parameter) || is_null($parameter) || empty($parameter)) {
    sendHttpReturnCodeAndJson(400, ['msg' => 'Missing parameter.', 'err_code' => 4, 'parameters' => ['missing_parameter' => $parameterName]]);
  }
  return $parameter;
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

function registerSlot($slotUUID, $filename, $filesize, $contentType, $userJid, $recipientJid, $config) {
  $contents = "<?php\n/*\n * This is an autogenerated file - do not edit\n */\n\n";
  $contents .= 'return [\'filename\' => \''.$filename.'\', \'filesize\' => \''.$filesize.'\', ';
  $contents .= '\'content_type\' => \''.$contentType.'\', \'user_jid\' => \''.$userJid.'\', \'recipient_jid\' => \''.$recipientJid.'\'];';
  $contents .= "\n?>";
  if (!file_put_contents(getSlotFilePath($slotUUID, $config), $contents)) {
    sendHttpReturnCodeAndMessage(500, "Could not create slot registry entry.");
  }
}

function slotExists($slotUUID, $config) {
  return file_exists(getSlotFilePath($slotUUID, $config));
}
?>
