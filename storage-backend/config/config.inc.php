<?php
/*
 * Configuration file for http upload storage backend
 */

return [
  // Array of keys of XMPP Server allowed to request slots
  'valid_xmpp_server_keys' => ['abc'],
  // Max Upload size in bytes
  'max_upload_file_size' => 10 * 1024 * 1024,
  // Array of characters which are not allowed in filenames
  'invalid_characters_in_filename' => ['/'],
  // Validity time of a delete token in seconds
  'delete_token_validity' => 5 * 60,
  // Flag to whether deletion is only allowed by creator or anybody
  'delete_only_by_creator' => true,
];
?>