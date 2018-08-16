<?php

namespace BlockHorizons\libschematic;

use pocketmine\block\Block;
use pocketmine\nbt\BigEndianNBTStream;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;

class Schematic{

	/**
	 * For schematics exported from Minecraft Pocket Edition
	 */
	const MATERIALS_POCKET = "Pocket";

	/**
	 * For schematics exported from Minecraft Alpha and newer
	 */
	const MATERIALS_ALPHA = "Alpha";

	/**
	 * For schematics exported from Minecraft Classic
	 */
	const MATERIALS_CLASSIC = "Classic";

	/**
	 * Fallback
	 */
	const MATERIALS_UNKNOWN = "Unknown";

	/** @var string */
	public $raw;

	/**
	 * Order YXZ:
	 * Height    - Along Y axis
	 * Width     - Along X axis
	 * Length    - Along Z axis
	 * @var int
	 */
	protected $height = 0, $width = 0, $length = 0;

	/** @var Block[] */
	protected $blocks = [];

	/** @var string */
	protected $materials = self::MATERIALS_UNKNOWN;

	/** @var CompoundTag */
	protected $entities;

	/** @var CompoundTag */
	protected $tileEntities;

	/** @var string */
	private $file;

	/** @var CompoundTag */
	private $compound;

	/**
	 * @param string $file the path of the Schematic file
	 */
	public function __construct(string $file = ""){
		if ($file !== ""){
			$data = file_get_contents($file);
			if (empty($data)){
				throw new \InvalidArgumentException("Failed to load Schematic data.");
			}
			$this->raw = $data;
			$this->file = $file;
		}
	}

	/**
	 * Save the Schematic to disk.
	 *
	 * @param string $file the Schematic output file name
	 */
	public function save(string $file = ""): void{
		if ($file === ""){
			file_put_contents($this->file, $this->raw);
			return;
		}
		file_put_contents($file, $this->raw);
	}

	/**
	 * Decodes the NBT data from the Schematic.
	 *
	 * @return $this
	 */
	public function decode(): self{
		$data = $this->getNBT();

		$this->decodeSizes();
		$this->materials = $data->getString("Materials");
		$this->entities = $data->getListTag("Entities");
		$this->tileEntities = $data->getListTag("TileEntities");

		$this->blocks = $this->decodeBlocks($data->getByteArray("Blocks"), $data->getByteArray("Data"), $this->height, $this->width, $this->length);
		return $this;
	}

	/**
	 * Reads the compressed NBT data from the Schematic, to be decoded later.
	 *
	 * @return CompoundTag
	 */
	public function getNBT(): CompoundTag{
		if($this->compound === null){
			$nbt = new BigEndianNBTStream();
			$this->compound = $nbt->readCompressed($this->raw);
		}
		return $this->compound;
	}

	public function decodeBlocks(string $blocks, string $meta, int $height, int $width, int $length): array{
		$bytes = array_values(unpack("c*", $blocks));
		$meta = array_values(unpack("c*", $meta));
		$realBlocks = [];
		for ($x = 0; $x < $width; $x++){
			for ($y = 0; $y < $height; $y++){
				for ($z = 0; $z < $length; $z++){
					$index = ($y * $length + $z) * $width + $x;
					$block = Block::get(($bytes[$index]??0) & 0xFF);
					$block->setComponents($x, $y, $z);
					if (isset($meta[$index])){
						$block->setDamage($meta[$index] & 0x0F);
					}
					$realBlocks[] = $block;
				}
			}
		}
		return $realBlocks;
	}

	/**
	 * Class properties into NBT -> Raw
	 */
	public function encode(): self{
		// Get real parameters from last block in the array
		$lb = array_reverse($this->blocks)[0] ?? null;
		$this->height = $lb ? $lb->y + 1 : $this->height;
		$this->width = $lb ? $lb->x + 1 : $this->width;
		$this->length = $lb ? $lb->z + 1 : $this->length;
		$encodedBlocks = $this->encodeBlocks($this->blocks, $this->height, $this->width, $this->length);

		$nbt = new BigEndianNBTStream;
		$nbtCompound = new CompoundTag("Schematic", [
			new ByteArrayTag("Blocks", $encodedBlocks[0]),
			new ByteArrayTag("Data", $encodedBlocks[1]),
			new ShortTag("Length", $this->length),
			new ShortTag("Width", $this->width),
			new ShortTag("Height", $this->height),
			new StringTag("Materials", self::MATERIALS_POCKET)
		]);
		$this->raw = $nbt->writeCompressed($nbtCompound);
		return $this;
	}

