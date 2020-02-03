<?php
// 利用http服务器演示协程 + 非阻塞IO进行有效调度,性能高于http_server.php例子,因为http_server.php例子里面要处理完一个请求才能进行下个accept

require __DIR__ . '/../vendor/autoload.php';

use Bobby\Coroutine\Coroutine;
use Bobby\Coroutine\CoroutineScheduler as Scheduler;
use Bobby\Coroutine\CoroutineInterrupter;


class CoroutineHttpServer 
{
    protected $reads = [];

    protected $writes = [];

    public function registerReadEvent($socket)
    {
        return new CoroutineInterrupter(function (Scheduler $scheduler, Coroutine $coroutine) use($socket) {
            if (!isset($this->reads[(int)$socket]))
                $this->reads[(int)$socket] = [$socket, [$coroutine]];
            else 
                $this->reads[(int)$socket][1][] = $coroutine;
        });
    }

    public function registerWriteEvent($socket)
    {
        return new CoroutineInterrupter(function (Scheduler $scheduler, Coroutine $coroutine) use ($socket) {
            if (!isset($this->reads[(int)$socket]))
                $this->writes[(int)$socket] = [$socket, [$coroutine]];
            else 
                $this->writes[(int)$socket][1][] = $coroutine;
        });
    }

    public function run(string $host, int $port)
    {
        yield new CoroutineInterrupter(function (Scheduler $scheduler, Coroutine $coroutine) use ($host, $port) {
            $scheduler->newCoroutine($this->requestHandle($host, $port));
            $scheduler->newCoroutine($this->poll($scheduler));
        });
    }

    public function poll(Scheduler $scheduler)
    {
        if (empty($this->reads) && empty($this->writes)) {
            return $scheduler->newCoroutine($this->poll($scheduler));
        }

        $waitForReads = [];
        foreach ($this->reads as list($socket)) {
            $waitForReads[] = $socket;
        }

        $waitForWrites = [];
        foreach ($this->writes as list($socket)) {
            $waitForWrites[] = $socket;
        }

        if (!stream_select($waitForReads, $waitForWrites, $exceptions, $scheduler->isEmpty()? null: 0)) {
            return $scheduler->newCoroutine($this->poll($scheduler));
        }

        $rwCoroutines = [];
        foreach ($waitForReads as $read) {
            list(, $readCoroutines) = $this->reads[(int)$read];
            unset($this->reads[(int)$read]);
            $rwCoroutines = array_merge($rwCoroutines, $readCoroutines);
        }

        foreach ($waitForWrites as $write) {
            list(, $writeCoroutines) = $this->writes[(int)$write];
            unset($this->writes[(int)$write]);
            $rwCoroutines = array_merge($rwCoroutines, $writeCoroutines);
        }

        foreach ($rwCoroutines as $rwCoroutine) {
            $scheduler->schedule($rwCoroutine);
        }

        yield $scheduler->newCoroutine($this->poll($scheduler));
    }

    public function requestHandle(string $host, int $port)
    {
        if (!$socket = stream_socket_server("tcp://$host:$port", $errno, $errstr)) {
            throw new \Exception($errstr, $errno);
        }

        while (1) {
            yield $this->registerReadEvent($socket);

            yield new CoroutineInterrupter(function (Scheduler $scheduler, Coroutine $coroutine) use ($socket) {
                try {
                    $scheduler->newCoroutine($this->response(stream_socket_accept($socket)));
                    $scheduler->schedule($coroutine);
                } catch (Throwable $e) {
                    die($e);
                }
            });
        }
    }

    protected function response($socket)
    {
        yield $this->registerReadEvent($socket);
        stream_set_blocking($socket, 0);
        $data = stream_get_contents($socket);
        $content = "Received following request:\n\n$data";
        yield $this->registerWriteEvent($socket);
        $response = <<<STR
HTTP/1.1 200 OK\r
Content-Type: text/html\r
Connection: close\r
\r
$content      
STR;
        fwrite($socket, $response);
        fclose($socket);
    }
}

($scheduler = new Scheduler)->newCoroutine(($httpServer = new CoroutineHttpServer)->run('0.0.0.0', 9001));
$scheduler->run();