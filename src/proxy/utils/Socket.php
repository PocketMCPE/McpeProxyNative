<?php

namespace proxy\utils;

use bedwars\Loader;
use proxy\Proxy;

class Socket
{

    private $address;
    private $port;

    private $socket;

    private static $socketPool = [];
    private static $eventPool = [];

    public function __construct(string $address, int $port)
    {
        $this->address = $address;
        $this->port = $port;
        $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        Socket::addSocketPool($this);
    }

    public static function init()
    {
        self::handleEvents();
    }

    public function getSocket()
    {
        return $this->socket;
    }

    public function setNonblock()
    {
        return socket_set_nonblock($this->socket);
    }

    public function getHash()
    {
        return $this->address . ':' . $this->port;
    }

    public function listen($adress, $port)
    {
        return socket_connect($this->socket, $adress, $port);
    }

    public function write($buffer, $dest, $port)
    {
        return socket_sendto($this->socket, $buffer, strlen($buffer), 0, $dest, $port);
    }

    public function bind()
    {
        if (@socket_bind($this->socket, $this->address, $this->port) === true) {
            @socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 0);
            @socket_set_option($this->socket, SOL_SOCKET, SO_SNDBUF, 1024 * 1024 * 8);
            @socket_set_option($this->socket, SOL_SOCKET, SO_RCVBUF, 1024 * 1024 * 8);
        } else {
            var_dump("IP UTILIZADO JA KRAI");
            //$this->logger->critical("**** FAILED TO BIND TO " . 0 . ":" . 0 . "!", true, true, 0);
            //$this->logger->critical("Perhaps a server is already running on that port?", true, true, 0);
            exit(1);
        }

        Proxy::$instance->getLogger()->info('Servidor ecutando no endereço ' . $this->address . ':' . $this->port . '!');
        socket_set_nonblock($this->socket);
    }



    public static function handleEvents()
    {
        while (Proxy::$instance->isRunning()) {
            foreach (self::$socketPool as $hash => $socket) {
                try {
                    if ($len = @socket_recvfrom($socket, $buffer, 65535, 0, $source, $port) !== false) {
                        if (!isset(self::$eventPool[$hash])) continue;
                        foreach (self::$eventPool[$hash] as $callable) {
                            $callable->call(Proxy::$instance, $buffer, $len, $source, $port);
                        }
                    }
                } catch (\Exception $exception) {
                    var_dump("Erro: " . $exception->getMessage());
                }
            }

        }
    }

    public static function addSocketPool(Socket $socket)
    {
        return self::$socketPool[$socket->getHash()] = $socket->getSocket();
    }

    public static function addListener(Socket $socket, \Closure $callable)
    {
        return self::$eventPool[$socket->getHash()][] = $callable;
    }

}