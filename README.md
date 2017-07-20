# libschematic

### Implementing into plugins
Best way to implement this code, is to use it as a virion. 
You can also add it as git submodule
```bash
cd ~/PocketMine-MP/plugins/MyPlugin/src
git submodule add -b master https://github.com/BlockHorizons/Schematics-PHP.git schematic
git submodule update
```

### Using 

#### Loading schematic file

```php
try {
  $fileContents = file_get_contents("castle.schematic");
  $schematic = new \BlockHorizons\libschematic\Schematic($fileContents);
  $schematic->decode();
} catch (\Throwable $error) {
  // Handle error
}
```

#### Saving schematic file

```php
try {
  $schematic->encode();
  file_put_contents("castle.schematic", $schematic->raw);
} catch (\Throwable $error) {
  // Handle error
}
```

#### Pasting

```php
$target = $player->getPosition();
foreach($schematic->getBlocks() as $block) {
	$target->level->setBlock($target->add($block), $block);
}
```

__NOTE__: TO FIX BLOCK IDs YOU MUST CALL ```Schematic::fixBlockIds()```
