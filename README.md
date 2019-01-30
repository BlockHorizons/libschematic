# libschematic
A library for creating and manipulating MCEdit Schematic files.  

### Implementing into plugins
Best way to implement this code is to use it as a git module or Poggit virion. 

### Usage

#### Loading schematic files

```php
try {
	$schematic = new Schematic();
	$schematic->parse("castle.schematic");
} catch (\Throwable $error) {
	// Handle error
}
```

#### Filling schematics
```php
$schematic = new Schematic();
$boundingBox = new AxisAlignedBB();

// For generator block providers, a bounding box is required as the size is unknown in advance.
$schematic->setBlocks($boundingBox, $blockGenerator);

$blocks = [];

// For array block providers, the bounding box is calculated automatically.
$schematic->setBlockArray($blocks);
```

#### Saving schematic files

```php
try {
    $schematic = new Schematic();
	$schematic->save("castle.schematic");
} catch (\Throwable $error) {
	// Handle error
}
```

#### Pasting schematics

```php
$target = $player->getPosition();
foreach($schematic->blocks() as $block) {
	$target->level->setBlock($target->add($block), $block);
}
```



