<?php
require_once(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php');

$ips = [];
$cmd = 'ipconfig /all';
exec($cmd, $ipconfig);
$prefix = '   IPv4 Address. . . . . . . . . . . : ';
foreach ($ipconfig as $line) {
	if (substr($line, 0, strlen($prefix)) === $prefix) {
		$ip = substr($line, strlen($prefix));
		$ip = trim(strtr($ip, ['(Preferred)' => '']));
		$ips[] = $ip;
	}
}
$databases = [];
if (isset($config['fetchDatabaseNames'])) {
	$databaseNames = $config['fetchDatabaseNames']();
	if (!empty($databaseNames)) {
		foreach ($databaseNames as $dbName) {
			$databases[] = [
				'name' => $dbName,
				'lastBackup' => getLastBackup($dbName)
			];
		}
	}
}
$data = ['timestamp' => time(), 'ips' => $ips, 'databases' => $databases];
$filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'serverInfo.json';
file_put_contents($filePath, json_encode($data));

if (!file_exists($filePath)) {
	exit(1);
}
if (empty($runAfterParse)) {
	echo "Goodbye!";
	exit(0);
}

function getLastBackup($dbName) {
	global $config;
	if (!isset($config['databaseBackupDirectory']) || !is_dir($config['databaseBackupDirectory'])) {
		return null;
	}
	if (!isset($config['databaseBackupNameSeparator'])) {
		$config['databaseBackupNameSeparator'] = ' ';
	}
	$dbName = strtolower($dbName);
	static $backups;
	if (!isset($backups)) {
		$backups = [];
		$backupListing = scandir($config['databaseBackupDirectory']);
		foreach ($backupListing as $backupFile) {
			$backupPath = $config['databaseBackupDirectory'] . DIRECTORY_SEPARATOR . $backupFile;
			if (!is_file($backupPath)) { continue; }
			$backupParts = explode($config['databaseBackupNameSeparator'], $backupFile);
			$databaseName = strtolower($backupParts[0]);
			if (!isset($backups[$databaseName]) || filemtime($backups[$databaseName]) < filemtime($backupPath)) {
				$backups[$databaseName] = $backupPath;
			}
		}
	}
	if (isset($backups[$dbName])) {
		return $backups[$dbName];
	}
	return false;
}