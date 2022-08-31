<?php


namespace raklib;

use pocketmine\Server;
use pocketmine\utils\Utils;
use proxy\Proxy;
use proxy\utils\Socket;
use raklib\Binary;
use raklib\protocol\ACK;
use raklib\protocol\ADVERTISE_SYSTEM;
use raklib\protocol\DATA_PACKET_0;
use raklib\protocol\DATA_PACKET_1;
use raklib\protocol\DATA_PACKET_2;
use raklib\protocol\DATA_PACKET_3;
use raklib\protocol\DATA_PACKET_4;
use raklib\protocol\DATA_PACKET_5;
use raklib\protocol\DATA_PACKET_6;
use raklib\protocol\DATA_PACKET_7;
use raklib\protocol\DATA_PACKET_8;
use raklib\protocol\DATA_PACKET_9;
use raklib\protocol\DATA_PACKET_A;
use raklib\protocol\DATA_PACKET_B;
use raklib\protocol\DATA_PACKET_C;
use raklib\protocol\DATA_PACKET_D;
use raklib\protocol\DATA_PACKET_E;
use raklib\protocol\DATA_PACKET_F;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\OPEN_CONNECTION_REPLY_1;
use raklib\protocol\OPEN_CONNECTION_REPLY_2;
use raklib\protocol\OPEN_CONNECTION_REQUEST_1;
use raklib\protocol\OPEN_CONNECTION_REQUEST_2;
use raklib\protocol\Packet;
use raklib\protocol\UNCONNECTED_PING;
use raklib\protocol\UNCONNECTED_PING_OPEN_CONNECTIONS;
use raklib\protocol\UNCONNECTED_PONG;
use raklib\RakLib;
use raklib\server\RakLibServer;
use raklib\server\Session;

class SessionManager
{
    protected $packetPool = [];

    protected $socket;

    protected $receiveBytes = 0;
    protected $sendBytes = 0;

    /** @var Session[] */
    protected $sessions = [];

    protected $name = "";

    protected $packetLimit = 500;

    protected $shutdown = false;

    protected $ticks = 0;
    protected $lastMeasure;

    protected $block = [];
    protected $ipSec = [];

    protected $lastTick = 0;

    public $portChecking = true;

    public function __construct(RakLibServer $server, Proxy $proxy, Socket $socket)
    {
        $this->server = $server;
        $this->proxy = $proxy;
        $this->socket = $socket;
        $this->registerPackets();

        $this->serverId = mt_rand(0, PHP_INT_MAX);
        $this->run();
    }

    public function run(){
        $this->tickProcessor();
    }

    private function tickProcessor(){
        $this->lastMeasure = microtime(true);

        while(!$this->shutdown){
            $start = microtime(true);
            //$max = 5000;
            //while(--$max and $this->receivePacket());
            while($this->receiveStream());
            $time = microtime(true) - $start;
            if($time < 0.05){
                time_sleep_until(microtime(true) + 0.05 - $time);
            }
            //$this->tick();
        }
    }

    public function getPort()
    {
        return $this->server->getPort();
    }

    public function getLogger()
    {
        return $this->server->getLogger();
    }

    private function receiveBuffer($buffer, $bytes, $address, $port)
    {
        if ($bytes !== false and $bytes <= 2048) {
            $pid = ord($buffer{0});

            if ($pid == UNCONNECTED_PONG::$ID) {
                return false;
            }

            if (($packet = $this->getPacketFromPool($pid)) !== null) {
                $packet->buffer = $buffer;
                $this->getSession($address, $port)->handlePacket($packet);
                return true;
            } /*elseif ($pid === UNCONNECTED_PING::$ID) {
                $packet = new UNCONNECTED_PING;
                $packet->buffer = $buffer;
                $packet->decode();

                $pk = new UNCONNECTED_PONG();
                $pk->serverID = $this->getID();
                $pk->pingID = $packet->pingID;
                $pk->serverName = $this->getName();
                $this->sendPacket($pk, $address, $port);
            } elseif ($buffer !== "") {
                $this->streamRaw($address, $port, $buffer);
                return true;
            } */else {
                return false;
            }
        } else {
            $errno = socket_last_error($this->socket->getSocket());
            if ($errno === SOCKET_EWOULDBLOCK) {
                return false;
            }
            if ($errno !== SOCKET_ECONNRESET) { //remote peer disappeared unexpectedly, this might spam like crazy so we don't log it
                $this->getLogger()->debug("Failed to recv (errno $errno): " . trim(socket_strerror($errno)));
            }


        }
    }



    public function sendPacket(Packet $packet, $dest, $port)
    {
        $packet->encode();
        $this->sendBytes += $this->socket->write($packet->buffer, $dest, $port);
    }

    public function streamEncapsulated(Session $session, EncapsulatedPacket $packet, $flags = RakLib::PRIORITY_NORMAL)
    {

        $id = $session->getAddress() . ":" . $session->getPort();
        $buffer = chr(RakLib::PACKET_ENCAPSULATED) . chr(strlen($id)) . $id . chr($flags) . $packet->toBinary(true);
        $this->server->pushThreadToMainPacket($buffer);
    }

