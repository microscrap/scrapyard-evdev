<?php

namespace Microscrap\ScrapyardEvdev\Tests\Support;

use Microscrap\Bindings\Evdev\DataObjects\AbsInfo;
use Microscrap\Bindings\Evdev\DataObjects\InputEvent;
use Microscrap\Bindings\Evdev\Enums\AbsCode;
use Microscrap\Bindings\Evdev\Enums\EventType;
use Microscrap\Bindings\Evdev\Enums\KeyCode;
use Microscrap\Bindings\Evdev\Enums\PollFlag;
use Microscrap\Bindings\Evdev\Enums\SynCode;
use Microscrap\Bindings\Evdev\EvdevDevice;

/** An EvdevDevice with scripted capabilities and events; never touches a file descriptor. */
class ScriptedEvdevDevice extends EvdevDevice
{
    /** @var list<list<InputEvent>> one list per read() */
    public array $queue = [];

    public int $revents = PollFlag::POLLIN->value;

    public bool $closed = false;

    /** @var list<KeyCode> what EVIOCGKEY reports held */
    public array $held = [];

    /** @var array<int, int> current EVIOCGABS value by AbsCode value */
    public array $values = [];

    public int $reads = 0;

    /**
     * @param list<KeyCode> $keys
     * @param array<int, AbsInfo> $abs keyed by AbsCode value
     */
    public function __construct(
        private readonly array $keys = [],
        private readonly array $abs = [],
        private readonly string $name = '',
        string $path = '/dev/input/event3',
    ) {
        parent::__construct(-1, $path);
    }

    public static function key(KeyCode $code, bool $down): InputEvent
    {
        return new InputEvent(0, 0, EventType::KEY->value, $code->value, $down ? 1 : 0);
    }

    public static function abs(AbsCode $code, int $value): InputEvent
    {
        return new InputEvent(0, 0, EventType::ABS->value, $code->value, $value);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function supports(EventType $type, int $code): bool
    {
        return match ($type) {
            EventType::KEY => in_array($code, array_map(fn (KeyCode $k): int => $k->value, $this->keys), true),
            EventType::ABS => isset($this->abs[$code]),
            default => false,
        };
    }

    public static function syn(SynCode $code): InputEvent
    {
        return new InputEvent(0, 0, EventType::SYN->value, $code->value, 0);
    }

    public function keyDown(int $code): bool
    {
        return in_array($code, array_map(fn (KeyCode $k): int => $k->value, $this->held), true);
    }

    public function absInfo(AbsCode|int $code): ?AbsInfo
    {
        $code = $code instanceof AbsCode ? $code->value : $code;
        $info = $this->abs[$code] ?? null;

        if (is_null($info) || ! isset($this->values[$code])) {
            return $info;
        }

        return new AbsInfo($this->values[$code], $info->minimum, $info->maximum, $info->fuzz, $info->flat, $info->resolution);
    }

    public function revents(): int
    {
        return $this->revents;
    }

    /** @return list<InputEvent> the next scripted batch, at most $max_events of it; the rest stays queued */
    public function read(int $max_events = 64): array
    {
        $this->reads++;
        $batch = array_shift($this->queue) ?? [];

        if (count($batch) > $max_events) {
            array_unshift($this->queue, array_slice($batch, $max_events));
            $batch = array_slice($batch, 0, $max_events);
        }

        return $batch;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