	/**
	 * @param Block[] $blocks
	 * @param int $height
	 * @param int $width
	 * @param int $length
	 *
	 * @return array
	 */
	public function encodeBlocks(array $blocks, int $height, int $width, int $length): array{
		$meta = "";
		$data = "";
		for ($x = 0; $x < $width; $x++){
			for ($y = 0; $y < $height; $y++){
				for ($z = 0; $z < $length; $z++){
					$index = ($y * $length + $z) * $width + $x;
					if (!isset($blocks[$index])){
						continue;
					}
					$block = $blocks[$index];
					$data .= pack("c", $block->getId());
					$meta .= pack("c", $block->getDamage() & 0x0F);
				}
			}
		}
		return [$data, $meta];
	}

	/**
	 * Replaces blocks that have different block IDs in Pocket Edition than PC Edition.
	 */
	public function fixBlockIds(): self{
		if ($this->materials === self::MATERIALS_POCKET){
			return $this;
		}
		foreach ($this->blocks as $key => $block){
			$replace = null;
			switch ($block->getId()){
				case 126:
					$replace = Block::get(Block::WOODEN_SLAB, $block->getDamage());
					break;
				case 125:
					$replace = Block::get(Block::DOUBLE_WOODEN_SLAB, $block->getDamage());
					break;
				case 188:
					$replace = Block::get(Block::FENCE, 1);
					break;
				case 189:
					$replace = Block::get(Block::FENCE, 2);
					break;
				case 190:
					$replace = Block::get(Block::FENCE, 3);
					break;
				case 191:
					$replace = Block::get(Block::FENCE, 5);
					break;
				case 192:
					$replace = Block::get(Block::FENCE, 4);
					break;
				default:
					break;
			}
			if ($replace){
				$replace->setComponents($block->x, $block->y, $block->z);
				$this->blocks[$key] = $replace;
			}
		}
		return $this;
	}

	/**
	 * Returns the file size of the Schematic file.
	 *
	 * @return int
	 */
	public function getSize(): int{
		return filesize($this->file);
	}

	/**
	 * Returns all blocks in the schematic.
	 *
	 * @return array
	 */
	public function getBlocks(): array{
		return $this->blocks;
	}

	/**
	 * NOTE: Blocks must follow YXZ order or you will corrupt the schematic file.
	 *
	 * @param Block[] $blocks
	 *
	 * @return $this
	 */
	public function setBlocks(array $blocks){
		$this->blocks = $blocks;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getMaterials(): string{
		return $this->materials;
	}

	/**
	 * @param string $materials
	 *
	 * @return $this
	 */
	public function setMaterials(string $materials){
		$this->materials = $materials;
		return $this;
	}

	/**
	 * Returns all entities in the schematic.
	 *
	 * @return CompoundTag
	 */
	public function getEntities(){
		return $this->entities;
	}

	/**
	 * @param $entities
	 *
	 * @return $this
	 */
	public function setEntities($entities){
		$this->entities = $entities;
		return $this;
	}

	/**
	 * @return CompoundTag
	 */
	public function getTileEntities(){
		return $this->tileEntities;
	}

	/**
	 * @param $entities
	 *
	 * @return $this
	 */
	public function setTileEntities($entities){
		$this->tileEntities = $entities;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getLength(): int{
		return $this->length;
	}

	/**
	 * @return int
	 */
	public function getHeight(): int{
		return $this->height;
	}

	/**
	 * @return int
	 */
	public function getWidth(): int{
		return $this->width;
	}

	/**
	 * @param int $length
	 *
	 * @return $this
	 */
	public function setLength(int $length){
		$this->length = $length;

		return $this;
	}

	/**
	 * @param int $height
	 *
	 * @return $this
	 */
	public function setHeight(int $height){
		$this->height = $height;

		return $this;
	}

	/**
	 * @param int $width
	 *
	 * @return $this
	 */
	public function setWidth(int $width){
		$this->width = $width;

		return $this;
	}

	public function decodeSizes(){
		$data = $this->getNBT();
		$this->width = $data->getShort("Width");
		$this->height = $data->getShort("Height");
		$this->length = $data->getShort("Length");
	}
}
