<?php
include 'vendor/autoload.php';
define('ROOT', __DIR__);

include ROOT . '/conf.php';
include ROOT . '/RC4.php';
$table = new LucidFrame\Console\ConsoleTable();
echo "ProjectNeko Starting.." . PHP_EOL;
echo "Loading Application List" . PHP_EOL . PHP_EOL;
$table->setHeaders(array('LocalPort', 'RemotePort', 'Type'));
foreach ($proxy_conf['ports'] as $key => $value) {
	if ($value[2] == 0) {
		$table->addRow(array($value[0], $value[1], "Socket"));
	} else {
		$table->addRow(array($value[0], $value[1], "HTTP"));
	}
}
$table->showAllBorders()->display();
class SProxyServer {

	private $_proxy_server = null;

	private $_conf = [];
	private $_setting = [];
	public $argv;
	private $_gtable = null;
	public function __construct($argv) {
		//解析
		$this->parse_conf(include ROOT . '/conf.php');
		//配置
		$this->_setting = [
			'daemonize' => $this->_conf['daemon'],
			'backlog' => 128,
			'reactor_num' => swoole_cpu_num(), //默认会启用CPU核数相同的数量
			'worker_num' => 2 * swoole_cpu_num(), //设置为CPU的1-4倍最合理
			'dispatch_mode' => 2, //2 固定模式，根据连接的文件描述符分配worker。这样可以保证同一个连接发来的数据只会被同一个worker处理
			'open_tcp_nodelay' => 1,
			'enable_reuse_port ' => 1,
			'log_file' => ROOT . '/logs/proxy.log', //指定swoole错误日志文件
		];
		$this->argv = $argv;
	}

	private function parse_conf($conf) {
		$this->_conf['daemon'] = $conf['daemon'];
		$this->_conf['host'] = $conf['host'];

		foreach ($conf['ports'] as $ports) {
			if (!isset($this->_conf['port'])) {
				$this->_conf['port'] = $ports[0];
			}

			$this->_conf['proxy'][$ports[0]] = [$ports[1], $ports[2]];
		}
	}

	private function set_process_title($title) {
		if (PHP_OS != 'Darwin') {
			@swoole_set_process_name($title);
		}
	}

	private function log() {
		$arguments = func_get_args();
		if (1 == count($arguments)) {
			if (is_scalar($arguments[0])) {
				$message = $arguments[0];
			} elseif (is_array($arguments[0])) {
				$message = json_encode($arguments[0]);
			} elseif ($arguments[0] instanceof Exception) {
				$message = $arguments[0]->getMessage() . ' in ' . $arguments[0]->getFile() . ' at ' . $arguments[0]->getLine() . ' line';
			} elseif (is_object($arguments[0])) {
				$message = $arguments[0]->toString();
			}
		} else {
			$message = call_user_func_array('sprintf', $arguments);
		}

		echo "[" . date('H:i:s') . "]" . $message . PHP_EOL;
	}

	public function proxy_master_start(swoole_server $proxy_server) {
		$this->set_process_title('php ' . $this->_conf['file_name'] . ' proxy_server_master');
	}

	public function proxy_manager_start(swoole_server $proxy_server) {
		$this->set_process_title('php ' . $this->_conf['file_name'] . ' proxy_server_manager');
	}

	public function proxy_shutdown(swoole_server $proxy_server) {}

	public function proxy_worker_start(swoole_server $proxy_server, $worker_id) {
		// swoole_timer_tick(5000, function () {
		// 	$this->reload_conf();
		// });
		if ($proxy_server->taskworker) {
			$this->set_process_title('php ' . $this->_conf['file_name'] . ' proxy_server_task_worker');
		} else {
			$this->set_process_title('php ' . $this->_conf['file_name'] . ' proxy_server_event_worker');
		}
	}

	public function proxy_worker_stop(swoole_server $proxy_server, $worker_id) {
		$this->log('worker stop|%s,%s', getmypid(), $worker_id);
	}

	public function proxy_worker_error(swoole_server $proxy_server, $worker_id, $worker_pid, $exit_code, $signo) {
		$this->log('worker error|%s,%s,%s,%s', $worker_pid, $worker_id, $exit_code, $signo);
	}

