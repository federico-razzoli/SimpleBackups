<?php
/**
 *
 * - take daily backup
 * - rotate weekly and monthly
 */

class PerconaBackup {

    /**
     * Which day of week should we retain it's backup for weekly rotation
     *
     * As returned by date('N'), 1 (for Monday) through 7 (for Sunday)
     * Last weeklyBackupDOW (i.e. "last thursday") in the month will be retained for monthly archive
     *
     * @var int
     */
    public $weeklyBackupDOW = 5;

    /**
     * Base directory for backups
     *
     * @var string
     */
    public $backupsDir = '/var/lib/backups';

    /**
     * File pointer to lock file
     *
     * @var string
     */
    private $lockFileHandle =  null;

    /**
     * File with [client] section holding all necessary information to connect
     *
     * Only current way of specifying connection params (as well as other params can be given under [xtrabackup] secion
     *
     * @var string
     */
    public $mysqlDefaultsFile = 'my.percona-backup.cnf';

    /**
     * "Type" of backups we handle.
     *
     * Just handy to have to validate.
     *
     * @var array
     */
    private $backupTypes = array('daily', 'weekly', 'monthly');

    /**
     * Backup driver;
     *
     * Right now only xtrabackup and test are supported
     *
     * @var string
     * @todo separate all xtrabackup stuff to own class and use class-based drivers
     */
    public $backupDriver = 'test';

    /**
     * Should we run the --apply-log step
     *
     * @var boolean
     */
    public $doApplyLog = true;

    /**
     * Value for --use-memory when running --apply-log
     *
     * @var string
     */
    public $applyLogMemoryLimit = '1G';

    /**
     * The timestamp of the date for the backup
     *
     * @var int
     */
    public $backupTimestamp = null;

    /**
     * Path to innobackupex executable
     *
     * @var string
     */
    public $xb = '/usr/bin/innobackupex';

    /**
     * Path to mysqladmin executable
     *
     * @var string
     */
    public $mysqladmin = '/usr/bin/mysqladmin';

    /**
     * Path to mysqldump executable
     *
     * @var unknown_type
     */
    public $mysqldump = '/usr/bin/mysqldump';

    public $verbose = false;

    public function __construct($configFile=null) {
        self::logit("entering ".__METHOD__);
        $this->setDefaults();
        !empty($configFile) && $this->loadConfig($configFile);
    }

    public function __destruct() {
        self::logit("entering ".__METHOD__);
        $this->releaseLock();
    }

    public function run () {
        self::logit("entering ".__METHOD__);
        $this->bootstrap();
        $this->preFlightChecks();
        self::logit("Doing '{$this->backupDriver}' backup with timestamp {$this->backupTimestamp} (".date("Y-m-d", $this->backupTimestamp).")");

        $this->acquireLock();
        try {
            $this->doBackup();
            $this->doDailyArchiving();
            $this->doWeeklyArchiving();
            $errorStatus = 0;
        } catch (Exception $e) {
            self::logit("Uncaught exception: ".$e->getMessage()."\n".$e->getTraceAsString());
            $errorStatus = 1;
        }
        $this->releaseLock();

        return $errorStatus;
    }

    public function doBackup () {
        self::logit("entering ".__METHOD__);
        $backupType = 'daily';
        $timestamp = date('Y-m-d', $this->backupTimestamp);
        $targetDir = "{$this->backupsDir}/{$backupType}/{$timestamp}/";
        if (is_dir($targetDir)) {
            throw new Exception("Refusing to overwrite existing backup folder {$targetDir}\n");
        }
        if (!mkdir($targetDir, 0770)) {
            throw new Exception("Can't create target directory {$targetDir}");
        }

        $backupMethod = "_doBackup{$this->backupDriver}";
        $this->$backupMethod($targetDir);
    }

    private function _doBackupTest ($targetDir) {
        self::logit("entering ".__METHOD__);
        touch("{$targetDir}/test.backup");
    }

    private function _doBackupMysqldump ($targetDir, $mysqldumpExtraParams='') {
        self::logit("entering ".__METHOD__);
        // we could autodetect binlogging and act accordingly
        // $mysqldumpExtraParams = "--master-data=2 "
        $cmd = "{$this->mysqldump} --defaults-file={$this->mysqlDefaultsFile} --verbose --single-transaction {$mysqldumpExtraParams} --all-databases --events --triggers --comments --dump-date --log-error={$targetDir}/mysqldump.backup.output --result-file={$targetDir}/backup-dump.sql";
        $backupOutput = array();
        $backupStatus = null;
        exec("nohup {$cmd} 2>&1 > {$targetDir}/mysqldump.backup.output", $backupOutput, $backupStatus);

        if ($backupStatus !== 0) {
            throw new Exception("mysqldump returned with error <> 0! exited with status: '{$backupStatus}'; cmd: '{$cmd}'\n output:\n ".explode("\n", $backupOutput));
        }
    }

