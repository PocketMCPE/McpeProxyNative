<?php

namespace raklib;

use pocketmine\Server;
use proxy\network\protocol\AddEntityPacket;
use proxy\network\protocol\AddItemEntityPacket;
use proxy\network\protocol\AddPaintingPacket;
use proxy\network\protocol\AddPlayerPacket;
use proxy\network\protocol\AdventureSettingsPacket;
use proxy\network\protocol\AnimatePacket;
use proxy\network\protocol\BatchPacket;
use proxy\network\protocol\BlockEntityDataPacket;
use proxy\network\protocol\BlockEventPacket;
use proxy\network\protocol\ChangeDimensionPacket;
use proxy\network\protocol\ChunkRadiusUpdatedPacket;
use proxy\network\protocol\ContainerClosePacket;
use proxy\network\protocol\ContainerOpenPacket;
use proxy\network\protocol\ContainerSetContentPacket;
use proxy\network\protocol\ContainerSetDataPacket;
use proxy\network\protocol\ContainerSetSlotPacket;
use proxy\network\protocol\CraftingDataPacket;
use proxy\network\protocol\CraftingEventPacket;
use proxy\network\protocol\DataPacket;
use proxy\network\protocol\DisconnectPacket;
use proxy\network\protocol\DropItemPacket;
use proxy\network\protocol\EntityEventPacket;
use proxy\network\protocol\ExplodePacket;
use proxy\network\protocol\FullChunkDataPacket;
use proxy\network\protocol\HurtArmorPacket;
use proxy\network\protocol\Info;
use proxy\network\protocol\Info as ProtocolInfo;
use proxy\network\protocol\InteractPacket;
use proxy\network\protocol\ItemFrameDropItemPacket;
use proxy\network\protocol\LevelEventPacket;
use proxy\network\protocol\LoginPacket;
use proxy\network\protocol\MobArmorEquipmentPacket;
use proxy\network\protocol\MobEquipmentPacket;
use proxy\network\protocol\MoveEntityPacket;
use proxy\network\protocol\MovePlayerPacket;
use proxy\network\protocol\PlayerActionPacket;
use proxy\network\protocol\PlayerInputPacket;
use proxy\network\protocol\PlayerListPacket;
use proxy\network\protocol\PlayStatusPacket;
use proxy\network\protocol\RemoveBlockPacket;
use proxy\network\protocol\RemoveEntityPacket;
use proxy\network\protocol\RequestChunkRadiusPacket;
use proxy\network\protocol\RespawnPacket;
use proxy\network\protocol\SetDifficultyPacket;
use proxy\network\protocol\SetEntityDataPacket;
use proxy\network\protocol\SetEntityLinkPacket;
use proxy\network\protocol\SetEntityMotionPacket;
use proxy\network\protocol\SetHealthPacket;
use proxy\network\protocol\SetPlayerGameTypePacket;
use proxy\network\protocol\SetSpawnPositionPacket;
use proxy\network\protocol\SetTimePacket;
use proxy\network\protocol\StartGamePacket;
use proxy\network\protocol\TakeItemEntityPacket;
use proxy\network\protocol\TextPacket;
use proxy\network\protocol\UpdateBlockPacket;
use proxy\network\protocol\UseItemPacket;
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
use raklib\protocol\UNCONNECTED_PING_OPEN_CONNECTIONS;
use raklib\protocol\UNCONNECTED_PONG;

class PacketManager
{

    protected static $packetPool = [];
    protected static $dataPacketPool = [];

    private static function registerPacket($id, $class)
    {
        self::$packetPool[$id] = new $class;
    }

    public static function getPacketFromPool($id)
    {
        if (isset(self::$packetPool[$id])) {
            return clone self::$packetPool[$id];
        }

        return null;
    }

    public static function registerDataPacket($id, $class)
    {
        self::$dataPacketPool[$id] = new $class;
    }

