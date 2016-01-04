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
];
