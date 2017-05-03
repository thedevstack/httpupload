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
  $slotParameters = require(getSlotFilePath($slotUUID, $config));
  $slotParameters['filename'] = $slotParameters['filename'];
  
  return $slotParameters;
}