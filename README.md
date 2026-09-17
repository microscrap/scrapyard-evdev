# microscrap/scrapyard-evdev — Linux evdev gamepads as Surface input circuits

[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Turns `/dev/input/event*` gamepads into Venusian Surface `GameController` circuits. Built on the [`microscrap/evdev`](https://github.com/microscrap/evdev) bindings: this package adds the opinions — which nodes are gamepads, the positional button map, normalised axes, the Z/RZ rule, hot-unplug.

Linux only. No service provider: construct it yourself.

## Requirements

* PHP `^8.4|^8.5|^8.6`
* Linux with `/dev/input/event*`; runtime user in the `input` group
* **ext-posi** `^0.8.0`
* `microscrap/evdev`, `surface/contracts`, `surface/human-input` `^0.8.0`

## Installation

```bash
composer require microscrap/scrapyard-evdev
```

## Usage

```php
use Microscrap\ScrapyardEvdev\EvdevScanner;
use Surface\Contracts\HumanInput\GamepadAxis;
use Surface\Contracts\HumanInput\GamepadButton;

$scanner = new EvdevScanner();              // '/dev/input'

foreach ($scanner->gamepads() as $path) {   // ['/dev/input/event3', ...]
    $pad = $scanner->open($path);           // EvdevGamepad, id 'event3'; null if it won't open
}

// each tick — never waits
$pad->poll();

if (! $pad->connected()) {
    $pad->close();                          // unplugged: rescan and reopen
}

$pad->isPressed(GamepadButton::SOUTH);
$pad->axis(GamepadAxis::LEFT_X);            // −1…1, 0.0 inside the flat zone
$pad->axis(GamepadAxis::RIGHT_TRIGGER);     // 0…1
```

Hand a pad to Surface: `$manager->attach($pad, 'player1')`.

## Mapping

* Buttons by position: `BTN_SOUTH/EAST/NORTH/WEST`, `BTN_TL/TR` → shoulders, `BTN_SELECT` → BACK, `BTN_START`, `BTN_MODE` → GUIDE, `BTN_THUMBL/R` → stick clicks, `BTN_DPAD_*`.
* `ABS_HAT0X/Y` → d-pad buttons.
* `ABS_X/Y` → left stick, `ABS_RX/RY` → right stick.
* `ABS_Z/RZ` → triggers when the pad has `ABS_RX`, else the right stick (DirectInput layout).
* `BTN_TL2/TR2` → full-scale triggers on pads without analog ones.

Details: `.okf/gamepads.md` in the source repo.

## Testing

```bash
composer install
vendor/bin/pest
```

Tests run without a kernel: `tests/Support/ScriptedEvdevDevice.php` scripts capabilities and events.

## License

MIT. See [LICENSE](LICENSE).