    private function _doBackupXtrabackup ($targetDir) {
        self::logit("entering ".__METHOD__);
        $cmd = "{$this->xb} --defaults-file={$this->mysqlDefaultsFile} --no-timestamp {$targetDir}";
        $backupOutput = array();
        $backupStatus = null;
        exec("nohup {$cmd}  2>&1 > {$targetDir}/innobackupex.backup.output", $backupOutput, $backupStatus);

        if ($backupStatus !== 0) {
            throw new Exception("XtraBackup failed to take the backup! exited with status: '{$backupStatus}'; cmd: '{$cmd}';\noutput:\n ".explode("\n", $backupOutput));
        }

        if ($this->doApplyLog) {
            $cmd = "{$this->xb} --defaults-file={$this->mysqlDefaultsFile} --apply-log --use-memory={$this->applyLogMemoryLimit} {$targetDir}";
            $applyOutput = array();
            $applyStatus = null;
            exec("nohup {$cmd}  2>&1 > {$targetDir}/innobackupex.apply.output", $applyOutput, $applyStatus);
        }
        if ($applyStatus !== 0) {
            throw new Exception("XtraBackup failed too apply logs! exited with status: '{$backupStatus}'; cmd: '{$cmd}';\noutput:\n ".explode("\n", $applyOutput));
        }
    }

    public function doDailyArchiving () {

        $backupsTree = $this->getBackupsList();
        if ($this->weeklyBackupDOW == date("N", $this->backupTimestamp)) {
            // rotation day, check if we have backup from 7 days ago and, if exists, archive it
            self::logit("It's rotation day! (".date("N", $this->backupTimestamp).")");
            if (count($backupsTree['daily']) > 7) {
                self::logit("and we have a week worth of backups...");
                $weekAgo = date("Y-m-d", mktime(0, 0, 0, date("m", $this->backupTimestamp), date("d", $this->backupTimestamp) - 7, date("Y", $this->backupTimestamp)));
                if (in_array($weekAgo, $backupsTree['daily'])) {
                    self::logit("Archiving weekly backup from a week ago ({$weekAgo})");
                    $this->archiveBackup($weekAgo);
                } else {
                    self::logit("We don't have last week's backup ({$weekAgo})");
                }
            } else {
            	self::logit("But there are less than 7 daily backups total...nothing to do here.");
            }
        } else {
            if (count($backupsTree['daily']) > 7) {
                // prune oldest backup
                $oldestBackup = $backupsTree['daily'][0];
                self::logit("Is not rotation day but we have more than a week worth of backups; Removing oldest daily backup {$this->backupsDir}/daily/{$oldestBackup}");
                $this->removeBackup($oldestBackup, 'daily');
            }
        }
    }

    public function doWeeklyArchiving () {
        $backupsTree = $this->getBackupsList();
        if (count($backupsTree['weekly']) > 4) {
            self::logit("We have more than 4 weekly backups...");
            // $oldestWeeklyBackup = explode('-',$backupsTree['weekly'][0]);
            $oldestWeeklyBackupTimestamp = strtotime($backupsTree['weekly'][0]);
            $oldestWeeklyBackupWeek = date("W", $oldestWeeklyBackupTimestamp);
            $oldestWeeklyBackupMonth = date("M", $oldestWeeklyBackupTimestamp);

            // "last week of that month with a rotationDOW"
            $lastWeekOfThatMonthTimestamp = mktime(0,0,0, date("m", $oldestWeeklyBackupTimestamp), date("t", $oldestWeeklyBackupTimestamp), date("Y", $oldestWeeklyBackupTimestamp));
            $lastWeekOfThatMonth = date("W", $lastWeekOfThatMonthTimestamp);
            $lastDayOfThatMonthDOW = date("N", $lastWeekOfThatMonthTimestamp);

            if ($lastDayOfThatMonthDOW < $this->weeklyBackupDOW) {
                self::logit("Last week of {$oldestWeeklyBackupMonth} (#{$lastWeekOfThatMonth}) has no day-of-week number {$this->weeklyBackupDOW}; using previous week's backup");
                $lastWeekOfThatMonth--;

                if ($lastWeekOfThatMonth === 0) {
                    // week #1, but backup should be for last week of December, which is 52 (since this "last week of december" was 1)
                    self::logit("Last week of {$oldestWeeklyBackupMonth} is #1 so previous week is #52");
                    $lastWeekOfThatMonth = 52;

                }
            }


            if ($oldestWeeklyBackupWeek == $lastWeekOfThatMonth) {
                self::logit("Archiving last weekly backup of {$oldestWeeklyBackupMonth} ({$backupsTree['weekly'][0]})");
                $this->archiveBackup($backupsTree['weekly'][0], 'weekly', 'monthly');
            } else {
                self::logit("Removing oldest weekly backup {$backupsTree['weekly'][0]} since week $oldestWeeklyBackupWeek is not last week of {$oldestWeeklyBackupMonth}, which is {$lastWeekOfThatMonth}");
                $this->removeBackup($backupsTree['weekly'][0], 'weekly');
            }
        } else {
        	self::logit("We have less than or exactly 4 weekly backups...nothing to do here");
        }
    }

