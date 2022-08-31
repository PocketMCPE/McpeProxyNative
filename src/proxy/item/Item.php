<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

/**
 * All the Item classes
 */

namespace proxy\item;

use proxy\nbt\NBT;
use proxy\nbt\tag\CompoundTag;
use proxy\nbt\tag\IntTag;
use proxy\nbt\tag\ListTag;
use proxy\nbt\tag\ShortTag;
use proxy\nbt\tag\StringTag;

class Item implements ItemIds
{
    /** @var NBT */
    private static $cachedParser = null;

    private static function parseCompoundTag(string $tag): CompoundTag
    {
        if (self::$cachedParser === null) {
            self::$cachedParser = new NBT(NBT::LITTLE_ENDIAN);
        }

        self::$cachedParser->read($tag);
        return self::$cachedParser->getData();
    }

    private static function writeCompoundTag(CompoundTag $tag): string
    {
        if (self::$cachedParser === null) {
            self::$cachedParser = new NBT(NBT::LITTLE_ENDIAN);
        }

        self::$cachedParser->setData($tag);
        return self::$cachedParser->write();
    }


    /** @var \SplFixedArray */
    public static $list = null;
    protected $block;
    protected $id;
    protected $meta;
    private $tags = "";
    private $cachedNBT = null;
    public $count;
    protected $durability = 0;
    protected $name;

    public function canBeActivated(): bool
    {
        return false;
    }

    public function setCompoundTag($tags)
    {
        if ($tags instanceof CompoundTag) {
            $this->setNamedTag($tags);
        } else {
            $this->tags = $tags;
            $this->cachedNBT = null;
        }
        return $this;
    }

    /**
     * @return string
     */
    public function getCompoundTag()
    {
        return $this->tags;
    }

    public function hasCompoundTag(): bool
    {
        return $this->tags !== "" and $this->tags !== null;
    }

    public function hasCustomBlockData(): bool
    {
        if (!$this->hasCompoundTag()) {
            return false;
        }

        $tag = $this->getNamedTag();
        if (isset($tag->BlockEntityTag) and $tag->BlockEntityTag instanceof CompoundTag) {
            return true;
        }

        return false;
    }

    public function clearCustomBlockData()
    {
        if (!$this->hasCompoundTag()) {
            return $this;
        }
        $tag = $this->getNamedTag();

        if (isset($tag->BlockEntityTag) and $tag->BlockEntityTag instanceof CompoundTag) {
            unset($tag->display->BlockEntityTag);
            $this->setNamedTag($tag);
        }

        return $this;
    }

    public function setCustomBlockData(CompoundTag $compound)
    {
        $tags = clone $compound;
        $tags->setName("BlockEntityTag");

        if (!$this->hasCompoundTag()) {
            $tag = new CompoundTag("", []);
        } else {
            $tag = $this->getNamedTag();
        }

        $tag->BlockEntityTag = $tags;
        $this->setNamedTag($tag);

        return $this;
    }

    public function getCustomBlockData()
    {
        if (!$this->hasCompoundTag()) {
            return null;
        }

        $tag = $this->getNamedTag();
        if (isset($tag->BlockEntityTag) and $tag->BlockEntityTag instanceof CompoundTag) {
            return $tag->BlockEntityTag;
        }

        return null;
    }

    public function hasEnchantments(): bool
    {
        if (!$this->hasCompoundTag()) {
            return false;
        }

        $tag = $this->getNamedTag();
        if (isset($tag->ench)) {
            $tag = $tag->ench;
            if ($tag instanceof ListTag) {
                return true;
            }
        }

        return false;
    }

    public function hasCustomName(): bool
    {
        if (!$this->hasCompoundTag()) {
            return false;
        }

        $tag = $this->getNamedTag();
        if (isset($tag->display)) {
            $tag = $tag->display;
            if ($tag instanceof CompoundTag and isset($tag->Name) and $tag->Name instanceof StringTag) {
                return true;
            }
        }

        return false;
    }

    public function getCustomName(): string
    {
        if (!$this->hasCompoundTag()) {
            return "";
        }

        $tag = $this->getNamedTag();
        if (isset($tag->display)) {
            $tag = $tag->display;
            if ($tag instanceof CompoundTag and isset($tag->Name) and $tag->Name instanceof StringTag) {
                return $tag->Name->getValue();
            }
        }

        return "";
    }

    public function setCustomName(string $name)
    {
        if ($name === "") {
            $this->clearCustomName();
        }

        if (!($hadCompoundTag = $this->hasCompoundTag())) {
            $tag = new CompoundTag("", []);
        } else {
            $tag = $this->getNamedTag();
        }

        if (isset($tag->display) and $tag->display instanceof CompoundTag) {
            $tag->display->Name = new StringTag("Name", $name);
        } else {
            $tag->display = new CompoundTag("display", [
                "Name" => new StringTag("Name", $name)
            ]);
        }

        if (!$hadCompoundTag) {
            $this->setCompoundTag($tag);
        }

        return $this;
    }

    public function clearCustomName()
    {
        if (!$this->hasCompoundTag()) {
            return $this;
        }
        $tag = $this->getNamedTag();

        if (isset($tag->display) and $tag->display instanceof CompoundTag) {
            unset($tag->display->Name);
            if ($tag->display->getCount() === 0) {
                unset($tag->display);
            }

            $this->setNamedTag($tag);
        }

        return $this;
    }