    public function streamRaw($address, $port, $payload)
    {

        $buffer = chr(RakLib::PACKET_RAW) . chr(strlen($address)) . $address . Binary::writeShort($port) . $payload;
        $this->server->pushThreadToMainPacket($buffer);
    }

    protected function streamClose($identifier, $reason)
    {

        $buffer = chr(RakLib::PACKET_CLOSE_SESSION) . chr(strlen($identifier)) . $identifier . chr(strlen($reason)) . $reason;
        $this->server->pushThreadToMainPacket($buffer);
    }

    protected function streamInvalid($identifier)
    {

        $buffer = chr(RakLib::PACKET_INVALID_SESSION) . chr(strlen($identifier)) . $identifier;
        $this->server->pushThreadToMainPacket($buffer);
    }

    protected function streamOpen(Session $session)
    {

        $identifier = $session->getAddress() . ":" . $session->getPort();
        $buffer = chr(RakLib::PACKET_OPEN_SESSION) . chr(strlen($identifier)) . $identifier . chr(strlen($session->getAddress())) . $session->getAddress() . Binary::writeShort($session->getPort()) . Binary::writeLong($session->getID());
        $this->server->pushThreadToMainPacket($buffer);
    }

    protected function streamACK($identifier, $identifierACK)
    {
        $buffer = chr(RakLib::PACKET_ACK_NOTIFICATION) . chr(strlen($identifier)) . $identifier . Binary::writeInt($identifierACK);
        $this->server->pushThreadToMainPacket($buffer);
    }

    protected function streamOption($name, $value)
    {
        $buffer = chr(RakLib::PACKET_SET_OPTION) . chr(strlen($name)) . $name . $value;
        $this->server->pushThreadToMainPacket($buffer);
    }

    private function checkSessions()
    {
        if (count($this->sessions) > 4096) {
            foreach ($this->sessions as $i => $s) {
                if ($s->isTemporal()) {
                    unset($this->sessions[$i]);
                    if (count($this->sessions) <= 4096) {
                        break;
                    }
                }
            }
        }
    }

    public function receiveStream()
    {

        if (strlen($packet = $this->server->readMainToThreadPacket()) > 0) {
            $id = ord($packet{0});
            $offset = 1;
            if ($id === RakLib::PACKET_ENCAPSULATED) {
                $len = ord($packet{$offset++});
                $identifier = substr($packet, $offset, $len);
                $offset += $len;
                if (isset($this->sessions[$identifier])) {
                    $flags = ord($packet{$offset++});
                    $buffer = substr($packet, $offset);
                    $this->sessions[$identifier]->addEncapsulatedToQueue(EncapsulatedPacket::fromBinary($buffer, true), $flags);
                } else {
                    $this->streamInvalid($identifier);
                }
            } /* elseif ($id === RakLib::PACKET_RAW) {
                $len = ord($packet{$offset++});
                $address = substr($packet, $offset, $len);
                $offset += $len;
                $port = Binary::readShort(substr($packet, $offset, 2));
                $offset += 2;
                $payload = substr($packet, $offset);
                $this->socket->write($payload, $address, $port);
            } elseif ($id === RakLib::PACKET_CLOSE_SESSION) {
                $len = ord($packet{$offset++});
                $identifier = substr($packet, $offset, $len);
                if (isset($this->sessions[$identifier])) {
                    $this->removeSession($this->sessions[$identifier]);
                } else {
                    $this->streamInvalid($identifier);
                }
            } elseif ($id === RakLib::PACKET_INVALID_SESSION) {
                $len = ord($packet{$offset++});
                $identifier = substr($packet, $offset, $len);
                if (isset($this->sessions[$identifier])) {
                    $this->removeSession($this->sessions[$identifier]);
                }
            } elseif ($id === RakLib::PACKET_SET_OPTION) {
                $len = ord($packet{$offset++});
                $name = substr($packet, $offset, $len);
                $offset += $len;
                $value = substr($packet, $offset);
                switch ($name) {
                    case "name":
                        $this->name = $value;
                        break;
                    case "portChecking":
                        $this->portChecking = (bool)$value;
                        break;
                    case "packetLimit":
                        $this->packetLimit = (int)$value;
                        break;
                }
            } elseif ($id === RakLib::PACKET_BLOCK_ADDRESS) {
                $len = ord($packet{$offset++});
                $address = substr($packet, $offset, $len);
                $offset += $len;
                $timeout = Binary::readInt(substr($packet, $offset, 4));
                $this->blockAddress($address, $timeout);
            } elseif ($id === RakLib::PACKET_UNBLOCK_ADDRESS) {
                $len = ord($packet{$offset++});
                $address = substr($packet, $offset, $len);
                $offset += $len;
                $this->unblockAddress($address);
            } elseif ($id === RakLib::PACKET_SHUTDOWN) {
                foreach ($this->sessions as $session) {
                    $this->removeSession($session);
                }

                $this->socket->close();
                $this->shutdown = true;
            } elseif ($id === RakLib::PACKET_EMERGENCY_SHUTDOWN) {
                $this->shutdown = true;
            } */ else {
                return false;
            }

            return true;
        }

        return false;
    }

