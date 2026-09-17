<?php

use Microscrap\Bindings\Evdev\DataObjects\AbsInfo;
use Microscrap\Bindings\Evdev\DataObjects\InputEvent;
use Microscrap\Bindings\Evdev\Enums\AbsCode;
use Microscrap\Bindings\Evdev\Enums\EventType;
use Microscrap\Bindings\Evdev\Enums\KeyCode;
use Microscrap\Bindings\Evdev\Enums\PollFlag;
use Microscrap\Bindings\Evdev\Enums\SynCode;
use Microscrap\ScrapyardEvdev\EvdevGamepad;
use Microscrap\ScrapyardEvdev\EvdevGamepadException;
use Microscrap\ScrapyardEvdev\Tests\Support\ScriptedEvdevDevice as Dev;
use Surface\Contracts\HumanInput\Devices\GameController as ControllerDevice;
use Surface\Contracts\HumanInput\GamepadAxis;
use Surface\Contracts\HumanInput\GamepadButton;
use Surface\HumanInput\ICInput;

function stick(int $min = -32768, int $max = 32767, int $flat = 0): AbsInfo
{
    return new AbsInfo(0, $min, $max, 0, $flat, 0);
}

/** An Xbox-shaped pad: two sticks, two analog triggers, a hat. */
function xpad(): Dev
{
    return new Dev(
        keys: [KeyCode::BTN_SOUTH, KeyCode::BTN_EAST, KeyCode::BTN_NORTH, KeyCode::BTN_WEST, KeyCode::BTN_TL, KeyCode::BTN_TR, KeyCode::BTN_SELECT, KeyCode::BTN_START, KeyCode::BTN_MODE, KeyCode::BTN_THUMBL, KeyCode::BTN_THUMBR],
        abs: [0 => stick(), 1 => stick(), 3 => stick(), 4 => stick(), 2 => new AbsInfo(0, 0, 255, 0, 0, 0), 5 => new AbsInfo(0, 0, 255, 0, 0, 0), 0x10 => new AbsInfo(0, -1, 1, 0, 0, 0), 0x11 => new AbsInfo(0, -1, 1, 0, 0, 0)],
        name: 'Xbox Wireless Controller',
    );
}

it('reports what the device has: buttons, the hat as a d-pad, six axes', function (): void {
    $pad = new EvdevGamepad(xpad(), 'event3');

    expect($pad->name())->toBe('Xbox Wireless Controller')
        ->and($pad->supports(GamepadButton::SOUTH))->toBeTrue()
        ->and($pad->supports(GamepadButton::DPAD_LEFT))->toBeTrue()
        ->and($pad->supportedAxes())->toBe(GamepadAxis::cases());
});

it('folds key events into edges and keeps a sub-poll tap', function (): void {
    $dev = xpad();
    $pad = new EvdevGamepad($dev, 'event3');
    $dev->queue = [[Dev::key(KeyCode::BTN_SOUTH, true), Dev::key(KeyCode::BTN_EAST, true), Dev::key(KeyCode::BTN_EAST, false)], []];

    $pad->poll();
    expect($pad->isPressed(GamepadButton::SOUTH))->toBeTrue()
        ->and($pad->isDown(GamepadButton::EAST))->toBeFalse()
        ->and($pad->isPressed(GamepadButton::EAST))->toBeTrue()
        ->and($pad->wasReleased(GamepadButton::EAST))->toBeTrue();

    $pad->poll();
    expect($pad->isDown(GamepadButton::SOUTH))->toBeTrue()->and($pad->isPressed(GamepadButton::SOUTH))->toBeFalse();
});

it('normalises sticks and triggers from the device ranges', function (): void {
    $dev = xpad();
    $pad = new EvdevGamepad($dev, 'event3');
    $dev->queue = [[Dev::abs(AbsCode::X, 32767), Dev::abs(AbsCode::Y, -32768), Dev::abs(AbsCode::RZ, 255), Dev::abs(AbsCode::Z, 0)]];

    $pad->poll();

    expect($pad->axis(GamepadAxis::LEFT_X))->toBe(1.0)
        ->and($pad->axis(GamepadAxis::LEFT_Y))->toBe(-1.0)
        ->and($pad->axis(GamepadAxis::RIGHT_TRIGGER))->toBe(1.0)
        ->and($pad->axis(GamepadAxis::LEFT_TRIGGER))->toBe(0.0);
});