    public static function getDataPacket($buffer)
    {
        $pid = ord($buffer{0});
        $start = 1;
        if ($pid == 0xfe) {
            $pid = ord($buffer{1});
            $start++;
        }

        $class = self::$dataPacketPool[$pid];
        if ($class === null) {
            return null;
        }
        $class = clone $class;

        $class->setBuffer($buffer, $start);

        return $class;
    }

    public static function getDataPacketById($id)
    {
        return self::$dataPacketPool[$id] ?? null;
    }

    public static function processBatch(DataPacket $packet)
    {
        $str = @zlib_decode($packet->payload, 1024 * 1024 * 64); //Max 64MB

        $len = strlen($str);
        $offset = 0;


        $packets = [];
        while ($offset < $len) {
            $pkLen = Binary::readInt(substr($str, $offset, 4));
            $offset += 4;

            $buf = substr($str, $offset, $pkLen);
            $offset += $pkLen;

            if (strlen($buf) === 0) {
                return null;
            }

            if (($pk = self::getDataPacketById(ord($buf{0}))) !== null) {

                if ($pk::NETWORK_ID === Info::BATCH_PACKET) {
                    break;
                }

                $pk->setBuffer($buf, 1);
                $packets[] = $pk;
                //$pk->decode();
                if ($pk->getOffset() <= 0) {
                    break;
                }

            }
        }
        return $packets;

    }

    public static function batchPacket(DataPacket $packet, $forceSync = false)
    {

        if (!$packet->isEncoded) {
            $packet->encode();
        }

        $str = zlib_encode(Binary::writeInt(strlen($packet->buffer)) . $packet->buffer, ZLIB_ENCODING_DEFLATE, 7);

        $batch = new BatchPacket();
        $batch->payload = $str;

        $batch->encode();
        $batch->isEncoded = true;

        return $batch;
    }

    public static function encapsulatePacket(DataPacket $dataPacket)
    {
        if (!$dataPacket->isEncoded) {
            $dataPacket->encode();
            $dataPacket->isEncoded = true;
        }
        $encapsulated = new EncapsulatedPacket();
        $encapsulated->buffer = chr(0xfe) . $dataPacket->buffer;

        return $encapsulated;
    }

    public static function extractPacket(EncapsulatedPacket $dataPacket)
    {

        if ($dataPacket->buffer !== "") {
            $dataPacket = PacketManager::getDataPacket($dataPacket->buffer);
            if ($dataPacket !== null) {
                return $dataPacket;
            }

        }
        return null;
    }

    public static function registerPackets()
    {
        //$this->registerPacket(UNCONNECTED_PING::$ID, UNCONNECTED_PING::class);
        self::registerPacket(UNCONNECTED_PING_OPEN_CONNECTIONS::$ID, UNCONNECTED_PING_OPEN_CONNECTIONS::class);
        self::registerPacket(OPEN_CONNECTION_REQUEST_1::$ID, OPEN_CONNECTION_REQUEST_1::class);
        self::registerPacket(OPEN_CONNECTION_REPLY_1::$ID, OPEN_CONNECTION_REPLY_1::class);
        self::registerPacket(OPEN_CONNECTION_REQUEST_2::$ID, OPEN_CONNECTION_REQUEST_2::class);
        self::registerPacket(OPEN_CONNECTION_REPLY_2::$ID, OPEN_CONNECTION_REPLY_2::class);
        self::registerPacket(UNCONNECTED_PONG::$ID, UNCONNECTED_PONG::class);
        self::registerPacket(ADVERTISE_SYSTEM::$ID, ADVERTISE_SYSTEM::class);
        self::registerPacket(DATA_PACKET_0::$ID, DATA_PACKET_0::class);
        self::registerPacket(DATA_PACKET_1::$ID, DATA_PACKET_1::class);
        self::registerPacket(DATA_PACKET_2::$ID, DATA_PACKET_2::class);
        self::registerPacket(DATA_PACKET_3::$ID, DATA_PACKET_3::class);
        self::registerPacket(DATA_PACKET_4::$ID, DATA_PACKET_4::class);
        self::registerPacket(DATA_PACKET_5::$ID, DATA_PACKET_5::class);
        self::registerPacket(DATA_PACKET_6::$ID, DATA_PACKET_6::class);
        self::registerPacket(DATA_PACKET_7::$ID, DATA_PACKET_7::class);
        self::registerPacket(DATA_PACKET_8::$ID, DATA_PACKET_8::class);
        self::registerPacket(DATA_PACKET_9::$ID, DATA_PACKET_9::class);
        self::registerPacket(DATA_PACKET_A::$ID, DATA_PACKET_A::class);
        self::registerPacket(DATA_PACKET_B::$ID, DATA_PACKET_B::class);
        self::registerPacket(DATA_PACKET_C::$ID, DATA_PACKET_C::class);
        self::registerPacket(DATA_PACKET_D::$ID, DATA_PACKET_D::class);
        self::registerPacket(DATA_PACKET_E::$ID, DATA_PACKET_E::class);
        self::registerPacket(DATA_PACKET_F::$ID, DATA_PACKET_F::class);
        self::registerPacket(NACK::$ID, NACK::class);
        self::registerPacket(ACK::$ID, ACK::class);
    }