    public function getNamedTagEntry($name)
    {
        $tag = $this->getNamedTag();
        if ($tag !== null) {
            return isset($tag->{$name}) ? $tag->{$name} : null;
        }

        return null;
    }

    public function getNamedTag()
    {
        if (!$this->hasCompoundTag()) {
            return null;
        } elseif ($this->cachedNBT !== null) {
            return $this->cachedNBT;
        }
        return $this->cachedNBT = self::parseCompoundTag($this->tags);
    }

    public function setNamedTag(CompoundTag $tag)
    {
        if ($tag->getCount() === 0) {
            return $this->clearNamedTag();
        }

        $this->cachedNBT = $tag;
        $this->tags = self::writeCompoundTag($tag);

        return $this;
    }

    public static function get($id, $meta = 0, int $count = 1, $tags = ""): Item
    {

        $item = new Item($id);
        $item->setCompoundTag($tags);

        $item = Item::fromString($id);
        $item->setCount($count);
        $item->setDamage($meta);

        return $item;
    }

    /**
     * @param string $str
     * @param bool $multiple
     * @return Item[]|Item
     */
    public static function fromString(string $str, bool $multiple = false)
    {
        if ($multiple === true) {
            $blocks = [];
            foreach (explode(",", $str) as $b) {
                $blocks[] = self::fromString($b, false);
            }

            return $blocks;
        } else {
            $b = explode(":", str_replace([" ", "minecraft:"], ["_", ""], trim($str)));
            if (!isset($b[1])) {
                $meta = 0;
            } else {
                $meta = $b[1] & 0xFFFF;
            }

            if (defined(Item::class . "::" . strtoupper($b[0]))) {
                $item = self::get(constant(Item::class . "::" . strtoupper($b[0])), $meta);
                if ($item->getId() === self::AIR and strtoupper($b[0]) !== "AIR") {
                    $item = self::get($b[0] & 0xFFFF, $meta);
                }
            } else {
                $item = self::get($b[0] & 0xFFFF, $meta);
            }

            return $item;
        }
    }

    public function clearNamedTag()
    {
        return $this->setCompoundTag("");
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function setCount(int $count)
    {
        $this->count = $count;
    }

    final public function getName(): string
    {
        return $this->hasCustomName() ? $this->getCustomName() : $this->name;
    }

    final public function canBePlaced(): bool
    {
        return $this->block !== null and $this->block->canBePlaced();
    }

    final public function isPlaceable(): bool
    {
        return $this->canBePlaced();
    }

    public function canBeConsumed(): bool
    {
        return false;
    }

    final public function getId(): int
    {
        return $this->id;
    }

    final public function getDamage()
    {
        return $this->meta;
    }

    public function setDamage($meta)
    {
        $this->meta = $meta !== null ? $meta & 0xFFFF : null;
    }

    public function getMaxStackSize(): int
    {
        return 64;
    }

    /**
     * @return bool
     */
    public function isTool()
    {
        return false;
    }

    /**
     * @return int|bool
     */
    public function getMaxDurability()
    {
        return false;
    }

    public function isPickaxe()
    {
        return false;
    }

    public function isAxe()
    {
        return false;
    }

    public function isSword()
    {
        return false;
    }

    public function isShovel()
    {
        return false;
    }

    public function isHoe()
    {
        return false;
    }

    public function isShears()
    {
        return false;
    }

    public function isArmor()
    {
        return false;
    }

    public function getArmorValue()
    {
        return false;
    }

    public function isBoots()
    {
        return false;
    }

    public function isHelmet()
    {
        return false;
    }

    public function isLeggings()
    {
        return false;
    }

    public function isChestplate()
    {
        return false;
    }

    public function getAttackDamage()
    {
        return 1;
    }

    public function __construct($id, $meta = 0, int $count = 1, string $name = "Unknown")
    {
        $this->id = $id & 0xffff;
        $this->meta = $meta !== null ? $meta & 0xffff : null;
        $this->count = $count;
        $this->name = $name;
    }

    final public function __toString()
    { //Get error here..
        return "Item " . $this->name . " (" . $this->id . ":" . ($this->meta === null ? "?" : $this->meta) . ")x" . $this->count . ($this->hasCompoundTag() ? " tags:0x" . bin2hex($this->getCompoundTag()) : "");
    }

    public final function equals(Item $item, bool $checkDamage = true, bool $checkCompound = true, bool $checkCount = false): bool
    {
        return $this->id === $item->getId() and ($checkCount === false or $this->getCount() === $item->getCount()) and ($checkDamage === false or $this->getDamage() === $item->getDamage()) and ($checkCompound === false or $this->getCompoundTag() === $item->getCompoundTag());
    }

    public final function deepEquals(Item $item, bool $checkDamage = true, bool $checkCompound = true, bool $checkCount = false): bool
    {
        if ($this->equals($item, $checkDamage, $checkCompound, $checkCount)) {
            return true;
        } elseif ($item->hasCompoundTag() and $this->hasCompoundTag()) {
            return NBT::matchTree($this->getNamedTag(), $item->getNamedTag());
        }

        return false;
    }
}
