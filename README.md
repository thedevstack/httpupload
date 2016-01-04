httpupload
==========
Implementation of the XMPP Extension Protocol (XEP) 0363 - HTTP Upload.

This implementation is divided into two parts:
* The prosody-module implementing the XEP
* The storage-backend implementing an external HTTP upload component to request "slots" for uploading files and serving these files

storage-backend
---------------
The storage backend is implemented in PHP and requires PHP >= 5.3.0.