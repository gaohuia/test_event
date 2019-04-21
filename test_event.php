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
	private $transferEncoding;

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

		$context = null;

		if ($parse['scheme'] == 'https') {
			$protocol = 'tls';
			$defaultPort = 443;

			$context = stream_context_create([
				'ssl' => [
					'verify_peer' => false,
					'verify_peer_name' => false,
				]
			]);
		} else {
			$protocol = 'tcp';
			$defaultPort = 80;

			$context = stream_context_create();
		}

		$port = $parse['port'] ?? $defaultPort;

		$this->stream = stream_socket_client(
			"{$protocol}://{$host}:{$port}",
			$errorno,
			$errorstr,
			30,
			STREAM_CLIENT_ASYNC_CONNECT,
			$context
		);
		
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
			"Accept-Encoding: deflate",
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

	function decodeChunks()
	{
		$body = "";

		$pos = strlen($this->header);
		while ($pos < strlen($this->buffer) && ($lineEnd = strpos($this->buffer, "\r\n", $pos)) !== false) {
			$data = substr($this->buffer, $pos, $lineEnd - $pos);

			$chunkSize = intval($data, 16);

			if ($chunkSize == 0) {
				return $body;
			}

			$pos = $lineEnd;
			$pos += 2;				// \r\n after the chunk size


			$body .= substr($this->buffer, $pos, $chunkSize);

			$pos += $chunkSize;		// the chunk
			$pos += 2;				// \r\n after the chunk
		}

		return false;
	}

	function onData($fd) {
		$this->readAll();

		if (empty($this->header)) {
			if (($pos = strpos($this->buffer, "\r\n\r\n")) !== false) {
				$this->header = substr($this->buffer, 0, $pos + 4);
				echo $this->header, "\n";

				$contentLengthPattern = "#Content-Length: (\d+)#";
				$transferEncodingPattern = "#Transfer-Encoding: (.*)\r\n#";
				if (preg_match($contentLengthPattern, $this->header, $match)) {
					$this->contentLength = intval($match[1]);
				} else if (preg_match($transferEncodingPattern, $this->header, $match)) {
					$this->transferEncoding = trim($match[1]);
				}
			}
		}

		if (-1 != $this->contentLength && strlen($this->buffer) >= strlen($this->header) + $this->contentLength) {
			$this->body = substr($this->buffer, strlen($this->header));
			$this->onFinish();
			return false;
		}

		if ($this->transferEncoding == 'chunked') {
			if (false !== ($body = $this->decodeChunks())) {
				$this->body = $body;
				$this->onFinish();
				return false;
			}
		}

		return true;
	}

	function onFinish()
	{
		file_put_contents($this->downloadTo, $this->body);
		stream_socket_shutdown($this->stream, STREAM_SHUT_WR);
	}
}

new Http("https://www.php.net/manual/zh/function.stream-socket-shutdown.php", "function.stream-socket-shutdown.html");
new Http("https://www.php.net/manual/zh/function.stream-context-create.php", "function.stream-context-create.html");
new Http("https://www.php.net/manual/zh/context.php", "context.html");
new Http("https://www.php.net/manual/zh/context.ssl.php", "context.ssl.html");
new Http("https://github.com/", "github.html");

new Http("http://www.zeroplace.cn/", "1.html");


$base->loop();

