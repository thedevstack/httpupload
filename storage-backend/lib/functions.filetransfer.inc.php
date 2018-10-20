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

function listFiles($jid, $limit = -1, $offset = 0, $descending = false) {
    // Read complete set of existing slots per jid (unsorted)
    $slots = readSlots($jid, $limit, $offset);

    if ($descending) {
        // Sort descending by timestamp
        usort($slots, function($a, $b) {
            return $b['sent_time'] - $a['sent_time'];
        });
    } else {
        // Sort ascending by timestamp
        usort($slots, function($a, $b) {
            return $a['sent_time'] - $b['sent_time'];
        });
    }

    // Select requested slot subset
    $offsetCounter = 0;
    $resultSet = array();
    foreach ($slots as $slot) {
        if (0 < $offset && $offsetCounter < $offset) {
            $offsetCounter++;
            continue;
        }
        $resultSet[] = $slot;
        
        if (0 < $limit && $limit == count($resultSet)) {
            break;
        }
    }
    return ['count' => count($slots),
            'hasMore' => $offset + count($resultSet) < count($slots),
            'files' => $resultSet];
}

function readSlots($jid, $limit = -1, $offset = 0)  {
    global $config;

    $jid = getBareJid($jid);
    $slots = array();

    if ($handle = opendir($config['slot_registry_dir'])) {
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != ".." && $entry != ".htaccess") {
                $slotUUID = $entry;
                $params = loadSlotParameters($slotUUID, $config);
                $senderBareJid = getBareJid($params['user_jid']);
                $recipientBareJid = (array_key_exists('recipient_jid', $params)) ? getBareJid($params['recipient_jid']) : '';
                if ($senderBareJid == $jid || $recipientBareJid == $jid) {
                    $filePath = getUploadFilePath($slotUUID, $config, $params['filename']);
                    $file = [];
                    $fileExists = file_exists(rawurldecode($filePath));
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
                    if (null == $file['recipient_jid']) {
                      $file['recipient_jid'] = "";
                    }
                    $slots[] = $file;
                }
            }
        }
    }
    
    return $slots;
}
