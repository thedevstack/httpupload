<?php
/*
 *
 *
 */
include_once(__DIR__.'/lib/functions.common.inc.php');
include_once(__DIR__.'/lib/functions.http.inc.php');
require_once(__DIR__.'/lib/xmpp/xmpp.util.php');
include_once(__DIR__.'/lib/functions.webui.inc.php');

session_start();

if (empty($_SESSION['JID']) || empty($_SESSION['authenticated']) || $_SESSION['authenticated'] != 'yeah') {
    header('Location: login.php');
    exit();
}

//
$jid = $_SESSION['JID'];
$files = $_SESSION['FILE-LIST'];

?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- The above 3 meta tags *must* come first in the head; any other head content must come *after* these tags -->

    <title>Filetransfer - Webadministration</title>

    <!-- Bootstrap core CSS -->
    <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet">

    <!-- IE10 viewport hack for Surface/desktop Windows 8 bug -->
    <link href="bootstrap/ie10-viewport-bug-workaround.css" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/admin.css" rel="stylesheet">
    
    <script type="text/javascript" src="bootstrap/jquery.1.12.4.min.js"></script>

    <!-- Just for debugging purposes. Don't actually copy these 2 lines! -->
    <!--[if lt IE 9]><script src="../../assets/js/ie8-responsive-file-warning.js"></script><![endif]-->
    <script src="bootstrap/ie-emulation-modes-warning.js"></script>

    <!-- HTML5 shim and Respond.js for IE8 support of HTML5 elements and media queries -->
    <!--[if lt IE 9]>
      <script src="bootstrap/html5shiv.3.7.3.min.js"></script>
      <script src="bootstrap/respond.1.4.2.min.js"></script>
    <![endif]-->
  </head>

  <body>

    <!-- Fixed navbar -->
    <nav class="navbar navbar-default navbar-fixed-top">
      <div class="container">
        <div class="navbar-header">
          <a class="navbar-brand" href="#">thedevstack.de - Filetransfer - Webadministration</a>
        </div>
        <div id="navbar" class="navbar-collapse collapse">
          <ul class="navbar-nav navbar-right" style="padding-left: 0; margin-bottom: 0; list-style: none; color: #777">
            <li><span style="font-weight:bold;"><?=$jid;?></span> | <a href="logout.php">Logout</a></li>
          </ul>
        </div><!--/.nav-collapse -->
      </div>
    </nav>

    <div class="container">

      <div class="table-responsive">
            <table class="table table-striped">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Sender</th>
                  <th>Recipient</th>
                  <th>filename</th>
                  <th>filesize</th>
                  <th>content-type</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
<?php
foreach ($files as $file) {
    //$slot['receipient_jid']="";
?>
                <tr>
                  <td><?=date('D d.m.Y H:i:s', $file['timestamp']);?></td>
                  <td><?=$file['from'];?></td>
                  <td><?=$file['to'];?></td>
                  <td>
                  <?php if ($file['url']) {
                      echo '<a href="'.$file['url'].'" id="file-link-'.$file['timestamp'].'">'.$file['filename'].'</a>';
                  } else {
                      echo $file['filename'];
                  }?>
                  </td>
                  <td><?=format_size($file['size']);?></td>
                  <td><?=$file['type'];?></td>
                  <td>
                    <?php if (null != $file['url'] && "" != $file['url']) { ?>
                    <form id="delete-file-<?=$file['timestamp'];?>">
                      <button type="submit" id="submit-delete-file-<?=$file['timestamp'];?>">delete</button>
                    </form>
                    <?php } else { ?>
                    -
                    <?php } ?>
                  </td>
                </tr>
<?php
}
?>
              </tbody>
            </table>
          </div>

    </div> <!-- /container -->


    <!-- Bootstrap core JavaScript
    ================================================== -->
    <!-- Placed at the end of the document so the pages load faster -->
    <script src="bootstrap/jquery.1.12.4.min.js"></script>
    <script src="bootstrap/js/bootstrap.min.js"></script>
    <!-- IE10 viewport hack for Surface/desktop Windows 8 bug -->
    <script src="bootstrap/ie10-viewport-bug-workaround.js"></script>
    <script type="text/javascript">
        $(document).ready(function() {
<?php foreach ($files as $file) { ?>
            $("#submit-delete-file-<?=$file['timestamp'];?>").click(function(event) {
              event.preventDefault();
              $.ajax({
                  type: "POST",
                  url: "deleteFile.php",
                  data: "fileurl=<?=$file['url'];?>",
                  success: function(data) {
                      console.log(data);
                      if (data.error) {
                          alert('An error occured\n' + data.msg);
                      } else {
                          $("#delete-file-<?=$file['timestamp'];?>").replaceWith('-');
                          var filename = $("#file-link-<?=$file['timestamp'];?>").text();
                          $("#file-link-<?=$file['timestamp'];?>").replaceWith(filename);
                      }
                  },
                  error: function(result) {
                      console.log(result);
                      alert('server error\n' + result);
                  }
              });
            });
<?php } ?>
        });
    </script>
  </body>
</html>