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

function getFromArray($key, $array) {
  if (array_key_exists($key, $array)
        && isset($array[$key])
        && !empty($array[$key])) {
        return $array[$key];
    } else {
        return NULL;
    }
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

function startsWith($haystack, $needle) {
     $length = strlen($needle);
     return (substr($haystack, 0, $length) === $needle);
}

function endsWith($haystack, $needle) {
    $length = strlen($needle);

    return $length === 0 || (substr($haystack, -$length) === $needle);
}

function generatePath($parts, $basePath = __DIR__) {
    $path = $basePath;
    if (!is_array($parts)) {
        $parts = [$parts];
    }
    foreach ($parts as $part) {
        $path .= DIRECTORY_SEPARATOR.generatePathName($part);
    }
    return $path;
}

function generatePathName($name) {
    return urlencode($name);
}