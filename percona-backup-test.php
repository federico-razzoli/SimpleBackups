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


try {
    $b = new PerconaBackup(dirname(__FILE__).'/percona-backup-test.ini');
    $i=600;
    while ($i--) {
        $b->backupTimestamp += 3600*24;
        //print("\n\n==================================================\n");
        $errorStatus = $b->run();
    }
    $b = null; // destroy it, so if it fails to unlock, we'll catch the exception
} catch (Exception $e) {
    PerconaBackup::logit("Uncaught exception: ".$e->getMessage()."\n".$e->getTraceAsString());
}