    public function blockAddress($address, $timeout = 300)
    {
        $final = microtime(true) + $timeout;
        if (!isset($this->block[$address]) or $timeout === -1) {
            if ($timeout === -1) {
                $final = PHP_INT_MAX;
            } else {
                $this->getLogger()->notice("Blocked $address for $timeout seconds");
            }
            $this->block[$address] = $final;
        } elseif ($this->block[$address] < $final) {
            $this->block[$address] = $final;
        }
    }

    public function unblockAddress($address)
    {
        unset($this->block[$address]);
    }

    /**
     * @param string $ip
     * @param int $port
     *
     * @return Session
     */
    public function getSession($ip, $port)
    {
        $id = $ip . ":" . $port;
        if (!isset($this->sessions[$id])) {
            $this->checkSessions();
            $this->sessions[$id] = new Session($this, $ip, $port);
        }

        return $this->sessions[$id];
    }

    public function removeSession(Session $session, $reason = "unknown")
    {
        $id = $session->getAddress() . ":" . $session->getPort();
        if (isset($this->sessions[$id])) {
            $this->sessions[$id]->close();
            unset($this->sessions[$id]);
            $this->streamClose($id, $reason);
        }
    }

    public function openSession(Session $session)
    {
        $this->streamOpen($session);
    }

    public function notifyACK(Session $session, $identifierACK)
    {
        $this->streamACK($session->getAddress() . ":" . $session->getPort(), $identifierACK);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getID()
    {
        return $this->serverId;
    }

    private function registerPacket($id, $class)
    {
        $this->packetPool[$id] = new $class;
    }

    /**
     * @param $id
     *
     * @return Packet
     */
    public function getPacketFromPool($id)
    {
        if (isset($this->packetPool[$id])) {
            return clone $this->packetPool[$id];
        }

        return null;
    }

    private function registerPackets()
    {
        //$this->registerPacket(UNCONNECTED_PING::$ID, UNCONNECTED_PING::class);
        $this->registerPacket(UNCONNECTED_PING_OPEN_CONNECTIONS::$ID, UNCONNECTED_PING_OPEN_CONNECTIONS::class);
        $this->registerPacket(OPEN_CONNECTION_REQUEST_1::$ID, OPEN_CONNECTION_REQUEST_1::class);
        $this->registerPacket(OPEN_CONNECTION_REPLY_1::$ID, OPEN_CONNECTION_REPLY_1::class);
        $this->registerPacket(OPEN_CONNECTION_REQUEST_2::$ID, OPEN_CONNECTION_REQUEST_2::class);
        $this->registerPacket(OPEN_CONNECTION_REPLY_2::$ID, OPEN_CONNECTION_REPLY_2::class);
        $this->registerPacket(UNCONNECTED_PONG::$ID, UNCONNECTED_PONG::class);
        $this->registerPacket(ADVERTISE_SYSTEM::$ID, ADVERTISE_SYSTEM::class);
        $this->registerPacket(DATA_PACKET_0::$ID, DATA_PACKET_0::class);
        $this->registerPacket(DATA_PACKET_1::$ID, DATA_PACKET_1::class);
        $this->registerPacket(DATA_PACKET_2::$ID, DATA_PACKET_2::class);
        $this->registerPacket(DATA_PACKET_3::$ID, DATA_PACKET_3::class);
        $this->registerPacket(DATA_PACKET_4::$ID, DATA_PACKET_4::class);
        $this->registerPacket(DATA_PACKET_5::$ID, DATA_PACKET_5::class);
        $this->registerPacket(DATA_PACKET_6::$ID, DATA_PACKET_6::class);
        $this->registerPacket(DATA_PACKET_7::$ID, DATA_PACKET_7::class);
        $this->registerPacket(DATA_PACKET_8::$ID, DATA_PACKET_8::class);
        $this->registerPacket(DATA_PACKET_9::$ID, DATA_PACKET_9::class);
        $this->registerPacket(DATA_PACKET_A::$ID, DATA_PACKET_A::class);
        $this->registerPacket(DATA_PACKET_B::$ID, DATA_PACKET_B::class);
        $this->registerPacket(DATA_PACKET_C::$ID, DATA_PACKET_C::class);
        $this->registerPacket(DATA_PACKET_D::$ID, DATA_PACKET_D::class);
        $this->registerPacket(DATA_PACKET_E::$ID, DATA_PACKET_E::class);
        $this->registerPacket(DATA_PACKET_F::$ID, DATA_PACKET_F::class);
        $this->registerPacket(NACK::$ID, NACK::class);
        $this->registerPacket(ACK::$ID, ACK::class);
    }
}
