<?php

class EventLoop {
	private static $eventBase;

	public static function loop() {
		if (static::$eventBase) {
			static::$eventBase->loop();
		}
	}

	public static function getEventBase()
	{
		if (!static::$eventBase) {
			static::$eventBase = new EventBase();
		}
		return static::$eventBase;
	}
}


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

		if ($parse['scheme'] == 'https') {
			$defaultPort = 443;
		} else {
			$defaultPort = 80;
		}

		$port = $parse['port'] ?? $defaultPort;
		$this->port = $port;

		$this->dns = new EventDnsBase(EventLoop::getEventBase(), true);

		if ($parse['scheme'] == 'https') {
			$this->initConnectionTLS();
		} else {
			$this->initConnection();
		}

		$this->event->setTimeouts(5, 5);
		$this->event->setCallbacks([$this, 'onData'], [$this, 'onWrite'], [$this, 'onEvent']);
		$this->event->enable(Event::READ | Event::WRITE);
		if (!$this->event->connectHost($this->dns, $this->host, $this->port)) {
			echo "Connection failed: " . $this->host;
			return ;
		}

		$this->sendRequest();
	}

	private function initConnectionTLS()
	{
		$context = new EventSslContext(
			EventSslContext::TLS_CLIENT_METHOD, [
				EventSslContext::OPT_CA_FILE => __DIR__ . '/cacert.pem',
				EventSslContext::OPT_VERIFY_PEER => true,
				EventSslContext::OPT_ALLOW_SELF_SIGNED => false,
			]
		);

		$this->event = EventBufferEvent::sslSocket(
			EventLoop::getEventBase(), null, $context, EventBufferEvent::SSL_CONNECTING
		);

	}

	private function initConnection()
	{
		$this->event = new EventBufferEvent(EventLoop::getEventBase());
	}

	public function onEvent($bev, $event)
	{
		if ($event & EventBufferEvent::ERROR) {
			echo "Dns Error:" . $bev->getDnsErrorString();
			echo "\n";
			echo "Ssl Error:" . $bev->sslError();
			echo "\n";
			$this->onFinish();
			return ;
		}

		if ($event & EventBufferEvent::TIMEOUT) {
			echo "Timeout\n";
			$this->onFinish();
			return ;
		}

		if ($event & EventBufferEvent::CONNECTED) {
			echo "Connected\n";
			printf("sslGetCipherName: %s\n", $this->event->sslGetCipherName());
			printf("sslGetCipherInfo: %s\n", $this->event->sslGetCipherInfo());
			printf("sslGetCipherVersion: %s\n", $this->event->sslGetCipherVersion());

			return ;
		}

		printf("onEvent: %d\n", $event);
		return ;
	}

	public function onWrite()
	{
		$this->write();
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

		$this->write();
	}

	private function write()
	{
		while ($this->sendPos < strlen($this->sendBuffer)) {
			$data = substr($this->sendBuffer, $this->sendPos, $this->chunkSize);
			if (!$this->event->getOutput()->add($data)) {
				printf("write failed\n");
				return ;
			}
			$this->sendPos += strlen($data);
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
		$data = $this->event->getInput()->read($this->chunkSize);
		$this->appendBuffer($data);
		while (strlen($data) == $this->chunkSize) {
			$data = $this->event->getInput()->read($this->chunkSize);
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

	function onData() {
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
		echo "request: {$this->url} completed\n";


		$this->event->disable(Event::READ | Event::WRITE);
		$this->event->free();
		unset($this->event);
		unset($this->dns);

		file_put_contents($this->downloadTo, $this->body);
	}
}

new Http("https://www.php.net/manual/zh/function.stream-socket-shutdown.php", "function.stream-socket-shutdown.html");
new Http("https://www.php.net/manual/zh/function.stream-context-create.php", "function.stream-context-create.html");
new Http("https://www.php.net/manual/zh/context.php", "context.html");
new Http("https://www.php.net/manual/zh/context.ssl.php", "context.ssl.html");
// new Http("https://github.com/", "github.html");
// new Http("https://www.google.com/", "1.html");
new Http("https://www.baidu.com/", "2.html");

EventLoop::loop();
