# libschematic
A library for creating and manipulating MCEdit Schematic files.  

### Implementing into plugins
Best way to implement this code, is to use it as a virion. 

See [the Virion documentation](https://github.com/poggit/support/blob/master/virion.md) for how to include virions into your plugins.  

### Using 

#### Loading schematic file

```php
try {
	$schematic = new \libschematic\Schematic("castle.schematic");
	$schematic->decode();
} catch (\Throwable $error) {
	// Handle error
}
```

#### Saving schematic file

```php
try {
	$schematic->encode();
	$schematic->save("castle.schematic"); // With custom name
	$schematic->save(); // With the name you used before
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

#### Fixing Block IDs
If you have blocks not currently in PMMP/MCPE, you will need to call the following after loading a schematic:
```php
$schematic->fixBlockIds();
```

#### I'm a fluent person
So am I!

```php
try {
	new \libschematic\Schematic("castle.schematic")
		->decode()
		->setBlocks(...)
		->setEntities(...)
		->save();
} catch (\Throwable $error) {
	// Handle error
}
```