it('reads zero inside the flat zone', function (): void {
    $dev = new Dev(keys: [KeyCode::BTN_SOUTH], abs: [0 => stick(0, 255, 15), 1 => stick(0, 255, 15)], name: 'Flat');
    $pad = new EvdevGamepad($dev, 'event4');
    $dev->queue = [[Dev::abs(AbsCode::X, 135)]];

    $pad->poll();

    expect($pad->axis(GamepadAxis::LEFT_X))->toBe(0.0);
});

it('turns the hat into d-pad buttons', function (): void {
    $dev = xpad();
    $pad = new EvdevGamepad($dev, 'event3');
    $dev->queue = [[Dev::abs(AbsCode::HAT0X, -1), Dev::abs(AbsCode::HAT0Y, 1)], [Dev::abs(AbsCode::HAT0X, 0)]];

    $pad->poll();
    expect($pad->isDown(GamepadButton::DPAD_LEFT))->toBeTrue()->and($pad->isDown(GamepadButton::DPAD_DOWN))->toBeTrue();

    $pad->poll();
    expect($pad->wasReleased(GamepadButton::DPAD_LEFT))->toBeTrue()->and($pad->isDown(GamepadButton::DPAD_DOWN))->toBeTrue();
});

it('treats Z / RZ as the right stick on a pad with no RX, and TL2 / TR2 as full triggers', function (): void {
    $dev = new Dev(keys: [KeyCode::BTN_SOUTH, KeyCode::BTN_TL2, KeyCode::BTN_TR2], abs: [0 => stick(0, 255), 1 => stick(0, 255), 2 => stick(0, 255), 5 => stick(0, 255)], name: 'DirectInput');
    $pad = new EvdevGamepad($dev, 'event5');
    $dev->queue = [[Dev::abs(AbsCode::Z, 255), Dev::abs(AbsCode::RZ, 0), Dev::key(KeyCode::BTN_TR2, true)]];

    $pad->poll();

    expect($pad->axis(GamepadAxis::RIGHT_X))->toBe(1.0)
        ->and($pad->axis(GamepadAxis::RIGHT_Y))->toBe(-1.0)
        ->and($pad->axis(GamepadAxis::RIGHT_TRIGGER))->toBe(1.0)
        ->and($pad->axis(GamepadAxis::LEFT_TRIGGER))->toBe(0.0);
});

it('is a plain button pad to Surface when it has no stick', function (): void {
    $dev = new Dev(keys: [KeyCode::BTN_SOUTH, KeyCode::BTN_EAST, KeyCode::BTN_START], abs: [0x10 => new AbsInfo(0, -1, 1, 0, 0, 0), 0x11 => new AbsInfo(0, -1, 1, 0, 0, 0)], name: 'Retro');
    $input = new ICInput('retro', new EvdevGamepad($dev, 'event6'));

    expect($input->device())->not->toBeInstanceOf(ControllerDevice::class)
        ->and($input->device()->supports(GamepadButton::DPAD_UP))->toBeTrue();
});

it('goes disconnected on a hang-up, releases what it held, and stops reading', function (): void {
    $dev = xpad();
    $pad = new EvdevGamepad($dev, 'event3');
    $dev->queue = [[Dev::key(KeyCode::BTN_SOUTH, true)], [Dev::key(KeyCode::BTN_EAST, true)]];
    $pad->poll();

    $dev->revents = PollFlag::POLLHUP->value;
    $pad->poll();

    expect($pad->connected())->toBeFalse()
        ->and($pad->wasReleased(GamepadButton::SOUTH))->toBeTrue()
        ->and($pad->isDown(GamepadButton::EAST))->toBeFalse()
        ->and($dev->queue)->toHaveCount(1);

    $pad->close();
    $pad->close();
    expect($dev->closed)->toBeTrue();
});

it('rejects a device that answers nothing', function (): void {
    new EvdevGamepad(new Dev(), 'event7');
})->throws(EvdevGamepadException::class, "'event7' is not a readable evdev device.");

it('goes disconnected on POLLERR too', function (): void {
    $dev = xpad();
    $pad = new EvdevGamepad($dev, 'event3');
    $dev->revents = PollFlag::POLLERR->value;

    expect($pad->poll()->connected())->toBeFalse();
});

