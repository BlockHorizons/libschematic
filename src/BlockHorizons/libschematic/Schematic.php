<?php

declare(strict_types=1);

namespace BlockHorizons\libschematic;

use pocketmine\block\Block;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\BlockStateDeserializeException;
use pocketmine\data\bedrock\block\BlockStateSerializeException;
use pocketmine\data\bedrock\block\upgrade\LegacyBlockIdToStringIdMap;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use function chr;
use function file_get_contents;
use function file_put_contents;
use function ksort;
use function ord;
use function serialize;
use function str_repeat;
use function strlen;
use function zlib_decode;
use function zlib_encode;

use const ZLIB_ENCODING_GZIP;

class Schematic{

	/** @var array<int, array{int, int}> */
	private static array $stateIdToLegacyCache = [];

	/** @var array<string, array{int, int}> */
	private static array $stateKeyToLegacy = [];

	private static bool $legacyReverseMapLoaded = false;

	/**
	 * For schematics exported from Minecraft Pocket Edition
	 */
	public const MATERIALS_POCKET = "Pocket";

	/**
	 * For schematics exported from Minecraft Alpha and newer
	 */
	public const MATERIALS_ALPHA = "Alpha";

	/**
	 * For schematics exported from Minecraft Classic
	 */
	public const MATERIALS_CLASSIC = "Classic";

	/**
	 * Fallback
	 */
	public const MATERIALS_UNKNOWN = "Unknown";

	/**
	 * Order YXZ:
	 * Height    - Along Y axis
	 * Width     - Along X axis
	 * Length    - Along Z axis
	 * @var int
	 */
	protected $height = 0, $width = 0, $length = 0;

	/** @var string */
	protected $blocks = "";
	/** @var string */
	protected $data = "";

	/** @var string */
	protected $materials = self::MATERIALS_UNKNOWN;

	/**
	 * save saves a schematic to disk.
	 *
	 * @param string $file the Schematic output file name
	 */
	public function save(string $file) : void{
		$nbt = new TreeRoot(
			CompoundTag::create()
				->setByteArray("Blocks", $this->blocks)
				->setByteArray("Data", $this->data)
				->setShort("Length", $this->length)
				->setShort("Width", $this->width)
				->setShort("Height", $this->height)
				->setString("Materials", self::MATERIALS_POCKET)
		);
		//NOTE: Save after encoding with zlib_encode for backward compatibility.
		file_put_contents($file, zlib_encode((new BigEndianNbtSerializer())->write($nbt), ZLIB_ENCODING_GZIP));
	}

	/**
	 * parse parses a schematic from the file passed.
	 *
	 * @param string $file
	 */
	public function parse(string $file) : void{
		$tree = (new BigEndianNbtSerializer())->read(zlib_decode(file_get_contents($file)));
		$nbt = $tree->mustGetCompoundTag();

		$this->materials = $nbt->getString("Materials");

		$this->height = $nbt->getShort("Height");
		$this->width = $nbt->getShort("Width");
		$this->length = $nbt->getShort("Length");

		$this->blocks = $nbt->getByteArray("Blocks");
		$this->data = $nbt->getByteArray("Data");
	}

	/**
	 * blocks returns a generator of blocks found in the schematic opened.
	 *
	 * @return \Generator
	 */
	public function blocks() : \Generator{
		$stateRegistry = RuntimeBlockStateRegistry::getInstance();
		$blockStateUpgrader = GlobalBlockStateHandlers::getUpgrader();
		$deserializer = GlobalBlockStateHandlers::getDeserializer();
		$unknownStateId = $deserializer->deserialize(GlobalBlockStateHandlers::getUnknownBlockStateData());

		for($x = 0; $x < $this->width; $x++){
			for($z = 0; $z < $this->length; $z++){
				for($y = 0; $y < $this->height; $y++){
					$index = $this->blockIndex($x, $y, $z);
					$id = isset($this->blocks[$index]) ? ord($this->blocks[$index]) & 0xff : 0;
					$data = isset($this->data[$index]) ? ord($this->data[$index]) & 0x0f : 0;
					try{
						$blockStateId = $deserializer->deserialize($blockStateUpgrader->upgradeIntIdMeta($id, $data));
					}catch(BlockStateDeserializeException){
						$blockStateId = $unknownStateId;
					}
					$block = $stateRegistry->fromStateId($blockStateId);
					$position = $block->getPosition();
					$position->x = $x;
					$position->y = $y;
					$position->z = $z;
					$position->world = null;
					yield $block;
				}
			}
		}
	}

