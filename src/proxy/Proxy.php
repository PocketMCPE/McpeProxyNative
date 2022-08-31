<?php

namespace proxy;

use proxy\utils\MainLogger;
use proxy\utils\Socket;
use raklib\PacketManager;
use raklib\protocol\DATA_PACKET_4;
use raklib\protocol\OPEN_CONNECTION_REPLY_2;
use raklib\protocol\OPEN_CONNECTION_REQUEST_1;
use raklib\protocol\OPEN_CONNECTION_REQUEST_2;

class Proxy
{

    public static $instance;

    private $classLoader = null;
    private $logger = null;

    private $socket;

    private $filePath;
    private $dataPath;

    public $isRunning = true;
    public $hasStopped = false;

    private $players = [];


    public static function getInstance()
    {
        return self::$instance;
    }

    public function hello()
    {
        $this->logger->info('
        
    ██╗░░░░░███████╗░█████╗░██████╗░██╗░░██╗██████╗░"
    ██║░░░░░██╔════╝██╔══██╗██╔══██╗╚██╗██╔╝██╔══██╗"
    ██║░░░░░█████╗░░███████║██████╔╝░╚███╔╝░██║░░██║"
    ██║░░░░░██╔══╝░░██╔══██║██╔══██╗░██╔██╗░██║░░██║"
    ███████╗███████╗██║░░██║██║░░██║██╔╝╚██╗██████╔╝"
    ╚══════╝╚══════╝╚═╝░░╚═╝╚═╝░░╚═╝╚═╝░░╚═╝╚═════╝░"
                    
               (For Minecraft 0.15.10)
                Proxy by LearXD #1044
               RakLib By PocketMine-Team
               
        ', 'HELLO');
    }

    public function __construct(\ClassLoader $classLoader, MainLogger $logger, $filePath, $dataPath)
    {
        try {
            self::$instance = $this;

            $this->classLoader = $classLoader;
            $this->logger = $logger;
            $this->filePath = $filePath;
            $this->dataPath = $dataPath;

            $this->hello();
            $logger->info('Proxy iniciando...');

            $server = [
                'ip' => '51.81.47.131',
                'port' => 25562
            ];
            //$server = [
            //    'ip' => 'us1.learxd.me',
            //    'port' => 19132
            //];

            $this->socket = $socket = new Socket('0.0.0.0', 25562);

            Socket::addListener($this->socket, function ($buffer, $len, $source, $port) use ($socket, $logger, $server) {
                $pid = ord($buffer{0});
                if (($packet = PacketManager::getPacketFromPool($pid)) !== null) {
                    var_dump("PLAYER => " . get_class($packet));
                    //var_dump($buffer);
                    $packet->buffer = $buffer;
                    switch ($packet::$ID) {
                        case OPEN_CONNECTION_REQUEST_1::$ID:
                            if ($player = $this->getSession($source, $port)) {
                                $player->connect($server['ip'], $server['port']);
                                $player->getSocket()->write($buffer, $server['ip'], $server['port']);
                            }
                            break;
                        case OPEN_CONNECTION_REQUEST_2::$ID:

                            $packet->decode();
                            $pk = new OPEN_CONNECTION_REQUEST_2();
                            $pk->clientID = $packet->clientID;
                            $pk->serverAddress = $packet->serverAddress;
                            $pk->serverPort = $server['port'];
                            $pk->mtuSize = $packet->mtuSize;
                            $pk->encode();

                            if ($player = $this->getSession($source, $port)) {
                                $player->getSocket()->write($pk->buffer, $server['ip'], $server['port']);
                            }
                            break;

                        default:

                            break;
                    }
                    if ($player = $this->getSession($source, $port, false)) {
                        if ($packet::$ID >= 0x80 and $packet::$ID <= 0x8f and $packet instanceof \raklib\protocol\DataPacket) {
                            $packet->decode();
                            $packet->packets = $player->processBuffer($packet, 'player');
                            if (sizeof($packet->packets) > 0) {
                                $packet->encode();
                                $player->getSocket()->write($packet->buffer, $server['ip'], $server['port']);
                            }
                        } else {
                            $player->getSocket()->write($buffer, $server['ip'], $server['port']);
                        }
                    }
                    return;
                }


            });

            $socket->bind();

            PacketManager::registerPackets();
            PacketManager::registerDataPackets();

            $logger->info('Servidor iniciado com sucesso!');
            Socket::init();

            $this->tickProcessor();
            $this->forceShutdown();
        } catch (\Throwable $e) {
            var_dump($e->getMessage());
        }

    }

    public function getLoader()
    {
        return $this->classLoader;
    }

    public function getLogger()
    {
        return $this->logger;
    }

    public function isRunning()
    {
        return $this->isRunning;
    }

    public function getSocket()
    {
        return $this->socket;
    }

    public function shutdown(bool $restart = false, string $msg = "")
    {
        $this->isRunning = false;
    }

    private function tickProcessor()
    {
        $this->nextTick = microtime(true);
        while ($this->isRunning) {
            $next = $this->nextTick - 0.0001;
            if ($next > microtime(true)) {
                @time_sleep_until($next);
            }
        }
    }

    public function forceShutdown()
    {
        if ($this->hasStopped) {
            return;
        }

        try {
            $this->hasStopped = true;
            $this->shutdown();
            $this->getLogger()->debug("Stopping network interfaces");
            socket_close($this->socket->getSocket());

            gc_collect_cycles();
        } catch (\Throwable $e) {
            $this->logger->logException($e);
            $this->logger->emergency("Crashed while crashing, killing process");
            @kill(getmypid());
        }

    }

    public function getSession($address, $port, $create = true)
    {
        if (!isset($this->players[$address . ':' . $port]) and $create) {
            $this->logger->info('Nova interface de jogador conectado [' . $address . ':' . $port . ']...');
            $this->players[$address . ':' . $port] = $player = new Player(Proxy::$instance, $address, $port);

            $socket = $this->socket;

            Socket::addListener($player->getSocket(), function ($buffer) use ($socket, $address, $port) {
                if (isset(Proxy::$instance->players[$address . ':' . $port])) {
                    /** @var Player $player */
                    $player = Proxy::$instance->players[$address . ':' . $port];
                    $pid = ord($buffer{0});
                    if (($packet = PacketManager::getPacketFromPool($pid)) !== null) {
                        var_dump("SERVER => " . get_class($packet));
                        //var_dump($buffer);
                        $packet->buffer = $buffer;

                        switch ($packet::$ID) {
                            case OPEN_CONNECTION_REPLY_2::$ID:
                                $packet->decode();
                                $pk = new OPEN_CONNECTION_REPLY_2();
                                $pk->serverID = $packet->serverID;
                                $pk->clientAddress = $packet->clientAddress;
                                $pk->clientPort = $port;
                                $pk->mtuSize = $packet->mtuSize;
                                $pk->encode();
                                $socket->write($pk->buffer, $address, $port);
                                break;
                            default:
                                if ($packet::$ID >= 0x80 and $packet::$ID <= 0x8f and $packet instanceof \raklib\protocol\DataPacket) {
                                    $packet->decode();
                                    $packet->packets = $player->processBuffer($packet, 'server');
                                    if (sizeof($packet->packets) > 0) {
                                        $packet->encode();
                                        $socket->write($packet->buffer, $address, $port);
                                    }
                                } else {
                                    $socket->write($buffer, $address, $port);
                                }
                                break;
                        }
                    }
                }

            });
        }
        return $this->players[$address . ':' . $port] ?? false;
    }

}