<?php
require_once(__DIR__.'/XMPPClient.php');

$username = "";
$errorMessage = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    if(!empty($_POST["username"]) && !empty($_POST["password"])) {
        $username = $_POST["username"];
        $password = $_POST["password"];
        
        $client = new XMPPClient($username, $password);
        $client->loginAndGetFileList();
    } else {
        $errorMessage = "Username and password are required.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="content-type" content="text/html; charset=utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Filetransfer - Webadministration</title>
    
    <!-- Bootstrap core CSS -->
    <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet">

    <!-- IE10 viewport hack for Surface/desktop Windows 8 bug -->
    <link href="bootstrap/ie10-viewport-bug-workaround.css" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/signin.css" rel="stylesheet">

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
        <div class="container">
<?php
if (!empty($errorMessage)) {
?>
        <span style="color: #ff0000"><?=$errorMessage;?></span>
<?php
}
?>

      <form class="form-signin" method="post">
        <h2 class="form-signin-heading">Please sign in</h2>
        <label for="inputEmail" class="sr-only">Email address</label>
        <input type="email" id="inputEmail" name="username" value="<?=$username;?>" class="form-control" placeholder="XMPP address" required autofocus>
        <label for="inputPassword" class="sr-only">Password</label>
        <input type="password" id="inputPassword" name="password" class="form-control" placeholder="Password" required>
        <button class="btn btn-lg btn-primary btn-block" type="submit">Sign in</button>
      </form>

    </div> <!-- /container -->
    
</body>
</html>