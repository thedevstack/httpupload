<?php
/*
 * This file contains functions commonly used.
 */

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

function format_size($size, $precision = 2) {
  $sizes = ['bytes', 'Kb', 'Mb', 'Gb', 'Tb'];
  $i = 0;
  while (1023 < $size && $i < count($sizes) - 1) {
    $size /= 1023;
    ++$i;
  }

  return number_format($size, $precision).' '.$sizes[$i];
}
?>