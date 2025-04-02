<?php

namespace proxy;

use proxy\network\protocol\DataPacket;
use proxy\network\protocol\Info;
use proxy\network\protocol\TextPacket;
use proxy\utils\Socket;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\OPEN_CONNECTION_REQUEST_2;
use raklib\protocol\UNCONNECTED_PONG;

use raklib\PacketManager;

class Player extends entity\Entity
{

    private $proxy;

    private $socket;

    public $address;
    public $port;

    public $session;

    /** @var Server */
    public $server = null;

    public function __construct(Proxy $proxy, string $address, int $port)
    {
        $this->proxy = $proxy;
        $this->address = $address;
        $this->port = $port;

        $this->socket = new Socket($address, $port);
        $this->socket->setNonblock();
    }

    public function connect(string $address, int $port = 19132)
    {
        return $this->server = new Server($address, $port);
    }

    public function getSocket()
    {
        return $this->socket;
    }

    public function getServer()
    {
        return $this->server;
    }

    public function processBuffer(\raklib\protocol\DataPacket $dataPacket, $from = "player" | "server")
    {

        $packets = $dataPacket->packets;

        foreach ($packets as $index => $pk) {
            $packet = PacketManager::extractPacket($pk);
            if($packet) {
                $dataPacket = ($from === 'server' ? $this->dataPacket($packet) : $this->handleDataPacket($packet));
                if ($dataPacket instanceof DataPacket) {
                    $packets[$index] = PacketManager::encapsulatePacket($dataPacket);
                }
            }

        }

        return $packets;
    }

    /**
     * @param DataPacket $dataPacket
     * @return DataPacket
     */
    public function handleDataPacket(DataPacket $dataPacket)
    {
        $dataPackets = [];
        $pk = null;



        if ($dataPacket::NETWORK_ID === Info::BATCH_PACKET) {
            $dataPacket->decode();

            /** @var DataPacket $pk */

            if (($dataPackets = PacketManager::processBatch($dataPacket)) === null) {
                return null;
            }
        }

        if(sizeof($dataPackets) <= 0) ($dataPackets[] = $dataPacket);

        foreach ($dataPackets as $packet) {
            var_dump("[DATAPACKET] PLAYER => ");
            var_dump($packet);
        }

        return $pk;
    }


    /**
     * @param DataPacket $dataPacket
     * @return DataPacket
     */
    public function dataPacket(DataPacket $dataPacket)
    {
        $pk = null;

        var_dump("SERVER => PLAYER ");
        var_dump($dataPacket);

        return $pk;
    }

    public function sendPacket(DataPacket $dataPacket)
    {
        if (($server = $this->getServer()) and $server->isConnected()) {
            $encapsulatedPacket = PacketManager::encapsulatePacket($dataPacket);
            return $this->getSocket()->write($encapsulatedPacket->buffer, $server->getAddress(), $server->getPort());
        }
        return false;
    }

}