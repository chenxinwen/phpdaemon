<?php
namespace PHPDaemon\SockJS\Methods;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;
use PHPDaemon\Exceptions\UndefinedMethodCalled;

/**
 * Contains some base methods
 *
 * @package Libraries
 * @subpackage SockJS
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */

abstract class Generic extends \PHPDaemon\HTTPRequest\Generic {
	protected $stage = 0;
	protected $sessId;
	protected $serverId;
	protected $path;
	protected $heartbeatTimer;

	protected $opts;

	protected $stopped = false;
	
	protected $callbackParamEnabled = false;
	protected $frames = [];

	protected $allowedMethods = 'GET';

	protected $errors = [
		1002 => 'Connection interrupted',
		2010 => 'Another connection still open',
		3000 => 'Go away!',
	];

	protected $pollMode = ['stream'];

	protected $gcEnabled = true;

	protected $cacheable = false;
	protected $poll = false;

	protected $bytesSent = 0;
	protected $gc = false;
	

	public function init() {
		$this->sessId = $this->attrs->sessId;
		D([$this->attrs->server['REQUEST_URI'], 'sessId' => $this->sessId]);
		$this->serverId = $this->attrs->serverId;
		$this->path = $this->attrs->path;

		// @TODO: revert timeout after request
		$this->upstream->setTimeouts($this->appInstance->config->networktimeoutread->value, $this->appInstance->config->networktimeoutwrite->value);

		$this->opts = $this->appInstance->getRouteOptions($this->attrs->path);
		$this->CORS();
		if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
			$this->header('204 No Content');
			$this->header('Cache-Control: max-age=31536000, public, pre-check=0, post-check=0');
			$this->header('Access-Control-Max-Age: 31536000');
			$this->header('Access-Control-Allow-Methods: OPTIONS, '.$this->allowedMethods);
			$this->header('Expires: '.date('r', strtotime('+1 year')));
			$this->finish();
			return;
		}
		if ($this->opts['cookie_needed'] && !$this instanceof Info) {
			if (isset($_COOKIE['JSESSIONID'])) {
				$val = $_COOKIE['JSESSIONID'];
				if (!is_string($val) || $val === '') {
					$val = 'dummy';
				}
			} else {
				$val = 'dummy';
			}
			$this->setcookie('JSESSIONID', $val, 0, '/');
			//D(['uri' => $_SERVER['REQUEST_URI'], 'cookie' => &$_COOKIE['JSESSIONID'], 'headers' => &$this->headers['SET_COOKIE_1']]);
		}
		$this->contentType($this->contentType);
		if (!$this->cacheable) {
			$this->noncache();
		}
		if ($this->callbackParamEnabled) {
			if (!isset($_GET['c']) || !is_string($_GET['c']) || preg_match('~[^_\.a-zA-Z0-9]~', $_GET['c'])) {
				$this->header('500 Internal Server Error');
				$this->out('"callback" parameter required');
				$this->finish();
				return;
			}
		}
		if (($f = $this->appInstance->config->heartbeatinterval->value) > 0) {
			$this->heartbeatTimer = setTimeout(function($timer) {
				$this->sendFrame('h');
				if ($this->gcEnabled) {
					$this->gcCheck();
				}
			}, $f * 1e6);
		}
		$this->afterHeaders();
		if ($this->poll) {
			$this->acquire(function() {
				$this->poll();
			});
		}
	}

	public function afterHeaders() {
	}

	/**
	 * Output some data
	 * @param string $s String to out
	 * @param bool $flush
	 * @return boolean Success
	 */
	public function outputFrame($s, $flush = true) {
		$this->bytesSent += strlen($s);
		return parent::out($s, $flush);
	}

	public function gcCheck() {
		$max = $this->appInstance->config->gcmaxresponsesize->value;
		if ($max > 0 && !$this->gc && $this->bytesSent > $max) {
			$this->gc = true;
			$this->appInstance->unsubscribe('s2c:' . $this->sessId, [$this, 's2c'], function($redis) {
				$this->finish();
			});
		}
	}

	/**
	 * Output some data
	 * @param string $s String to out
	 * @param bool $flush
	 * @return boolean Success
	 */
	public function out($s, $flush = true) {
		if ($this->heartbeatTimer !== null) {
			Timer::setTimeout($this->heartbeatTimer);
		}
		return parent::out($s, $flush);;
	}


	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		$this->sleep(30);
	}


	public function w8in() {}
	
	public function s2c($redis) {
		if (!$redis) {
			return;
		}
		list (, $chan, $msg) = $redis->result;
		$frames = json_decode($msg, true);
		if (!is_array($frames) || !sizeof($frames)) {
			return;
		}
		foreach ($frames as $frame) {
			$this->sendFrame($frame);
		}
		if (!in_array('stream', $this->pollMode)) {
			$this->stop();
		}
		if ($this->gcEnabled) {
			$this->gcCheck();
		}
	}

	public function stop() {
		if ($this->stopped) {
			return;
		}
		$this->stopped = true;
		if (in_array('one-by-one', $this->pollMode)) {
			$this->stop();
		}
		$this->appInstance->unsubscribeReal('s2c:' . $this->sessId, function($redis) {
			foreach ($this->frames as $frame) {
				$this->sendFrame($frame);
			}
			$this->finish();
		});
	}


	public function onFinish() {
		$this->appInstance->unsubscribe('s2c:' . $this->sessId, [$this, 's2c']);
		$this->appInstance->unsubscribe('w8in:' . $this->sessId, [$this, 'w8in']);
		Timer::remove($this->heartbeatTimer);
		parent::onFinish();
	}

	protected function poll($cb = null) {
		$this->appInstance->subscribe('s2c:' . $this->sessId, [$this, 's2c'], function($redis) use ($cb) {
			$this->appInstance->publish('poll:' . $this->sessId, json_encode($this->pollMode), function($redis) use ($cb) {
				if (!$redis) {
					$cb === null || call_user_func($cb);
					return;
				}
				if ($redis->result > 0) {
					$cb === null || call_user_func($cb);
					return;
				}
				$this->appInstance->setnx('sess:' . $this->sessId, '', function($redis) use ($cb) {
					if (!$redis || $redis->result === 0) {
						$this->error(3000);
						$cb === null || call_user_func($cb);
						return;
					}
					$this->appInstance->expire('sess:' . $this->sessId, $this->appInstance->config->deadsessiontimeout->value, function($redis) use ($cb) {
						if (!$redis || $redis->result === 0) {
							$this->error(3000);
							$cb === null || call_user_func($cb);
							return;
						}
						$this->appInstance->subscribe('state:' . $this->sessId, function($redis) use ($cb) {
							if (!$redis) {
								return;
							}
							list (, $chan, $state) = $redis->result;
							if ($state === 'started') {
								$this->sendFrame('o');
								if (!in_array('stream', $this->pollMode)) {
									$this->finish();
									return;
								}
							}
							$this->appInstance->publish('poll:' . $this->sessId, json_encode($this->pollMode), function($redis) use ($cb) {
								if (!$redis || $redis->result === 0) {
									$this->error(3000);
									$cb === null || call_user_func($cb);
									return;
								}
								$cb === null || call_user_func($cb);
							});
						}, function ($redis) use ($cb) {
							if (!$this->appInstance->beginSession($this->path, $this->sessId, $this->attrs->server)) {
								$this->header('404 Not Found');
								$this->finish();
								$this->unsubscribeReal('state:' . $this->sessId);
							}
							$cb === null || call_user_func($cb);
						});
					});
				});
			});
		});
	}
	
	protected function acquire($cb) {
		$this->appInstance->getkey('error:' . $this->sessId, function($redis) use ($cb) {
			if (!$redis) {
				$this->error(3000);
				return;
			}
			if ($redis->result !== null) {
				$this->error((int) $redis->result);
				return;
			}
			if ($this->appInstance->getLocalSubscribersCount('w8in:' . $this->sessId) > 0) {
				$this->anotherConnectionStillOpen();
				return;
			}
			$this->appInstance->publish('w8in:' . $this->sessId, '', function($redis) use ($cb) {
				if ($redis->result > 0) {
					$this->anotherConnectionStillOpen();
					return;
				}
				$this->appInstance->subscribe('w8in:' . $this->sessId, [$this, 'w8in'], function($redis) use ($cb) {
					if ($this->appInstance->getLocalSubscribersCount('w8in:' . $this->sessId) > 1) {
						$this->anotherConnectionStillOpen();
						return;
					}
					$this->appInstance->publish('w8in:' . $this->sessId, '', function($redis) use ($cb) {
						if ($redis->result > 1) {
							$this->anotherConnectionStillOpen();
							return;
						}
						call_user_func($cb);
					});
				});
			});
		});
	}

	protected function anotherConnectionStillOpen() {
		$this->appInstance->setkey('error:' . $this->sessId, 1002, function() {
			$this->appInstance->expire('error:' . $this->sessId, $this->appInstance->config->deadsessiontimeout->value, function() {
				$this->error(2010);
			});
		});
	}

	protected function error($code) {
		$this->sendFrame('c' . json_encode([$code, isset($this->errors[$code]) ? $this->errors[$code] : null]));
	}

	protected function sendFrame($frame) {
		if (substr($frame, 0, 1) === 'c') {
			$this->finish();
		}		
	}

	protected function contentType($type) {
		$this->header('Content-Type: '.$type.'; charset=UTF-8');
	}
	protected function noncache() {
		$this->header('Pragma: no-cache');
		$this->header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	}

	protected function CORS() {
		if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
			$this->header('Access-Control-Allow-Headers: '.$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']);
		}
		if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== 'null') {
			$this->header('Access-Control-Allow-Origin:' . $_SERVER['HTTP_ORIGIN']);
		} else {
			$this->header('Access-Control-Allow-Origin: *');
		}
		$this->header('Access-Control-Allow-Credentials: true');
	}
}
