<?php
namespace psesd\serverReporter;

class Provider
{
	protected $config;

	public function __construct($config = [])
	{
		$this->config = $config;
	}
	public function provide()
	{
		header("Content-type: application/json");
		$data = $this->getData(false);
		if (!$data) {
			return false;
		}
		echo json_encode($data);
	}

	public function log($message)
	{
		$logFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'provider.log';
		$message = date("F d, Y G:i:s") .': ' . $message . PHP_EOL;
		file_put_contents($logFile, $message, FILE_APPEND);
	}

	public function push()
	{
		if (!isset($this->config['push'])) {
			return false;
		}
		$errors = false;
		$data = $this->getData(true);
		if (!$data) {
			$this->log("Push failed because the data was stale");
			return false;
		}
		foreach ($this->config['push'] as $push) {
			$headers = ['Content-Type: application/x-www-form-urlencoded'];
			if (!empty($push['key'])) {
				$headers[] = 'X-Api-Key: ' . $push['key'];
			}
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $push['url']);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); 
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 300); 
			curl_setopt($ch, CURLOPT_TIMEOUT, 300);
			//execute post
			$result = $rawResult = curl_exec($ch);
			if (substr($result, 0, 1) === '{' && ($result = json_decode($result, true)) && !empty($result['status']) && $result['status'] === 'accepted') {
				// $this->log("Push to {$push['url']} succeeded");
			} else {
				$this->log("Push to {$push['url']} failed ({$rawResult})");
				$errors = true;
			}
			//close connection
			curl_close($ch);
		}
		return !$errors;
	}

	public function getLocalSensorData()
	{
		$sensors = [];
		$sensors['disk-space-C'] = $this->getDiskSpaceSensor('C');
		$sensors['disk-space-D'] = $this->getDiskSpaceSensor('D');
		return $sensors;
	}

	protected function getDiskSpaceSensor($drive)
	{
		$sensor = [
			'class' => 'canis\sensors\local\DynamicData',
			'id' => 'disk-space-' . $drive,
			'name' => 'Free Disk Space on ' . $drive,
			'dataValuePostfix' => '%'
		];
		$payload = [];
		$totalSpace = disk_total_space($drive .':');
		$freeSpace = disk_free_space($drive .':');
		$freePercent = ($freeSpace/$totalSpace)*100;
		if ($freePercent < 15) {
			$payload['state'] = 'low';
		} else {
			$payload['state'] = 'normal';
		}
		// $payload['total'] = $totalSpace;
		// $payload['free'] = $freeSpace;
		$payload['dataValue'] = round($freePercent, 1);
		$sensor['payload'] = $payload;
		return $sensor;
	}
	
	public function getData($isPush = false)
	{
		$data = ['timestamp' => time(), 'earliestNextCheck' => time(), 'provider' => null];
		$data['earliestNextCheck'] = time() + 60;
		if ($isPush) {
			$providerClass = 'canis\sensors\providers\PushProvider';
		} else { 
			$providerClass = 'canis\sensors\providers\PullProvider';
		}
		$data['provider'] = [
			'class' => $providerClass,
			'id' => $this->config['id'] .'',
			'name' => $this->config['name'],
			'meta' => [],
			'servers' => []
		];

		$data['provider']['servers']['self'] = [
			'class' => 'canis\sensors\servers\WindowsServer',
			'id' => $this->config['id'],
			'name' => $this->config['name'],
			'meta' => [],
			'resources' => [],
			'services' => [],
			'sensors' => $this->getLocalSensorData()
		];
		$data['provider']['servers']['self']['meta']['PHP Version'] = phpversion();

		$data['provider']['servers']['self']['services']['db'] = [
			'class' => 'canis\sensors\services\DatabaseService'
		];

		$filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'serverInfo.json';
		if (file_exists($filePath)) {
			$info = json_decode(file_get_contents($filePath), true);
			$age = false;
			if (isset($info['ips'])) {
				foreach ($info['ips'] as $ip) {
					$data['provider']['servers']['self']['resources'][] = [
						'class' => 'canis\sensors\resources\IP',
						'ip' => $ip
					];
				}
			}
			if (isset($info['timestamp'])) {
				$age = (time() - $info['timestamp']) / 60;
				if ($age > 60) {
					$age = false;
				}
			}
			if ($age === false) {
				return false;
			}
			if (!empty($info['databases'])) {
				foreach ($info['databases'] as $database) {
					$type = 'Database';
					$base = [];
					if (isset($this->config['databaseConfig'][$database['name']])) {
						$base = $this->config['databaseConfig'][$database['name']];
					}
					if (!empty($base['ignore'])) {
						continue;
					}
					if (isset($base['type'])) {
						$type = $base['type'];
						unset($base['type']);
					}
					$sensors = [];
					if (isset($database['lastBackup'])) {
						$sensor = [
							'class' => 'canis\sensors\local\Dynamic',
							'id' => 'database-has-backup',
							'name' => 'Database Backup'
						];
						$payload = [];
						$lastBackupAge = false;
						if ($database['lastBackup'] !== false && file_exists($database['lastBackup'])) {
							$lastBackupAge = (time()-filemtime($database['lastBackup']))/60/60;
						}
						if ($lastBackupAge === false || $lastBackupAge > 25) {
							$payload['state'] = 'error';
						} else {
							$payload['state'] = 'normal';
						}
						$sensor['payload'] = $payload;
						$sensors['database-has-backup'] = $sensor;
					}
					$databaseConfig = array_merge([
						'class' => 'canis\sensors\resources\\' . $type,
						'dbName' => $database['name'],
						'sensors' => $sensors
					], $base);
					$data['provider']['servers']['self']['resources'][] = $databaseConfig;
				}
			}
		}
		return $data;
	}
}
?>