	public function proxy_client_close(swoole_server $proxy_server, $client_fd, $from_reactor_id) {
		$this->log('client_close|%s', $client_fd);

		$client_detail = $this->_gtable->get($client_fd);

		$this->_gtable->del($client_fd);

		if ($client_detail) {
			$target_fd = $client_detail['tfd'];
			if ($client_detail['status'] > 0) {
				$this->log('client_close|%s,cport=%s,tport=%s,close_target,%s', $client_fd, $client_detail['cport'], $client_detail['tport'], $target_fd);
				$this->_gtable->del($target_fd);
				$proxy_server->close($target_fd);
			}
		}
	}

	public function proxy_target_close(swoole_server $proxy_server, $target_fd, $from_reactor_id) {
		$this->log('target_close|%s', $target_fd);

		$target_detail = $this->_gtable->get($target_fd);

		$this->_gtable->del($target_fd);

		if ($target_detail) {
			$client_fd = $target_detail['cfd'];
			if ($target_detail['status'] > 0) {
				$this->log('target_close|%s,cport=%s,dport=%s,close_client,%s', $target_fd, $target_detail['cport'], $target_detail['tport'], $client_fd);
				$this->_gtable->del($client_fd);
				$proxy_server->close($client_fd);
			}
		}
	}

	public function proxy_client_connect(swoole_server $proxy_server, $client_fd, $from_reactor_id) {
		$client_info = $proxy_server->connection_info($client_fd);

		$this->log('client_connect|%s,%s', $client_fd, json_encode($client_info));

		$client_from_port = $client_info['server_port'];
		$client_target_port = $this->_conf['proxy'][$client_from_port][0];

		$this->log('client_connect|%s,src_port=%s,dest_port=%s', $client_fd, $client_from_port, $client_target_port);

		$target_fd = -1;
		//尝试等待
		$loop = 1;
		while ($loop <= 3) {
			foreach ($this->_gtable as $name => $info) {
				if ($info['status'] == 0
					&& $info['type'] == 't'
					&& $info['tport'] == $client_target_port) {
					//target
					$this->_gtable->incr($name, 'status', 1);
					$this->_gtable->incr($name, 'cfd', $client_fd);
					$this->_gtable->incr($name, 'cport', $client_from_port);
					//
					$target_fd = $info['tfd'];
					//client
					$this->_gtable->set($client_fd, [
						'type' => 'c',
						'ip' => $client_info['remote_ip'],
						'tfd' => $info['tfd'],
						'cfd' => $client_fd,
						'status' => 1,
						'cport' => $client_from_port,
						'tport' => $client_target_port,
					]);
					break 2;
				}
			}
			sleep(1);
			$loop++;
		}

		if (false == $this->_gtable->exist($client_fd)) {
			$this->log('client_connect|%s,force_close', $client_fd);
			$proxy_server->close($client_fd);
		} else {
			$this->log('client_connect|%s,found_target,%s', $client_fd, $target_fd);
		}
	}

	public function proxy_target_connect(swoole_server $proxy_target, $target_fd, $from_reactor_id) {
		$target_info = $proxy_target->connection_info($target_fd);

		$this->log('target_connect|%s,%s', $target_fd, json_encode($target_info));

		$this->_gtable->set($target_fd, [
			'type' => 't',
			'ip' => $target_info['remote_ip'],
			'tfd' => $target_fd,
			'cfd' => 0,
			'status' => 0,
			'cport' => 0,
			'tport' => $target_info['server_port'],
		]);
	}

	public function proxy_client_receive(swoole_server $proxy_server, $client_fd, $from_reactor_id, $data) {
		$this->log('client_receive|%s', $client_fd);

		$client_detail = $this->_gtable->get($client_fd);

		if ($client_detail) {
			$target_fd = $client_detail['tfd'];
			if ($client_detail['status'] > 0
				&& $proxy_server->exist($target_fd)) {
				$this->log('client_receive|%s,cport=%s,tport=%s,requestto,%s', $client_fd, $client_detail['cport'], $client_detail['tport'], $target_fd);
				//HTTP
				if ($this->_conf['proxy'][$client_detail['cport']][1]) {
					$data = preg_replace("/Connection: keep-alive\r\n/", "Connection: Close\r\nX-REAL-IP: " . $client_detail['ip'] . "\r\n", $data, 1);
				}
				$result = rc4($this->argv[2], $data);

				//var_dump(openssl_error_string());
				$proxy_server->send($target_fd, $result);
			} else {
				$this->log('client_receive|%s,not_found_target,%s', $client_fd, $target_fd);
			}
		} else {
			$this->log('client_receive|%s,not_found_target', $client_fd);
		}
	}

