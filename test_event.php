<?php

$base = new EventBase();

class Http {
	private $url;
	private $event;
	private $host;
	private $uri;

	private $chunkSize = 1024;

	private $buffer = "";
	private $header = "";
	private $body = "";
	private $contentLength = -1;

	private $downloadTo;
	private $sendBuffer;
	private $sendPos = 0;

	public function __construct($url, $downloadTo) {
		global $base;
		$this->url = $url;
		$this->downloadTo = $downloadTo;

		$parse = parse_url($this->url);
		$host = $parse['host'];
		$port = $parse['port'] ?? 80;
		$uri = $parse['path'];
		if (!empty($parse['query'])) {
			$uri .= '?' . $parse['query'];
		}

		$this->host = $host;
		$this->uri = $uri;

		$this->stream = stream_socket_client("tcp://{$host}:{$port}");
		stream_set_blocking($this->stream, 0);
		stream_set_write_buffer($this->stream, 0);
		stream_set_read_buffer($this->stream, 0);

		$this->sendRequest();

		$this->event = new Event($base, $this->stream, Event::READ | Event::WRITE, [$this, 'callback']);
		$this->event->add();
	}

	private function sendRequest()
	{
		$http = [
			"GET {$this->uri} HTTP/1.1",
			"Host: {$this->host}",
			"Connection: keep-alive",
		];

		$header = implode("\r\n", $http);
		$this->sendBuffer = $header . "\r\n\r\n";
		$this->sendPos = 0;
	}

	public function callback($fd, $mask)
	{
		global $base;

		$flag = Event::READ;

		if (($mask & Event::READ) != 0) {
			if (!$this->onData($fd)) {
				$flag &= ~Event::READ;
			}
		}

		if (($mask & Event::WRITE) != 0) {
			$this->onSend($fd);
		}

		if (strlen($this->sendBuffer) > $this->sendPos) {
			$flag |= Event::WRITE;
		}

		if ($flag) {
			$this->event->set($base, $this->stream, $flag, [$this, 'callback']);
			$this->event->add();
		}
	}

	private function onSend()
	{
		$count = fwrite($this->stream, substr($this->sendBuffer, $this->sendPos));

		$this->sendPos += $count;
		if($this->sendPos < strlen($this->sendBuffer)) {
			return true;
		} else {
			return false;
		}
	}

	private function appendBuffer($chunk)
	{
		$this->buffer .= $chunk;
	}

	private function readAll()
	{
		$data = fread($this->stream, $this->chunkSize);
		$this->appendBuffer($data);
		while (strlen($data) == $this->chunkSize) {
			$data = fread($this->stream, $this->chunkSize);
			$this->appendBuffer($data);
		}
	}

	function onData($fd) {
		$this->readAll();

		if (empty($this->header)) {
			if (($pos = strpos($this->buffer, "\r\n\r\n")) !== false) {
				$this->header = substr($this->buffer, 0, $pos + 4);
				echo $this->header, "\n";

				$pattern = "#Content-Length: (\d+)#";
				if (preg_match($pattern, $this->header, $match)) {
					$this->contentLength = intval($match[1]);
				}
			}
		}

		if (-1 != $this->contentLength && strlen($this->buffer) >= strlen($this->header) + $this->contentLength) {
			$this->body = substr($this->buffer, strlen($this->header));
			$this->onFinish();
			return false;
		}

		return true;
	}

	function onFinish()
	{
		file_put_contents($this->downloadTo, $this->body);
		stream_socket_shutdown($this->stream, STREAM_SHUT_WR);
	}
}

new Http("http://d.hiphotos.baidu.com/image/pic/item/0b7b02087bf40ad1719cf19f592c11dfa9ecce55.jpg", "1.jpg");
new Http("http://www.zeroplace.cn/", "1.html");


$base->loop();