    public function getBackupsList($type=null) {
        self::logit("entering ".__METHOD__);
        $type = !empty($type) ? (in_array($type, $this->backupTypes) ? $type : 'daily') : null;
        $types = !empty($type) ? array($type) : $this->backupTypes;
        $list = array();
        foreach ($types as $typeDir) {
            $d =  new DirectoryIterator("{$this->backupsDir}/{$typeDir}");
            $list[$typeDir] = array();
            foreach ($d as $dirItem) {
                if ($dirItem->isDir() && !$dirItem->isDot()) {
                    $list[$typeDir][] = $dirItem->getFileName();
                }
            }
            sort($list[$typeDir]);
        }
        return !empty($type) ? $list[$typeDir] : $list;
    }

    public function removeBackup ($backupDate, $type) {
        self::logit("entering ".__METHOD__);
        if (!in_array($type, $this->backupTypes)) {
            throw new Exception(__METHOD__.": Wrong backup type given: {$type}");
        }
        $backupPath = realpath("{$this->backupsDir}/{$type}/{$backupDate}");

        if (!file_exists($backupPath)) {
            throw new Exception(__METHOD__.": file doesn't exists '{$backupPath}'");
        }
        if (!is_writeable($backupPath)) {
            throw new Exception(__METHOD__.": can't write to '{$backupPath}'");
        }

        if (strpos($backupPath, $this->backupsDir) === 0) {
            $isOK = passthru("rm -rf {$backupPath}");
        } else {
            self::logit("Backup path '{$backupPath}' is not within '{$this->backupsDir}'");
        }
        return $isOK;
    }

    public function archiveBackup ($backupDate, $from='daily', $to='weekly') {
        self::logit("entering ".__METHOD__);
        if (!in_array($from, $this->backupTypes) || !in_array($to, $this->backupTypes)  ||
            $from=='monthly' || ($from=='weekly' && $to=='daily') ) {
            throw new Exception(__METHOD__.": Wrong archiving from/to given: {$from}/{$to}");
        }
        $source = "{$this->backupsDir}/{$from}/{$backupDate}/";
        $targetBase = "{$this->backupsDir}/{$to}";
        $target = "{$targetBase}/{$backupDate}/";
        if (!file_exists($source)) {
            throw new Exception(__METHOD__.": file doesn't exists '{$source}'");
        }
        if (!is_writeable($targetBase)) {
            throw new Exception(__METHOD__.": can't write to '{$targetBase}'");
        }
        if (!rename($source, $target)) {
            throw new Exception(__METHOD__.": failed to rename '{$source}' as '{$target}'");
        }
    }

    public function setDefaults () {
        self::logit("entering ".__METHOD__);
        $this->backupTimestamp = mktime();
        $this->mysqlDefaultsFile = dirname(__FILE__).'/my.percona-backup.cnf';
        //$this->lockFile = "{$this->backupsDir}/lock";
        $this->xb = shell_exec('/usr/bin/which innobackupex');
        $this->mysqladmin = shell_exec('/usr/bin/which mysqladmin');
        $this->mysqldump = shell_exec('/usr/bin/which mysqldump');
    }

    public function loadConfig ($configFile) {
        self::logit("entering ".__METHOD__);
        if (!file_exists($configFile)) {
            throw new Exception("Config file '{$configFile}' doesn't exists", 201);
        }

        if (!is_readable($configFile)) {
            throw new Exception("Can't read backups configuration from '{$configFile}', please check permissions", 202);
        }

        $conf = parse_ini_file($configFile, true);
        self::logit("Loading config file {$configFile}");
        if (!is_array($conf)) {
            throw new Exception("Failed to load configuration file '{$configFile}'; Likely malformed .ini");
        }
        //self::logit("\$conf: ".print_r($conf));
        $classMembers = get_class_vars(__CLASS__);
        // print_r($classMembers);
        foreach ($conf as $k => $v) {
            if (!isset($classMembers[$k])) {
                self::logit("Warning: '{$k}' is not a class member; (v: '{$v}')");
                continue;
            }
            $this->{$k} = $v;
        }
    }

