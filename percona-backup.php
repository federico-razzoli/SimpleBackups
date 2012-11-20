<?php

require_once(dirname(__FILE__).'/PerconaBackup.php');


$longopts  = array(
    /*
    "opt" // bool
    "opt:" // mandatory
    "opt::" // optional
    */
    "mysqldump::",      // mysqldump binary
    "mysqladmin::",     // mysqladmin binary
    "defaults-file::",   // my.cnf with [client] section
    "driver::"
);
// $options = getopt("",$longopts);

$errorStatus = 0;
try {
    $b = new PerconaBackup(dirname(__FILE__).'/percona-backup.ini');
    $errorStatus = $b->run();
    $b = null; // destroy it, so if it fails to unlock, we'll catch the exception
} catch (Exception $e) {
    PerconaBackup::logit("Uncaught exception: ".$e->getMessage()."\n".$e->getTraceAsString());
}

exit($errorStatus);