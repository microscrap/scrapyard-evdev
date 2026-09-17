<?php

namespace Microscrap\ScrapyardEvdev;

use Microscrap\Bindings\Evdev\DataObjects\AbsInfo;
use Microscrap\Bindings\Evdev\DataObjects\InputEvent;
use Microscrap\Bindings\Evdev\Enums\AbsCode;
use Microscrap\Bindings\Evdev\Enums\EventType;
use Microscrap\Bindings\Evdev\Enums\KeyCode;
use Microscrap\Bindings\Evdev\Enums\PollFlag;
use Microscrap\Bindings\Evdev\Enums\SynCode;
use Microscrap\Bindings\Evdev\EvdevDevice;
use Surface\Contracts\HumanInput\Circuits\GameController;
use Surface\Contracts\HumanInput\GamepadAxis;
use Surface\Contracts\HumanInput\GamepadButton;
use Surface\HumanInput\Devices\DigitalButton;

/**
 * One evdev node as a Surface game controller. Capabilities are read once,
 * here; poll() never waits. Buttons map by position (BTN_SOUTH is SOUTH
 * whatever the label says). ABS_Z / ABS_RZ are triggers on a pad with ABS_RX,
 * the right stick otherwise. poll() drains the node (a busy pad queues
 * thousands of events a second); after SYN_DROPPED it skips to the next
 * SYN_REPORT and resyncs from the kernel's held keys and axis values. A
 * hang-up disconnects for good: rescan and reopen to get the pad back.
 */
final class EvdevGamepad implements GameController
{
    /** @var array<string, DigitalButton> keyed by GamepadButton value */
    private array $buttons = [];

    /** @var array<int, GamepadButton> EV_KEY code => button */
    private array $key_buttons = [];

    /** @var array<int, GamepadAxis> EV_KEY code => trigger axis it saturates */
    private array $key_triggers = [];

    /** @var array<int, AbsInfo> EV_ABS code => range, for every axis the pad has */
    private array $ranges = [];

    /** @var array<int, GamepadAxis> EV_ABS code => axis */
    private array $abs_axes = [];

    /** @var array<string, float> keyed by GamepadAxis value */
    private array $axes = [];

    private bool $hat = false;

    private bool $connected = true;

    private bool $closed = false;

    /** Events were dropped; skip to the next SYN_REPORT, then resync. */
    private bool $dropping = false;

    /** read() batches per poll before the rest waits for the next tick. */
    private const int MAX_BATCHES = 64;

    private const int BATCH = 64;

    public function __construct(private readonly EvdevDevice $device, public readonly string $id)
    {
        $name = $device->name();

        foreach (self::positions() as $code => $button) {
            if ($device->supports(EventType::KEY, $code)) {
                $this->key_buttons[$code] = $button;
            }
        }

        foreach (AbsCode::cases() as $code) {
            $info = $device->absInfo($code);

            if (! is_null($info) && $info->maximum > $info->minimum) {
                $this->ranges[$code->value] = $info;
            }
        }

        $this->hat = isset($this->ranges[AbsCode::HAT0X->value]) || isset($this->ranges[AbsCode::HAT0Y->value]);
        $analog_triggers = isset($this->ranges[AbsCode::RX->value]);

        $routes = [
            AbsCode::X->value => GamepadAxis::LEFT_X,
            AbsCode::Y->value => GamepadAxis::LEFT_Y,
            AbsCode::RX->value => GamepadAxis::RIGHT_X,
            AbsCode::RY->value => GamepadAxis::RIGHT_Y,
            AbsCode::Z->value => $analog_triggers ? GamepadAxis::LEFT_TRIGGER : GamepadAxis::RIGHT_X,
            AbsCode::RZ->value => $analog_triggers ? GamepadAxis::RIGHT_TRIGGER : GamepadAxis::RIGHT_Y,
        ];

        foreach ($routes as $code => $axis) {
            if (isset($this->ranges[$code])) {
                $this->abs_axes[$code] = $axis;
                $this->axes[$axis->value] = 0.0;
            }
        }

        foreach ([KeyCode::BTN_TL2->value => GamepadAxis::LEFT_TRIGGER, KeyCode::BTN_TR2->value => GamepadAxis::RIGHT_TRIGGER] as $code => $axis) {
            if (! isset($this->axes[$axis->value]) && $device->supports(EventType::KEY, $code)) {
                $this->key_triggers[$code] = $axis;
                $this->axes[$axis->value] = 0.0;
            }
        }

        if ($name === '' && $this->key_buttons === [] && $this->key_triggers === [] && $this->ranges === []) {
            throw EvdevGamepadException::unreadable($id);
        }

        foreach (GamepadButton::cases() as $button) {
            if ($this->supports($button)) {
                $this->buttons[$button->value] = new DigitalButton($button->value);
            }
        }
    }

