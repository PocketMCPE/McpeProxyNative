<?php

namespace proxy;

class Server
{

    protected $address = "0.0.0.0";
    protected $port = 19132;

    protected $key = "sha32";

    protected $connected = true;

    public function __construct(string $address, int $port = 19132, string $key = "") {
        $this->address = $address;
        $this->port = $port;

        $this->key = $key;
    }

    public function getAddress() {
        return $this->address;
    }

    public function getPort() {
        return $this->port;
    }

    public function getKey() {
        return $this->key;
    }

    public function isConnected() {
        return $this->connected;
    }

}