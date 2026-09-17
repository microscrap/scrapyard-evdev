<?php

namespace Microscrap\ScrapyardEvdev;

use Closure;
use Microscrap\Bindings\Evdev\Enums\EventType;
use Microscrap\Bindings\Evdev\Enums\KeyCode;
use Microscrap\Bindings\Evdev\EvdevDevice;

/**
 * Finds gamepads among the event nodes of a directory. A node is a gamepad
 * when its EV_KEY bits include BTN_SOUTH (the kernel's BTN_GAMEPAD). The bits
 * come from sysfs when it answers (no open: an evdev close costs the kernel
 * 15-45 ms), otherwise from a probe that is always closed. Rescanning on a
 * schedule is the consumer's job.
 */
final class EvdevScanner
{
    /** @var Closure(string): ?EvdevDevice */
    private readonly Closure $open;

    /** @var null|Closure(string): ?string */
    private readonly ?Closure $capabilities;

    /**
     * @param null|Closure(string): ?EvdevDevice $open defaults to EvdevDevice::open(...)
     * @param null|Closure(string): ?string $capabilities node basename → its sysfs `capabilities/key` text, null when unknown;
     *        defaults to /sys/class/input when $open is the default, otherwise every node is probed
     */
    public function __construct(private readonly string $directory = '/dev/input', ?Closure $open = null, ?Closure $capabilities = null)
    {
        $this->capabilities = $capabilities ?? (is_null($open) ? self::sysfsKeys(...) : null);
        $this->open = $open ?? EvdevDevice::open(...);
    }

    /** @return list<string> device node paths whose EV_KEY bits include BTN_SOUTH, natural-sorted */
    public function gamepads(): array
    {
        $entries = is_dir($this->directory) ? scandir($this->directory) : false;

        if ($entries === false) {
            return [];
        }

        $nodes = array_values(array_filter($entries, fn (string $entry): bool => str_starts_with($entry, 'event')));
        natsort($nodes);

        $found = [];

        foreach ($nodes as $node) {
            $path = rtrim($this->directory, '/').'/'.$node;
            $keys = is_null($this->capabilities) ? null : ($this->capabilities)($node);

            if (is_string($keys)) {
                if (self::hasBit($keys, KeyCode::BTN_SOUTH->value)) {
                    $found[] = $path;
                }

                continue;
            }

            $device = ($this->open)($path);

            if (is_null($device)) {
                continue;
            }

            if ($device->supports(EventType::KEY, KeyCode::BTN_SOUTH->value)) {
                $found[] = $path;
            }

            $device->close();
        }

        return $found;
    }

    /**
     * @internal sysfs prints a bitmap as hex words, most significant first, one kernel long each
     */
    public static function hasBit(string $bitmap, int $bit, int $word_bits = PHP_INT_SIZE * 8): bool
    {
        $words = array_reverse(preg_split('/\s+/', trim($bitmap)) ?: []);
        $word = $words[intdiv($bit, $word_bits)] ?? null;

        if (is_null($word) || ! ctype_xdigit($word)) {
            return false;
        }

        $offset = $bit % $word_bits;
        $hex = str_pad($word, intdiv($word_bits, 4), '0', STR_PAD_LEFT);
        $nibble = hexdec($hex[strlen($hex) - 1 - intdiv($offset, 4)]);

        return ($nibble & (1 << ($offset % 4))) !== 0;
    }

    private static function sysfsKeys(string $node): ?string
    {
        $file = "/sys/class/input/{$node}/device/capabilities/key";
        $text = is_readable($file) ? @file_get_contents($file) : false;

        return is_string($text) ? $text : null;
    }

    /** @throws EvdevGamepadException when the node opens but answers no probe; the device is closed first */
    public function open(string $path): ?EvdevGamepad
    {
        $device = ($this->open)($path);

        if (is_null($device)) {
            return null;
        }

        try {
            return new EvdevGamepad($device, basename($path));
        } catch (EvdevGamepadException $e) {
            $device->close();

            throw $e;
        }
    }
}
