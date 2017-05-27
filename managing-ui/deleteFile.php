<?php

include_once(__DIR__.'/lib/functions.http.inc.php');
require_once(__DIR__."/XMPPClient.php");
session_start();

if (empty($_SESSION['JID']) || empty($_SESSION['authenticated']) || $_SESSION['authenticated'] != 'yeah') {
    header('Location: login.php');
    exit();
}

//
$jid = $_SESSION['JID'];
$password = $_SESSION['PASSWORD'];

$fileurl = $_POST['fileurl'];

if (null != $fileurl && "" != $fileurl) {
    try {
        $client = new XMPPClient($jid, $password, true);
        if ($client->login()) {
            $client->deleteFile($fileurl);
            $client->disconnect();
        } else {
            $data = ['error' => true, 'msg' => 'Failed to login'];
        }
    } catch (XMPPException $e) {
        $data = ['error' => true, 'msg' => $e->getMessage()];
    }
} else {
    $data = ['error' => true, 'msg' => 'Missing required file url to delete'];
}

sendHttpReturnCodeAndJson(200, $data);

?>