    public static function registerDataPackets()
    {
        self::$dataPacketPool = new \SplFixedArray(256);

        self::registerDataPacket(ProtocolInfo::LOGIN_PACKET, LoginPacket::class);
        self::registerDataPacket(ProtocolInfo::PLAY_STATUS_PACKET, PlayStatusPacket::class);
        self::registerDataPacket(ProtocolInfo::DISCONNECT_PACKET, DisconnectPacket::class);
        self::registerDataPacket(ProtocolInfo::BATCH_PACKET, BatchPacket::class);
        self::registerDataPacket(ProtocolInfo::TEXT_PACKET, TextPacket::class);
        self::registerDataPacket(ProtocolInfo::SET_TIME_PACKET, SetTimePacket::class);
        self::registerDataPacket(ProtocolInfo::START_GAME_PACKET, StartGamePacket::class);
        self::registerDataPacket(ProtocolInfo::ADD_PLAYER_PACKET, AddPlayerPacket::class);
        self::registerDataPacket(ProtocolInfo::ADD_ENTITY_PACKET, AddEntityPacket::class);
        self::registerDataPacket(ProtocolInfo::REMOVE_ENTITY_PACKET, RemoveEntityPacket::class);
        self::registerDataPacket(ProtocolInfo::ADD_ITEM_ENTITY_PACKET, AddItemEntityPacket::class);
        self::registerDataPacket(ProtocolInfo::TAKE_ITEM_ENTITY_PACKET, TakeItemEntityPacket::class);
        self::registerDataPacket(ProtocolInfo::MOVE_ENTITY_PACKET, MoveEntityPacket::class);
        self::registerDataPacket(ProtocolInfo::MOVE_PLAYER_PACKET, MovePlayerPacket::class);
        self::registerDataPacket(ProtocolInfo::REMOVE_BLOCK_PACKET, RemoveBlockPacket::class);
        self::registerDataPacket(ProtocolInfo::UPDATE_BLOCK_PACKET, UpdateBlockPacket::class);
        self::registerDataPacket(ProtocolInfo::ADD_PAINTING_PACKET, AddPaintingPacket::class);
        self::registerDataPacket(ProtocolInfo::EXPLODE_PACKET, ExplodePacket::class);
        self::registerDataPacket(ProtocolInfo::LEVEL_EVENT_PACKET, LevelEventPacket::class);
        self::registerDataPacket(ProtocolInfo::BLOCK_EVENT_PACKET, BlockEventPacket::class);
        self::registerDataPacket(ProtocolInfo::ENTITY_EVENT_PACKET, EntityEventPacket::class);
        self::registerDataPacket(ProtocolInfo::MOB_EQUIPMENT_PACKET, MobEquipmentPacket::class);
        self::registerDataPacket(ProtocolInfo::MOB_ARMOR_EQUIPMENT_PACKET, MobArmorEquipmentPacket::class);
        self::registerDataPacket(ProtocolInfo::INTERACT_PACKET, InteractPacket::class);
        self::registerDataPacket(ProtocolInfo::USE_ITEM_PACKET, UseItemPacket::class);
        self::registerDataPacket(ProtocolInfo::PLAYER_ACTION_PACKET, PlayerActionPacket::class);
        self::registerDataPacket(ProtocolInfo::HURT_ARMOR_PACKET, HurtArmorPacket::class);
        self::registerDataPacket(ProtocolInfo::SET_ENTITY_DATA_PACKET, SetEntityDataPacket::class);
        self::registerDataPacket(ProtocolInfo::SET_ENTITY_MOTION_PACKET, SetEntityMotionPacket::class);
        self::registerDataPacket(ProtocolInfo::SET_ENTITY_LINK_PACKET, SetEntityLinkPacket::class);
        self::registerDataPacket(ProtocolInfo::SET_HEALTH_PACKET, SetHealthPacket::class);
        self::registerDataPacket(ProtocolInfo::SET_SPAWN_POSITION_PACKET, SetSpawnPositionPacket::class);
        self::registerDataPacket(ProtocolInfo::ANIMATE_PACKET, AnimatePacket::class);
        self::registerDataPacket(ProtocolInfo::RESPAWN_PACKET, RespawnPacket::class);
        self::registerDataPacket(ProtocolInfo::DROP_ITEM_PACKET, DropItemPacket::class);
        self::registerDataPacket(ProtocolInfo::CONTAINER_OPEN_PACKET, ContainerOpenPacket::class);
        self::registerDataPacket(ProtocolInfo::CONTAINER_CLOSE_PACKET, ContainerClosePacket::class);
        self::registerDataPacket(ProtocolInfo::CONTAINER_SET_SLOT_PACKET, ContainerSetSlotPacket::class);
        self::registerDataPacket(ProtocolInfo::CONTAINER_SET_DATA_PACKET, ContainerSetDataPacket::class);
        self::registerDataPacket(ProtocolInfo::CONTAINER_SET_CONTENT_PACKET, ContainerSetContentPacket::class);
        self::registerDataPacket(ProtocolInfo::CRAFTING_DATA_PACKET, CraftingDataPacket::class);
        self::registerDataPacket(ProtocolInfo::CRAFTING_EVENT_PACKET, CraftingEventPacket::class);
        self::registerDataPacket(ProtocolInfo::ADVENTURE_SETTINGS_PACKET, AdventureSettingsPacket::class);
        self::registerDataPacket(ProtocolInfo::BLOCK_ENTITY_DATA_PACKET, BlockEntityDataPacket::class);
        self::registerDataPacket(ProtocolInfo::FULL_CHUNK_DATA_PACKET, FullChunkDataPacket::class);
        self::registerDataPacket(ProtocolInfo::SET_DIFFICULTY_PACKET, SetDifficultyPacket::class);
        self::registerDataPacket(ProtocolInfo::PLAYER_LIST_PACKET, PlayerListPacket::class);
        self::registerDataPacket(ProtocolInfo::PLAYER_INPUT_PACKET, PlayerInputPacket::class);
        self::registerDataPacket(ProtocolInfo::SET_PLAYER_GAMETYPE_PACKET, SetPlayerGameTypePacket::class);
        self::registerDataPacket(ProtocolInfo::CHANGE_DIMENSION_PACKET, ChangeDimensionPacket::class);
        self::registerDataPacket(ProtocolInfo::REQUEST_CHUNK_RADIUS_PACKET, RequestChunkRadiusPacket::class);
        self::registerDataPacket(ProtocolInfo::CHUNK_RADIUS_UPDATED_PACKET, ChunkRadiusUpdatedPacket::class);
        self::registerDataPacket(ProtocolInfo::ITEM_FRAME_DROP_ITEM_PACKET, ItemFrameDropItemPacket::class);
    }
}