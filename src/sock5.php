<?php
namespace Liubinzh\Sock5;

// 状态相关
define('STAGE_INIT', 0);
define('STAGE_ADDR', 1);
define('STAGE_UDP_ASSOC', 2);
define('STAGE_DNS', 3);
define('STAGE_CONNECTING', 4);
define('STAGE_STREAM', 5);
define('STAGE_DESTROYED', -1);
// 命令
define('CMD_CONNECT', 1);
define('CMD_BIND', 2);
define('CMD_UDP_ASSOCIATE', 3);

// 请求地址类型
define('ADDRTYPE_IPV4', 1);
define('ADDRTYPE_IPV6', 4);
define('ADDRTYPE_HOST', 3);

class Sock5Server{
	protected $serv = array();
	// 前端
	protected $frontends;
	// 后端
	protected $backends;
	// logger
	protected $logger;
	// config
	protected $config;

	public function __construct(){
		$this->config =  array('daemon'=>false, 'host'=>'0.0.0.0', 'port'=>1080);
		$argv = getopt('c:d');
		if(isset($argv['d'])){
			$this->config['daemon'] = true;
		}
		$config = empty($argv['c']) ? '' : getcwd() . '/' . $argv['c'];
		if($config){
			if (!file_exists($config)){
				throw new \Exception('config file is not exists');
			}
			$config = file_get_contents($config);
			if($config){
				$config = json_decode($config, true);
			}
		}
		if(is_array($config)){
			$this->config = array_merge($this->config, $config);
		}
		$this->serv = new \swoole_server($this->config['host'], $this->config['port'],
			SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
		$this->serv->on('connect', [$this,'onConnect']);
		$this->serv->on('receive', [$this,'onReceive']);
		$this->serv->on('close', [$this,'onClose']);
		$this->logger = new \Katzgrau\KLogger\Logger(getcwd().'/logs');
	}
	protected function onConnect($serv, $fd){
		// 设置当前连接的状态为STAGE_INIT，初始状态
		if(!isset($this->frontends[$fd])){
			$this->frontends[$fd]['stage'] = STAGE_INIT;
		}
	}
	protected function onReceive($serv, $fd, $from_id, $data){

		$connection = isset($this->frontends[$fd]) ? $this->frontends[$fd] : false;
		if(!$connection){
			$this->frontends[$fd]['stage'] = STAGE_INIT;
		}
		switch ($this->frontends[$fd]['stage']){
			case STAGE_INIT:
				//与客户端建立SOCKS5连接
				//参见: https://www.ietf.org/rfc/rfc1928.txt
				$serv->send($fd, "\x05\x00");
				$this->frontends[$fd]['stage'] = STAGE_ADDR;
				break;
			case STAGE_ADDR:
				$cmd = ord($data[1]);
				//仅处理客户端的TCP连接请求
				if($cmd != CMD_CONNECT){
					$this->logger->error('unsupport cmd');
					$serv->send($fd, "\x05\x07\x00\x01");
					return $this->serv->close($fd);
				}
				$header = $this->parse_socket5_header($data);
				if(!$header){
					$serv->send($fd, "\x05\x08\x00\x01");
					return $this->serv->close($fd);
				}
				//尚未建立连接
				if (!isset($this->frontends[$fd]['socket'])){
					$this->frontends[$fd]['stage'] = STAGE_CONNECTING;
					//连接到后台服务器
					$socket = new \swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
					$socket->closing = false;
					$socket->on('connect', function (\swoole_client $socket) use ($data, $fd){
						$this->logger->debug("onConnect: backend[{$socket->sock}]");
						$this->backends[$socket->sock] = $fd;
						$this->frontends[$fd]['socket'] = $socket;
						$this->frontends[$fd]['stage'] = STAGE_STREAM;
						$buf_replies = "\x05\x00\x00\x01\x00\x00\x00\x00". pack('n', 8888);
						$this->serv->send($fd, $buf_replies);
					});
					$socket->on('error', function (\swoole_client $socket) use ($fd){
						$this->logger->error("connect to backend server failed");
						$this->serv->send($fd, "backend server not connected. please try reconnect.");
						$this->serv->close($fd);
					});
					$socket->on('close', function (\swoole_client $socket) use ($fd){
						unset($this->backends[$socket->sock]);
						unset($this->frontends[$fd]);
						if (!$socket->closing){
							$this->serv->close($fd);
						}
					});
					$socket->on('receive', function (\swoole_client $socket, $_data) use ($fd){
						$this->serv->send($fd, $_data);
					});

					if($header[0] == ADDRTYPE_HOST){
						\swoole_async_dns_lookup($header[1], function ($host, $ip) use($header, $socket, $fd){
							$connection_info = $this->serv->connection_info($fd);
							$this->logger->info("connecting {$host}:{$header[2]} from {$connection_info['remote_ip']}:{$connection_info['remote_port']}");
							$socket->connect($ip, $header[2]);
						});
					}elseif($header[0] == ADDRTYPE_IPV4){
						$socket->connect($header[1], $header[2]);
					}else{
						$serv->send($fd, "\x05\x08\x00\x01");
						return $this->serv->close($fd);
					}
				}
				break;
			case STAGE_STREAM:
				if (isset($this->frontends[$fd]['socket'])){
					$socket = $this->frontends[$fd]['socket'];
					$socket->send($data);
				}
				break;
			default:break;
		}
	}
	protected function onClose($serv, $fd, $from_id){
		//清理掉后端连接
		if (isset($this->frontends[$fd]['socket'])){
			$backend_socket = $this->frontends[$fd]['socket'];
			$backend_socket->closing = true;
			$backend_socket->close();
			unset($this->backends[$backend_socket->sock]);
			unset($this->frontends[$fd]);
		}
		$this->logger->debug("onClose: frontend[$fd]");
	}
	public function start(){
		$default = ['daemonize' => $this->config['daemon'],
			'timeout' => 1,
			'poll_thread_num' => 1,
			'worker_num' => 1,
			'backlog' => 128,
			'dispatch_mode' => 2,
			'log_file' => './swoole.log'
		];
		$this->serv->set($default);
		$this->serv->start();
	}
	/**
	 * 解析客户端发来的socket5头部数据
	 * @param string $buffer
	 */
	protected function parse_socket5_header($buffer){
		$buffer = substr($buffer, 3);
		$addr_type = ord($buffer[0]);
		switch($addr_type){
			case ADDRTYPE_IPV4:
				$dest_addr = ord($buffer[1]).'.'.ord($buffer[2]).'.'.ord($buffer[3]).'.'.ord($buffer[4]);
				$port_data = unpack('n', substr($buffer, 5, 2));
				$dest_port = $port_data[1];
				$header_length = 7;
				break;
			case ADDRTYPE_HOST:
				$addrlen = ord($buffer[1]);
				$dest_addr = substr($buffer, 2, $addrlen);
				$port_data = unpack('n', substr($buffer, 2 + $addrlen, 2));
				$dest_port = $port_data[1];
				$header_length = $addrlen + 4;
				break;
			case ADDRTYPE_IPV6:
				$this->logger->error('todo ipv6 not support yet');
				return false;
			default:
				$this->logger->error("unsupported addrtype $addr_type");
				return false;
		}
		return array($addr_type, $dest_addr, $dest_port, $header_length);
	}
}