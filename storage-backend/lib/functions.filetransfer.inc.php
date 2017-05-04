<?php
/*
 * This file contains the functions for the storage-backend.
 */

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

function loadSlotParameters($slotUUID, $config) {
  $slotFilePath = getSlotFilePath($slotUUID, $config);
  $slotParameters = require($slotFilePath);
  $slotParameters['filename'] = $slotParameters['filename'];
  $slotParameters['creation_time'] = filemtime($slotFilePath);
  
  return $slotParameters;
}

function readSlots($jid)  {
    global $config;

    $jid = getBareJid($jid);
    $slots = array();

    if ($handle = opendir($config['slot_registry_dir'])) {
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != ".." && $entry != ".htaccess") {
                $slotUUID = $entry;
                $params = loadSlotParameters($slotUUID, $config);
                $senderBareJid = getBareJid($params['user_jid']);
                $recipientBareJid = (array_key_exists('receipient_jid', $params)) ? getBareJid($params['receipient_jid']) : '';
                if ($senderBareJid == $jid || $recipientBareJid == $jid) {
                    $filePath = getUploadFilePath($slotUUID, $config, $params['filename']);
                    $file = [];
                    $fileExists = file_exists($filePath);
                    $file['url'] = "";
                    $file['sent_time'] = $params['creation_time'];
                    if ($fileExists) {
                        $file['url'] = $config['base_url_get'].$slotUUID.'/'.$params['filename'];
                    }
                    $file['fileinfo'] = [];
                    $file['fileinfo']['filename'] = $params['filename'];
                    $file['fileinfo']['filesize'] = $params['filesize'];
                    $file['fileinfo']['content_type'] = $params['content_type'];
                    $file['sender_jid'] = $senderBareJid;
                    $file['recipient_jid'] = $recipientBareJid;
                    if (null == $file['receipient_jid']) {
                      $file['receipient_jid'] = "";
                    }
                    $slots[] = $file;
                }
            }
        }
    }
    return $slots;
}