    public function name(): string
    {
        return $this->device->name();
    }

    public function path(): string
    {
        return $this->device->path;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->disconnect();
        $this->device->close();
    }

    public function poll(): static
    {
        foreach ($this->buttons as $button) {
            $button->settle();
        }

        if (! $this->connected) {
            return $this;
        }

        if ($this->device->revents() & (PollFlag::POLLERR->value | PollFlag::POLLHUP->value)) {
            $this->disconnect();

            return $this;
        }

        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $events = $this->device->read(self::BATCH);

            foreach ($events as $event) {
                $this->fold($event);
            }

            if (count($events) < self::BATCH) {
                break;
            }
        }

        return $this;
    }

    public function connected(): bool
    {
        return $this->connected;
    }

    public function supports(GamepadButton $button): bool
    {
        if (in_array($button, $this->key_buttons, true)) {
            return true;
        }

        return $this->hat && in_array($button, [GamepadButton::DPAD_UP, GamepadButton::DPAD_DOWN, GamepadButton::DPAD_LEFT, GamepadButton::DPAD_RIGHT], true);
    }

    public function isDown(GamepadButton $button): bool
    {
        return isset($this->buttons[$button->value]) && $this->buttons[$button->value]->isDown();
    }

    public function isPressed(GamepadButton $button): bool
    {
        return isset($this->buttons[$button->value]) && $this->buttons[$button->value]->isPressed();
    }

    public function wasReleased(GamepadButton $button): bool
    {
        return isset($this->buttons[$button->value]) && $this->buttons[$button->value]->wasReleased();
    }

    public function isHolding(GamepadButton $button, int $hold_ms): bool
    {
        return isset($this->buttons[$button->value]) && $this->buttons[$button->value]->isHolding($hold_ms);
    }

    /** @return list<GamepadAxis> */
    public function supportedAxes(): array
    {
        return array_values(array_filter(GamepadAxis::cases(), fn (GamepadAxis $axis): bool => isset($this->axes[$axis->value])));
    }

    public function axis(GamepadAxis $axis): float
    {
        return $this->axes[$axis->value] ?? 0.0;
    }

    /** @return array<int, GamepadButton> EV_KEY code => button, by position */
    private static function positions(): array
    {
        return [
            KeyCode::BTN_SOUTH->value => GamepadButton::SOUTH,
            KeyCode::BTN_EAST->value => GamepadButton::EAST,
            KeyCode::BTN_NORTH->value => GamepadButton::NORTH,
            KeyCode::BTN_WEST->value => GamepadButton::WEST,
            KeyCode::BTN_TL->value => GamepadButton::LEFT_SHOULDER,
            KeyCode::BTN_TR->value => GamepadButton::RIGHT_SHOULDER,
            KeyCode::BTN_SELECT->value => GamepadButton::BACK,
            KeyCode::BTN_START->value => GamepadButton::START,
            KeyCode::BTN_MODE->value => GamepadButton::GUIDE,
            KeyCode::BTN_THUMBL->value => GamepadButton::LEFT_STICK,
            KeyCode::BTN_THUMBR->value => GamepadButton::RIGHT_STICK,
            KeyCode::BTN_DPAD_UP->value => GamepadButton::DPAD_UP,
            KeyCode::BTN_DPAD_DOWN->value => GamepadButton::DPAD_DOWN,
            KeyCode::BTN_DPAD_LEFT->value => GamepadButton::DPAD_LEFT,
            KeyCode::BTN_DPAD_RIGHT->value => GamepadButton::DPAD_RIGHT,
        ];
    }

    private function fold(InputEvent $event): void
    {
        if ($event->type === EventType::SYN->value) {
            if ($event->code === SynCode::DROPPED->value) {
                $this->dropping = true;
            } elseif ($event->code === SynCode::REPORT->value && $this->dropping) {
                $this->dropping = false;
                $this->resync();
            }

            return;
        }

        if ($this->dropping) {
            return;
        }

        if ($event->type === EventType::KEY->value) {
            $this->foldKey($event->code, $event->value !== 0);
        } elseif ($event->type === EventType::ABS->value) {
            $this->foldAbs($event->code, $event->value);
        }
    }

    /** Held keys and axis values straight from the kernel, as if each had just been reported. */
    private function resync(): void
    {
        foreach ([...array_keys($this->key_buttons), ...array_keys($this->key_triggers)] as $code) {
            $this->foldKey($code, $this->device->keyDown($code));
        }

        foreach (array_keys($this->ranges) as $code) {
            $info = $this->device->absInfo($code);

            if (! is_null($info)) {
                $this->foldAbs($code, $info->value);
            }
        }
    }

    private function foldKey(int $code, bool $down): void
    {
        if (isset($this->key_buttons[$code])) {
            $this->buttons[$this->key_buttons[$code]->value]->update($down);
        } elseif (isset($this->key_triggers[$code])) {
            $this->axes[$this->key_triggers[$code]->value] = $down ? 1.0 : 0.0;
        }
    }

    private function foldAbs(int $code, int $value): void
    {
        if ($code === AbsCode::HAT0X->value && $this->hat) {
            $this->buttons[GamepadButton::DPAD_LEFT->value]->update($value < 0);
            $this->buttons[GamepadButton::DPAD_RIGHT->value]->update($value > 0);
        } elseif ($code === AbsCode::HAT0Y->value && $this->hat) {
            $this->buttons[GamepadButton::DPAD_UP->value]->update($value < 0);
            $this->buttons[GamepadButton::DPAD_DOWN->value]->update($value > 0);
        } elseif (isset($this->abs_axes[$code])) {
            $axis = $this->abs_axes[$code];
            $this->axes[$axis->value] = $axis === GamepadAxis::LEFT_TRIGGER || $axis === GamepadAxis::RIGHT_TRIGGER
                ? self::trigger($this->ranges[$code], $value)
                : self::stick($this->ranges[$code], $value);
        }
    }

    /** −1…1 across the range; 0.0 within `flat` of centre. */
    private static function stick(AbsInfo $range, int $value): float
    {
        if (abs($value - ($range->minimum + $range->maximum) / 2) <= $range->flat) {
            return 0.0;
        }

        return max(-1.0, min(1.0, 2.0 * ($value - $range->minimum) / ($range->maximum - $range->minimum) - 1.0));
    }

    /** 0…1 across the range. */
    private static function trigger(AbsInfo $range, int $value): float
    {
        return max(0.0, min(1.0, ($value - $range->minimum) / ($range->maximum - $range->minimum)));
    }

    private function disconnect(): void
    {
        $this->connected = false;

        foreach ($this->buttons as $button) {
            $button->update(false);
        }

        foreach (array_keys($this->axes) as $axis) {
            $this->axes[$axis] = 0.0;
        }
    }
}
