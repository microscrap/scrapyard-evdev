<?php

use Microscrap\Bindings\Evdev\Enums\KeyCode;
use Microscrap\Bindings\Evdev\EvdevDevice;
use Microscrap\ScrapyardEvdev\EvdevGamepad;
use Microscrap\ScrapyardEvdev\EvdevGamepadException;
use Microscrap\ScrapyardEvdev\EvdevScanner;
use Microscrap\ScrapyardEvdev\Tests\Support\ScriptedEvdevDevice as Dev;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/evdev-'.uniqid();
    mkdir($this->dir);

    foreach (['event0', 'event2', 'event10', 'mouse0', 'js0'] as $node) {
        touch("{$this->dir}/{$node}");
    }

    /** @var list<Dev> $probed */
    $this->probed = [];
    $this->open = function (string $path): ?EvdevDevice {
        $node = basename($path);
        expect($node)->toStartWith('event');

        $dev = match ($node) {
            'event2', 'event10' => new Dev(keys: [KeyCode::BTN_SOUTH], name: "Pad {$node}", path: $path),
            default => new Dev(name: 'Keyboard', path: $path),
        };
        $this->probed[] = $dev;

        return $dev;
    };
});

afterEach(function (): void {
    array_map('unlink', glob("{$this->dir}/*"));
    rmdir($this->dir);
});

it('lists the event nodes that have a south button, in natural order, closing every probe', function (): void {
    $scanner = new EvdevScanner($this->dir, $this->open);

    expect($scanner->gamepads())->toBe(["{$this->dir}/event2", "{$this->dir}/event10"])
        ->and($this->probed)->toHaveCount(3)
        ->and(array_filter($this->probed, fn (Dev $d): bool => ! $d->closed))->toBe([]);
});

it('opens a node as a gamepad named after its basename', function (): void {
    $pad = (new EvdevScanner($this->dir, $this->open))->open("{$this->dir}/event2");

    expect($pad)->toBeInstanceOf(EvdevGamepad::class)
        ->and($pad->id)->toBe('event2')
        ->and($pad->name())->toBe('Pad event2')
        ->and($pad->path())->toBe("{$this->dir}/event2");
});

it('answers null when the node cannot be opened', function (): void {
    $scanner = new EvdevScanner($this->dir, fn (string $path): ?EvdevDevice => null);

    expect($scanner->open("{$this->dir}/event2"))->toBeNull()
        ->and($scanner->gamepads())->toBe([]);
});

it('closes a node that answers no probe before rethrowing', function (): void {
    $dev = new Dev();
    $scanner = new EvdevScanner($this->dir, fn (string $path): ?EvdevDevice => $dev);

    expect(fn () => $scanner->open("{$this->dir}/event0"))->toThrow(EvdevGamepadException::class)
        ->and($dev->closed)->toBeTrue();
});

it('lists nothing for a directory that does not exist', function (): void {
    expect((new EvdevScanner("{$this->dir}/missing", $this->open))->gamepads())->toBe([]);
});

it('reads BTN_SOUTH from sysfs capabilities without opening the node', function (): void {
    $sysfs = ['event0' => "120013\n", 'event2' => '7fdb000000000000 0 0 0 0', 'event10' => null];
    $scanner = new EvdevScanner($this->dir, $this->open, fn (string $node): ?string => $sysfs[$node]);

    expect($scanner->gamepads())->toBe(["{$this->dir}/event2", "{$this->dir}/event10"])
        ->and(array_map(fn (Dev $d): string => basename($d->path), $this->probed))->toBe(['event10']);
});

it('tests one bit of a sysfs bitmap', function (string $bitmap, int $bit, int $word_bits, bool $set): void {
    expect(EvdevScanner::hasBit($bitmap, $bit, $word_bits))->toBe($set);
})->with([
    'south on a 64-bit pad' => ['7fdb000000000000 0 0 0 0', 304, 64, true],
    'east on a 64-bit pad' => ['7fdb000000000000 0 0 0 0', 305, 64, true],
    'bit 306 clear' => ['7fdb000000000000 0 0 0 0', 306, 64, false],
    'keyboard has no south' => ['10000 00000007 ff9f207a c14057ff febeffdf ffefffff ffffffff fffffffe', 304, 32, false],
    'south on a 32-bit pad' => ['7fdb0000 0 0 0 0 0 0 0 0 0', 304, 32, true],
    'short map' => ['120013', 304, 64, false],
    'empty' => ['0', 0, 64, false],
    'low bit' => ['1', 0, 64, true],
]);
