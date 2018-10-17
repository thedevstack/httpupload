<?php
/*
 *
 * This file contains functions to be used to
 * extract information based on http request information.
 *
 */
 
require_once('functions.common.inc.php');

/**
 * Inspired by https://github.com/owncloud/core/blob/master/lib/private/appframework/http/request.php#L523
 */
function getServerProtocol() {
    $protocol = getHeaderExtensionValue('FORWARDED_PROTO');
    if (isset($protocol)) {
        if (strpos($protocol, ',') !== false) {
            $parts = explode(',', $protocol);
            $proto = strtolower(trim($parts[0]));
        } else {
            $proto = strtolower($protocol);
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
    $forwardedHost = getHeaderExtensionValue('FORWARDED_HOST');
    if (isset($forwardedHost)) {
        return strtolower($forwardedHost);
    }
    return strtolower(getHeaderValue('HOST'));
}

function getRequestUriWithoutFilename() {
    return strtolower(substr($_SERVER['REQUEST_URI'], 0, strrpos($_SERVER['REQUEST_URI'], '/') + 1));
}

function sendHttpReturnCodeAndJson($code, $data) {
    if (!is_array($data)) {
        $data = ['msg' => $data];
    }
    
    setContentType('application/json');
    
    sendHttpReturnCodeAndMessage($code, json_encode($data));
}

function sendHttpReturnCodeAndMessage($code, $text = '') {
    http_response_code($code);
    exit($text);
}

function setContentType($contentType) {
    header('Content-Type: '.$contentType);
}

function getHeaderExtensionValue($headerName) {
    $headerName = strtoupper($headerName);
    if (!startsWith($headerName, 'HTTP_X_')) {
        if (!startsWith($headerName, 'HTTP_')) {
            $headerName = str_replace('HTTP_', 'HTTP_X_', $headerName);
        } else {
            $headerName = 'HTTP_X_'.$headerName;
        }
    }
    
    return getHeaderValue($headerName);
}

function getHeaderValue($headerName) {
    $headerName = strtoupper($headerName);
    if (!startsWith($headerName, 'HTTP_')) {
        $headerName = 'HTTP_'.$headerName;
    }
    
    return getFromArray($headerName, $_SERVER);
}

function getFileParameter($parameterName) {
    return getFromArray($parameterName, $_FILES);
}

function getOptionalFileParameter($parameterName, $default = NULL) {
    $parameter = getFileParameter($parameterName);

    return handleOptionalParameter($parameter, $default);
}

function getMandatoryFileParameter($parameterName, $message = '', $json = false) {
    $parameter = getFileParameter($parameterName);

    return handleMandatoryParameter($parameterName, $parameter, $message, $json);
}

function getPostParameter($parameterName) {
    return getFromArray($parameterName, $_POST);
}

function getOptionalPostParameter($parameterName, $default = NULL) {
    $parameter = getPostParameter($parameterName);
    
    return handleOptionalParameter($parameter, $default);
}

function getMandatoryPostParameter($parameterName, $message = '', $json = false) {
    $parameter = getPostParameter($parameterName);
    
    return handleMandatoryParameter($parameterName, $parameter, $message, $json);
}

function getGetParameter($parameterName) {
    return getFromArray($parameterName, $_GET);
}

function getOptionalGetParameter($parameterName, $default = NULL) {
    $parameter = getGetParameter($parameterName);
    
    return handleOptionalParameter($parameter, $default);
}

function getMandatoryGetParameter($parameterName, $message = '', $json = false) {
    $parameter = getGetParameter($parameterName);
    
    return handleMandatoryParameter($parameterName, $parameter, $message, $json);
}

function handleOptionalParameter($parameter, $default) {
    if (!isset($parameter) || is_null($parameter) || empty($parameter)) {
        $parameter = $default;
    }
    return $parameter;
}

function handleMandatoryParameter($parameterName, $parameter, $message, $json) {
    if (!isset($parameter) || is_null($parameter) || empty($parameter)) {
        if (empty($message) || is_null($message)) {
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
