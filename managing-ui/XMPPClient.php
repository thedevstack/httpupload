<?php
require_once(__DIR__."/lib/xmpp/Exception.php");
require_once(__DIR__."/lib/xmpp/XMPPLog.php");
require_once(__DIR__."/lib/xmpp/Roster.php");
require_once(__DIR__."/lib/xmpp/XMLObj.php");
require_once(__DIR__."/lib/xmpp/XMLStream.php");
require_once(__DIR__."/lib/xmpp/XMPP.php");
require_once(__DIR__."/lib/xmpp/xmpp.util.php");
require_once(__DIR__.'/lib/Encryption.class.php');

class XMPPClient extends XMPP {
    const ENCRYPTION_KEY = "ashdkZ/(TZiuh2qeh12h89z9pghi2ug";
    private $defaultPort = 5222;
    private $defaultResource = 'filetransfer-http-managing-ui';
    
    public function __construct($jid, $password, $passwordEncrypted = false) {
        parent::__construct(getJidDomain($jid), $this->defaultPort, getJidLocalPart($jid), ($passwordEncrypted ? $this->decrypt($password) : $password), $this->defaultResource);
    }
    
    public function loginAndGetFileList() {
        if ($this->login()) {
            $this->getFileList();
        }
    }
    
    public function deleteFile($fileurl) {
        if (null == $fileurl || '' == $fileurl) {
            throw new XMPPException('Missing fileurl');
        }
        $id = $this->getId();
        $this->addIdHandler($id, 'deleteFileIqHandler');
        $xml = "<iq xmlns='jabber:client' type='get' to='$this->host' id='$id'><request xmlns='urn:xmpp:filetransfer:http' type='delete'><fileurl>$fileurl</fileurl></request></iq>";
        $this->send($xml);
    }
    
    private function decrypt($password) {
        $e = new Encryption(MCRYPT_BlOWFISH, MCRYPT_MODE_CBC);
        return $e->decrypt($password, XMPPClient::ENCRYPTION_KEY);
    }
    
    public function login() {
        try {
            $this->connect(5);
            $this->processUntil('session_start', 10);
            session_start();
            $_SESSION["authenticated"] = 'yeah';
            $_SESSION["JID"] = $this->basejid;
            $e = new Encryption(MCRYPT_BlOWFISH, MCRYPT_MODE_CBC);
            $encryptedPassword = $e->encrypt($this->password, XMPPClient::ENCRYPTION_KEY);
            $_SESSION["PASSWORD"] = $encryptedPassword;
            return true;
        } catch (XMPPException $e) {
            echo $e->getMessage();
            return false;
        }
    }
    
    protected function getFileList() {
        $id = $this->getId();
        $this->addIdHandler($id, 'fileListIqHandler');
        $this->send("<iq xmlns='jabber:client' type='get' to='$this->host' id='$id'><request xmlns='urn:xmpp:filetransfer:http' type='list'/></iq>");    
        $this->processUntil("list_loaded");
    }
    
    protected function fileListIqHandler($xml) {
        $list = $xml->sub('list');
        $fileList = [];
        foreach ($list->subs as $fileXML) {
            $file = [];
            $file['url'] = $fileXML->sub('url')->data;
            $file['timestamp'] = $fileXML->attrs['timestamp'];
            $file['to'] = $fileXML->attrs['to'];
            $file['from'] = $fileXML->attrs['from'];
            $fileInfo = $fileXML->sub('file-info');
            $file['filename'] = $fileInfo->sub('filename')->data;
            $file['size'] = $fileInfo->sub('size')->data;
            $file['type'] = $fileInfo->sub('content-type')->data;
            $fileList[] = $file;
        }
        $this->event('list_loaded');
        $_SESSION['FILE-LIST'] = $fileList;
        header('Location: index.php');
    }   

    protected function deleteFileIqHandler($xml) {
      $deleted = $xml->sub('deleted');
      if (null != $deleted) {
        $data = ['error' => false, 'msg' => 'File successfully deleted'];
      } else {
        $error = $xml->sub('error');
        $data = ['error' => true, 'msg' => $error->subs[0]->name."\n".$error->sub('text')->data];
      }
      sendHttpReturnCodeAndJson(200, $data);
    }
}
?>