	public function proxy_target_receive(swoole_server $proxy_server, $target_fd, $from_reactor_id, $data) {
		$this->log('target_receive|%s', $target_fd);
		$target_detail = $this->_gtable->get($target_fd);

		if ($target_detail) {
			$client_fd = $target_detail['cfd'];
			if ($target_detail['status'] > 0
				&& $proxy_server->exist($client_fd)) {
				$this->log('target_receive|%s,cport=%s,tport=%s,responseto,%s', $target_fd, $target_detail['cport'], $target_detail['tport'], $client_fd);
				//print_r($data);
				$proxy_server->send($client_fd, rc4($this->argv[2], $data));
			} else {
				$this->log('target_receive|%s,not_found_client,%s', $target_fd, $client_fd);
			}
		} else {
			$this->log('target_receive|%s,not_found_client', $target_fd);
		}
	}

	public function run($name) {
		$this->_conf['file_name'] = $name;

		$this->_gtable = new swoole_table(1024);
		$this->_gtable->column('type', swoole_table::TYPE_STRING, 10);
		$this->_gtable->column('ip', swoole_table::TYPE_STRING, 15);
		$this->_gtable->column('cfd', swoole_table::TYPE_INT);
		$this->_gtable->column('tfd', swoole_table::TYPE_INT);
		$this->_gtable->column('status', swoole_table::TYPE_INT);
		$this->_gtable->column('cport', swoole_table::TYPE_INT);
		$this->_gtable->column('tport', swoole_table::TYPE_INT);
		$this->_gtable->create();

		$bind_port = [
			$this->_conf['port'],
		];
		$this->_proxy_server = new swoole_server($this->_conf['host'], $this->_conf['port']);

		foreach ($this->_conf['proxy'] as $open_port => $local_port) {
			if (!in_array($open_port, $bind_port)) {
				$bind_port[] = $open_port;

				$client_server_handle = $this->_proxy_server->listen($this->_conf['host'], $open_port, SWOOLE_SOCK_TCP);
				$client_server_handle->on('close', array($this, 'proxy_client_close'));
				$client_server_handle->on('connect', array($this, 'proxy_client_connect'));
				$client_server_handle->on('receive', array($this, 'proxy_client_receive'));
			}

			if (!in_array($local_port[0], $bind_port)) {
				$bind_port[] = $local_port[0];

				$target_server_handle = $this->_proxy_server->listen($this->_conf['host'], $local_port[0], SWOOLE_SOCK_TCP);
				$target_server_handle->on('close', [$this, 'proxy_target_close']);
				$target_server_handle->on('connect', [$this, 'proxy_target_connect']);
				$target_server_handle->on('receive', [$this, 'proxy_target_receive']);
			}
		}

		$this->_proxy_server->set($this->_setting);

		$this->_proxy_server->on('start', array($this, 'proxy_master_start'));
		$this->_proxy_server->on('managerStart', array($this, 'proxy_manager_start'));
		$this->_proxy_server->on('shutDown', array($this, 'proxy_shutdown'));
		$this->_proxy_server->on('workerStart', array($this, 'proxy_worker_start'));
		$this->_proxy_server->on('workerStop', array($this, 'proxy_worker_stop'));
		$this->_proxy_server->on('workerError', array($this, 'proxy_worker_error'));

		$this->_proxy_server->on('close', array($this, 'proxy_client_close'));
		$this->_proxy_server->on('connect', array($this, 'proxy_client_connect'));
		$this->_proxy_server->on('receive', array($this, 'proxy_client_receive'));

		$this->_proxy_server->start();
	}
}

(new SProxyServer($argv))->run($argv[0]);