<?php
include 'vendor/autoload.php';

define('ROOT', __DIR__);

include ROOT . '/client.php';
include ROOT . '/RC4.php';
$options = getopt("l:r:p:");
$remote_agent = explode(":", @$options['r']);
$local_agent = explode(":", @$options['l']);
$password = @$options['p'];
if (!@$remote_agent || !@$local_agent || !@$password) {
	die("Usage: php " . basename(__FILE__) . " -l 127.0.0.1:2333 -r 123.123.123.123:7777 -p password" . PHP_EOL);
}
$config = [
	'daemon' => 0,
	'process_num' => 10,
	'agent' => [
		'host' => $remote_agent[0],
		'port' => $remote_agent[1],
	],
	'apps' => [
		'host' => $local_agent[0],
		'port' => $local_agent[1],
	],
];
if ($config['daemon']) {
	$pid = pcntl_fork();
	if ($pid != 0) {
		exit(0);
	}
}

for ($i = 2; $i < $config['process_num']; $i++) {
	$pid = pcntl_fork();
	if ($pid == 0) {
		break;
	}
}

if ($pid == 0) {
	child:
	//echo "fork_child|" . getmypid() . PHP_EOL;

	{
		(new SProxyClient(
			$config['agent']['host'],
			$config['agent']['port'],
			$config['apps']['host'],
			$config['apps']['port'],
			$password

		))->run();
	}

	exit;
}
$table = new LucidFrame\Console\ConsoleTable();
$table_remote = new LucidFrame\Console\ConsoleTable();
echo "ProjectNeko Starting.." . PHP_EOL;
echo "Remote Server:" . PHP_EOL;
$table_remote->addHeader('Host')->addHeader('Port')->addRow()->addColumn($remote_agent[0])->addColumn($remote_agent[1])->display();
echo PHP_EOL . "Local Application:" . PHP_EOL;

$table->addHeader('Host')->addHeader('Port')->addRow()->addColumn($local_agent[0])->addColumn($local_agent[1])->display();

echo PHP_EOL . "ProjectNeko Start Success...";
while (true) {
	$pid = pcntl_fork();
	if ($pid == 0) {
		goto child;
	}
	$child_pid = pcntl_wait($status);
	if ($child_pid == -1) {
		break;
	}
	sleep(10);
}