	/**
	 * setBlocks sets a generator of blocks to a schematic, using a bounding box to calculate the size.
	 *
	 * @param            $bb AxisAlignedBB
	 * @param iterable<Block> $blocks
	 */
	public function setBlocks(AxisAlignedBB $bb, iterable $blocks) : void{
		$offset = new Vector3((int) $bb->minX, (int) $bb->minY, (int) $bb->minZ);
		$max = new Vector3((int) $bb->maxX, (int) $bb->maxY, (int) $bb->maxZ);

		$this->width = $max->x - $offset->x + 1;
		$this->length = $max->z - $offset->z + 1;
		$this->height = $max->y - $offset->y + 1;

		foreach($blocks as $block){
			if(!$block instanceof Block){
				throw new \InvalidArgumentException("setBlocks() expects only Block instances");
			}
			$pos = $block->getPosition()->subtractVector($offset);
			$index = $this->blockIndex($pos->x, $pos->y, $pos->z);
			if(strlen($this->blocks) <= $index){
				$this->blocks .= str_repeat(chr(0), $index - strlen($this->blocks) + 1);
			}
			if(strlen($this->data) <= $index){
				$this->data .= str_repeat(chr(0), $index - strlen($this->data) + 1);
			}
			[$legacyId, $legacyMeta] = $this->mapBlockToLegacy($block);
			$this->blocks[$index] = chr($legacyId & 0xff);
			$this->data[$index] = chr($legacyMeta & 0x0f);
		}
	}

	/**
	 * setBlockArray sets a block array to a schematic. The bounds of the schematic are calculated manually.
	 *
	 * @param Block[] $blocks
	 */
	public function setBlockArray(array $blocks) : void{
		$min = new Vector3(0, 0, 0);
		$max = new Vector3(0, 0, 0);
		foreach($blocks as $block){
			$blockPos = $block->getPosition();
			if($blockPos->x < $min->x){
				$min->x = $blockPos->x;
			}elseif($blockPos->x > $max->x){
				$max->x = $blockPos->x;
			}
			if($blockPos->y < $min->y){
				$min->y = $blockPos->y;
			}elseif($blockPos->y > $max->y){
				$max->y = $blockPos->y;
			}
			if($blockPos->z < $min->z){
				$min->z = $blockPos->z;
			}elseif($blockPos->z > $max->z){
				$max->z = $blockPos->z;
			}
		}
		$this->height = $max->y - $min->y + 1;
		$this->width = $max->x - $min->x + 1;
		$this->length = $max->z - $min->z + 1;

		foreach($blocks as $block){
			$pos = $block->getPosition()->subtractVector($min);
			$index = $this->blockIndex($pos->x, $pos->y, $pos->z);
			if(strlen($this->blocks) <= $index){
				$this->blocks .= str_repeat(chr(0), $index - strlen($this->blocks) + 1);
			}
			if(strlen($this->data) <= $index){
				$this->data .= str_repeat(chr(0), $index - strlen($this->data) + 1);
			}
			[$legacyId, $legacyMeta] = $this->mapBlockToLegacy($block);
			$this->blocks[$index] = chr($legacyId & 0xff);
			$this->data[$index] = chr($legacyMeta & 0x0f);
		}
	}

	/**
	 * @return array{int, int}
	 */
	private function mapBlockToLegacy(Block $block) : array{
		$stateId = $block->getStateId();
		if(isset(self::$stateIdToLegacyCache[$stateId])){
			return self::$stateIdToLegacyCache[$stateId];
		}

		self::ensureLegacyReverseMapLoaded();
		$serializer = GlobalBlockStateHandlers::getSerializer();
		try{
			$stateData = $serializer->serialize($stateId);
		}catch(BlockStateSerializeException){
			return self::$stateIdToLegacyCache[$stateId] = [0, 0];
		}
		$key = self::blockStateDataKey($stateData);
		return self::$stateIdToLegacyCache[$stateId] = self::$stateKeyToLegacy[$key] ?? [0, 0];
	}

	private static function ensureLegacyReverseMapLoaded() : void{
		if(self::$legacyReverseMapLoaded){
			return;
		}
		self::$legacyReverseMapLoaded = true;

		$legacyIds = LegacyBlockIdToStringIdMap::getInstance()->getLegacyToStringMap();
		$blockDataUpgrader = GlobalBlockStateHandlers::getUpgrader();
		foreach(array_keys($legacyIds) as $legacyId){
			for($meta = 0; $meta <= 0x0f; $meta++){
				try{
					$stateData = $blockDataUpgrader->upgradeIntIdMeta($legacyId, $meta);
				}catch(BlockStateDeserializeException){
					continue;
				}
				$key = self::blockStateDataKey($stateData);
				if(!isset(self::$stateKeyToLegacy[$key])){
					self::$stateKeyToLegacy[$key] = [$legacyId, $meta];
				}
			}
		}
	}

	private static function blockStateDataKey(BlockStateData $data) : string{
		$stateValues = [];
		foreach(Utils::stringifyKeys($data->getStates()) as $name => $tag){
			$stateValues[$name] = $tag->getValue();
		}
		ksort($stateValues);
		return $data->getName() . ':' . serialize($stateValues);
	}

	/**
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 *
	 * @return int
	 */
	protected function blockIndex(int $x, int $y, int $z) : int{
		return ($y * $this->length + $z) * $this->width + $x;
	}

	/**
	 * @return string
	 */
	public function getMaterials() : string{
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
	 * @return int
	 */
	public function getLength() : int{
		return $this->length;
	}

	/**
	 * @return int
	 */
	public function getHeight() : int{
		return $this->height;
	}

	/**
	 * @return int
	 */
	public function getWidth() : int{
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
}
