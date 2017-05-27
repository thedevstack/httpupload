<?php
/*
 * xmpp jid util functions.
 */
 
function getJidDomain($jid) {
    if (null == $jid) {
        return null;
    }
    
    $atIndex = strpos($jid, '@');
    $slashIndex = strpos($jid, '/');
    
    if ($slashIndex !== false) {
        if ($slashIndex > $atIndex) {// 'local@domain.foo/resource' and 'local@domain.foo/res@otherres' case
            return substr($jid, $atIndex + 1, $slashIndex - $atIndex + 1);
        } else {// 'domain.foo/res@otherres' case
            return substr($jid, 0, $slashIndex);
        }
    } else {
        return substr($jid, $atIndex + 1);
    }
}

function getJidLocalPart($jid) {
    if ($jid == null) {
        return null;
    }

    $atIndex = strpos($jid, '@');
    if ($atIndex === false || $atIndex == 0) {
        return "";
    }
    
    $slashIndex = strpos($jid, '/');
    if ($slashIndex !== false && $slashIndex < $atIndex) {
        return "";
    } else {
        return substr($jid, 0, $atIndex);
    }
}

function getBareJid($jid) {
    if ($jid == null) {
        return null;
    }
    
    $slashIndex = strpos($jid, '/');
    if ($slashIndex === false) {
        return $jid;
    } else if ($slashIndex == 0) {
        return "";
    } else {
        return substr($jid, 0, $slashIndex);
    }
}

function getResource($jid) {
    if ($jid == null) {
        return null;
    }

    $slashIndex = strpos($jid, '/');
    if ($slashIndex + 1 > strlen($jid) || $slashIndex === false) {
        return "";
    } else {
        return substr($jid, $slashIndex + 1);
    }
}