it('stops reading once closed and reads everything released', function (): void {
    $dev = xpad();
    $pad = new EvdevGamepad($dev, 'event3');
    $dev->queue = [[Dev::key(KeyCode::BTN_SOUTH, true), Dev::abs(AbsCode::X, 32767)], [Dev::key(KeyCode::BTN_EAST, true)]];
    $pad->poll();

    $pad->close();
    $pad->poll();

    expect($pad->connected())->toBeFalse()
        ->and($pad->isDown(GamepadButton::SOUTH))->toBeFalse()
        ->and($pad->axis(GamepadAxis::LEFT_X))->toBe(0.0)
        ->and($dev->queue)->toHaveCount(1)
        ->and($pad->path())->toBe('/dev/input/event3');
});

it('ignores unmapped codes, other event types, and unsupported buttons and axes', function (): void {
    $dev = new Dev(keys: [KeyCode::BTN_SOUTH], name: 'Bare');
    $pad = new EvdevGamepad($dev, 'event8');
    $dev->queue = [[Dev::key(KeyCode::BTN_C, true), new InputEvent(0, 0, EventType::REL->value, 0, 5), Dev::abs(AbsCode::X, 100)]];

    $pad->poll();

    expect($pad->supports(GamepadButton::DPAD_UP))->toBeFalse()
        ->and($pad->supportedAxes())->toBe([])
        ->and($pad->isDown(GamepadButton::EAST))->toBeFalse()
        ->and($pad->axis(GamepadAxis::LEFT_X))->toBe(0.0);
});

it('lists trigger axes for digital-only triggers', function (): void {
    $pad = new EvdevGamepad(new Dev(keys: [KeyCode::BTN_SOUTH, KeyCode::BTN_TL2, KeyCode::BTN_TR2], name: 'SNES-ish'), 'event9');

    expect($pad->supportedAxes())->toBe([GamepadAxis::LEFT_TRIGGER, GamepadAxis::RIGHT_TRIGGER]);
});

it('drains a burst longer than one read in a single poll', function (): void {
    $dev = xpad();
    $pad = new EvdevGamepad($dev, 'event3');
    $dev->queue = [[...array_fill(0, 200, Dev::abs(AbsCode::X, 0)), Dev::key(KeyCode::BTN_SOUTH, true)]];

    $pad->poll();

    expect($pad->isDown(GamepadButton::SOUTH))->toBeTrue()
        ->and($dev->reads)->toBe(4);
});

it('skips to the next report after SYN_DROPPED and resyncs from the kernel', function (): void {
    $dev = xpad();
    $pad = new EvdevGamepad($dev, 'event3');
    $dev->queue = [[Dev::key(KeyCode::BTN_SOUTH, true), Dev::syn(SynCode::REPORT)]];
    $pad->poll();

    $dev->held = [KeyCode::BTN_EAST];
    $dev->values = [AbsCode::X->value => 32767];
    $dev->queue = [[
        Dev::syn(SynCode::DROPPED),
        Dev::key(KeyCode::BTN_NORTH, true),
        Dev::syn(SynCode::REPORT),
        Dev::key(KeyCode::BTN_WEST, true),
        Dev::syn(SynCode::REPORT),
    ]];
    $pad->poll();

    expect($pad->isDown(GamepadButton::SOUTH))->toBeFalse()
        ->and($pad->wasReleased(GamepadButton::SOUTH))->toBeTrue()
        ->and($pad->isPressed(GamepadButton::EAST))->toBeTrue()
        ->and($pad->isDown(GamepadButton::NORTH))->toBeFalse()
        ->and($pad->isDown(GamepadButton::WEST))->toBeTrue()
        ->and($pad->axis(GamepadAxis::LEFT_X))->toBe(1.0)
        ->and($pad->axis(GamepadAxis::LEFT_Y))->toEqualWithDelta(0.0, 0.001);
});

it('keeps dropping across polls until the report arrives', function (): void {
    $dev = xpad();
    $pad = new EvdevGamepad($dev, 'event3');
    $dev->held = [KeyCode::BTN_SOUTH];
    $dev->queue = [[Dev::syn(SynCode::DROPPED), Dev::key(KeyCode::BTN_EAST, true)], [Dev::key(KeyCode::BTN_EAST, true), Dev::syn(SynCode::REPORT)]];

    $pad->poll();
    expect($pad->isDown(GamepadButton::EAST))->toBeFalse()->and($pad->isDown(GamepadButton::SOUTH))->toBeFalse();

    $pad->poll();
    expect($pad->isDown(GamepadButton::EAST))->toBeFalse()->and($pad->isDown(GamepadButton::SOUTH))->toBeTrue();
});