    public function bootstrap () {
        self::logit("entering ".__METHOD__);
        // make sure the different backup types have their destination folder.
        foreach ($this->backupTypes as $backupType) {
        	$d = "{$this->backupsDir}/{$backupType}";
        	if (!is_dir($d)) {
        	    print("Creating backups folder {$d}\n");
        	    if (!mkdir($d, 0770, true)) {
        	        throw new Exception("Can't create backup dir '{$d}'");
        	    }
        	}
        }

        if (!file_exists($this->lockFile) && !touch($this->lockFile)) {
            throw new Exception("Can't touch $this->lockFile; check permissions");
        }
    }

    public function acquireLock () {
        self::logit("entering ".__METHOD__);
        $this->lockFileHandle = fopen($this->lockFile, "w");
        if (flock($this->lockFileHandle, LOCK_EX)) {
            self::logit("Sucessfully locked $this->lockFile");
        } else {
            // we could/should find the other running instance and report pid
            throw new Exception("Can't aquire lock on {$this->lockFile} (which means other instance must be working on this backups dir ($this->backupsDir). Aborting!");
        }
    }

    public function releaseLock () {
        self::logit("entering ".__METHOD__);
        if (file_exists($this->lockFile)) {
            if (flock($this->lockFileHandle, LOCK_UN)) {
                self::logit("Released lock on {$this->lockFile}");
                fclose($this->lockFileHandle);
                unlink($this->lockFile);
            } else {
            	throw new Exception("Can't unlock {$this->lockFile}");
            }
        } else {
            self::logit("No lockfile {$this->lockFile}; so can't consider unlocking...");
        }
    }


    public function preFlightChecks () {
        self::logit("entering ".__METHOD__);


        self::logit("Backups dir is {$this->backupsDir}, and the MySQL defaults file is {$this->mysqlDefaultsFile}");
        if (!is_dir($this->backupsDir)) {
            throw new Exception("Target dir '{$this->backupsDir}' doesn't exists", 101);
        }

        if (!is_writable($this->backupsDir)) {
            throw new Exception("Can't write to target dir '{$this->backupsDir}', please check permissions", 102);
        }

        $diskSpaceInfo = $this->_diskSpaceInfo();
        if ($diskSpaceInfo->available < $diskSpaceInfo->required * 1.1) {
            throw new Exception("Disk space likely not enough to hold next backup. Aborting as a safety measure");
        }


        if (!file_exists($this->mysqlDefaultsFile)) {
            throw new Exception("Connection parameters file '{$this->mysqlDefaultsFile}' doesn't exists", 201);
        }

        if (!is_readable($this->mysqlDefaultsFile)) {
            throw new Exception("Can't read connection parameters from '{$this->mysqlDefaultsFile}', please check permissions", 202);
        }

        $shellStatus = null;
        $shellCmd = "{$this->mysqladmin} --defaults-file={$this->mysqlDefaultsFile} ping";
        exec($shellCmd, $null, $shellStatus);
        if ($shellStatus !== 0) {
            $mysqldPid = exec("pgrep -x mysqld", $null, $shellStatus);
            if ($shellStatus !== 0) {
                throw new Exception("The mysqld daemon is not running");
            } else {
            	throw new Exception("Invalid MySQL credentials (mysqld PID: {$mysqldPid}) - {$shellCmd}");
            }
        }

        if ($this->backupDriver == 'xtrabackup' && (empty($this->xb) || !is_executable($this->xb))) {
            throw new Exception("Failed to find innobackupex executable, or don't have enough rights to run it (xb: {$this->xb})");
        }

        if ($this->backupDriver == 'mysqldump' && empty($this->mysqldump) || !is_executable($this->mysqldump)) {
            throw new Exception("Failed to find mysqldump executable, or don't have enough rights to run it (mysqldump: {$this->mysqldump})");
        }
    }

    public static function logit($message) {
        if (stripos($message, 'entering') !== 0 || $this->verbose) {
            $log = "[".date('Y-m-d H:i:s')."] ".__CLASS__.": {$message}\n";
            syslog(LOG_INFO, $log);
            echo $log;
        }
    }

    private function _diskSpaceInfo () {
        self::logit("entering ".__METHOD__);
        $dailyBackups = $this->getBackupsList('daily');
        $lastBackup = array_pop($dailyBackups);
        $i = new stdClass();
        $i->required = exec("du -s -b {$this->backupsDir}/daily/{$lastBackup} | awk '{ print $1 }'");
        // $requiredSpace *= 1.1; // @todo: estimated 10% growth, we could/should set this as a class member?

        $i->available = disk_free_space("{$this->backupsDir}/daily/");
        self::logit("estimated disk space required:{$i->required} / available:{$i->available}");

        return $i;

    }

    public function __get($var) {
        if ($var == 'lockFile') {
            return $this->backupsDir.'/lock';
        }
        return null;
    }
}
