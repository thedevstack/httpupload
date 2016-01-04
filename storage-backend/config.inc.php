<?php
/*
 * Configuration file for http upload storage backend
 */

return array(
  // Array of keys of XMPP Server allowed to request slots
  'valid_xmpp_server_keys' => array('abc'),
  // Max Upload size in bytes
  'max_upload_file_size' => 10 * 1024 * 1024,
  // Array of characters which are not allowed in filenames
  'invalid_characters_in_filename' => array('/'),
  // The path to the file storage - IMPORTANT: Add a trailing '/'
  'storage_base_path' => '[[PATH_TO_STORAGE]]',
  // The path to the directory where the slots are stored - IMPORTANT: Add a trailing '/'
  'slot_registry_dir' => '[[PATH_TO_SLOT_STORAGE]]',
  // The base URL to put the files - IMPORTANT: Add a trailing '/'
  'base_url_put' => '[[BASE_URL_FOR_PUT]]',
  // The base URL to get the files - IMPORTANT: Add a trailing '/'
  'base_url_get' => '[[BASE_URL_FOR_GET]]',
);
