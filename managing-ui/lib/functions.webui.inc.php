<?php


function readSlots($jid)  {
    global $config;
    
    $slots = array();
    $slots['sent'] = array();
    $slots['received'] = array();
    if ($handle = opendir($config['slot_registry_dir'])) {
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != ".." && $entry != ".htaccess") {
                $slotUUID = $entry;
                $params = loadSlotParameters($slotUUID, $config);
                if (getBareJid($params['user_jid']) == $jid) {
                    $filePath = getUploadFilePath($slotUUID, $config, $params['filename']);
                    $params['file_exists'] = file_exists($filePath);
                    $params['creation_time'] = -1;
                    if ($params['file_exists']) {
                        $params['creation_time'] = filemtime($filePath);
                    }
                    $params['uuid'] = $slotUUID;
                    $slots['sent'][] = $params;
                } else if (array_key_exists('receipient_jid', $params) && getBareJid($params['receipient_jid']) == $jid) {
                    $slots['received'][] = $params;
                } else if (!array_key_exists('receipient_jid', $params)) { // In httpupload storage-backend version < 0.2 the receipient_jid was not stored
                    $params['receipient_jid'] = "Unknown";
                }
            }
        }
    }
    return $slots;
}
?>