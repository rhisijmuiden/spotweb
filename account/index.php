<?php

// <spotweb>/account is a short address; send the browser to the real page
$base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/account/index.php'))), '/');
header('Location: '.$base.'/?page=edituserprefs', true, 302);
