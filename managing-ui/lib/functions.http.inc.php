<?php
/*
 *
 * This file contains functions to be used to
 * extract information based on http request information.
 *
 */
 
/**
 * Inspired by https://github.com/owncloud/core/blob/master/lib/private/appframework/http/request.php#L523
 */
function getServerProtocol() {
  if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
      if (strpos($_SERVER['HTTP_X_FORWARDED_PROTO'], ',') !== false) {
          $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO']);
          $proto = strtolower(trim($parts[0]));
      } else {
          $proto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']);
      }
      // Verify that the protocol is always HTTP or HTTPS
      // default to http if an invalid value is provided
      return $proto === 'https' ? 'https' : 'http';
  }
  if (isset($_SERVER['HTTPS'])
      && $_SERVER['HTTPS'] !== null
      && $_SERVER['HTTPS'] !== 'off'
      && $_SERVER['HTTPS'] !== '') {
      return 'https';
  }
  return 'http';
}

function getRequestHostname() {
  if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
    return strtolower($_SERVER['HTTP_X_FORWARDED_HOST']);
  }
  return strtolower($_SERVER['HTTP_HOST']);
}

function getRequestUriWithoutFilename() {
  return strtolower(substr($_SERVER['REQUEST_URI'], 0, strrpos($_SERVER['REQUEST_URI'], '/') + 1));
}

function sendHttpReturnCodeAndJson($code, $data) {
  if (!is_array($data)) {
    $data = ['msg' => $data];
  }
  header('Content-Type: application/json');
  sendHttpReturnCodeAndMessage($code, json_encode($data));
}

function sendHttpReturnCodeAndMessage($code, $text = '') {
  http_response_code($code);
  exit($text);
}

function getOptionalPostParameter($parameterName, $default = NULL) {
  $parameter = $_POST[$parameterName];
  if (!isset($parameter) || is_null($parameter) || empty($parameter)) {
    $parameter = $default;
  }
  return $parameter;
}

function getMandatoryPostParameter($parameterName, $message = '', $json = false) {
  $parameter = $_POST[$parameterName];
  if (!isset($parameter) || is_null($parameter) || empty($parameter)) {
    if (empty($message)) {
      if ($json) {
        $message = ['msg' => 'Missing parameter.', 'parameters' => ['missing_parameter' => $parameterName]];
      } else {
        $message = 'Missing mandatory parameter "'.$parameterName.'".';
      }
    }
    if (!$json) {
      sendHttpReturnCodeAndMessage(400, $message);
    } else {
      sendHttpReturnCodeAndJson(400, $message);
    }
  }
  return $parameter;
}
?>