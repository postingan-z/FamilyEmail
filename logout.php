<?php
setcookie('mailgen_session','',time()-3600,'/');
header('Location: /mailgen/login.php');
exit;
