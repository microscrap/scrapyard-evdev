---
type: Concept
title: Gamepads
description: "How evdev nodes become Surface GameController circuits: BTN_SOUTH scan, positional button map, hat as d-pad, normalised sticks/triggers, the Z/RZ rule, flat zone, unplug via revents()."
resource: src/EvdevGamepad.php
tags: [gamepad, evdev, surface, human-input, axes, hot-unplug]
generated: { by: "claude-opus-5", at: "2026-09-17T00:00:00Z" }
status: draft
sources:
  - id: gamepad
    resource: src/EvdevGamepad.php
    title: button/axis mapping, poll(), disconnect
  - id: scanner
    resource: src/EvdevScanner.php
    title: gamepad detection, open()
  - id: contract
    resource: ../../venusian/surface/src/Surface/Contracts/HumanInput/Circuits/GameController.php
    title: circuit contract implemented here
---

# Scanning

`EvdevScanner::gamepads()`: entries of the directory (default `/dev/input`) starting `event`, natural-sorted (`event2` before `event10`). Node = gamepad when its EV_KEY bits include `BTN_SOUTH`. Bits read from `/sys/class/input/<node>/device/capabilities/key` (hex longs, most significant first; `hasBit()`) — no open, because an evdev close costs the kernel 15-45 ms (18 nodes ≈ 400 ms). Sysfs silent → open + `supports()` probe. Injected `$open` without `$capabilities` → always probe. `BTN_SOUTH` stands in for kernel `BTN_GAMEPAD` (same value; enum can't alias). Every probe closed. Missing/unreadable directory → `[]`; node that won't open → skipped.

`open($path)`: `null` when the node won't open. Pad `id` = `basename($path)` (`event3`). Device answering no probe (empty name, no mapped key, no axis) → `EvdevGamepadException` "'{id}' is not a readable evdev device.", device closed first.

# Buttons (positional, not labelled)

| evdev | GamepadButton |
|---|---|
| BTN_SOUTH | SOUTH |
| BTN_EAST | EAST |
| BTN_NORTH | NORTH |
| BTN_WEST | WEST |
| BTN_TL / BTN_TR | LEFT_SHOULDER / RIGHT_SHOULDER |
| BTN_SELECT | BACK |
| BTN_START | START |
| BTN_MODE | GUIDE |
| BTN_THUMBL / BTN_THUMBR | LEFT_STICK / RIGHT_STICK |
| BTN_DPAD_UP/DOWN/LEFT/RIGHT | DPAD_UP/DOWN/LEFT/RIGHT |

`supports()` = device reports the code, or button is `DPAD_*` and device has `ABS_HAT0X` or `ABS_HAT0Y`. Capabilities read once in constructor; `supports()` / `supportedAxes()` do no IO. `EV_KEY` value 0 = up, non-zero (incl. autorepeat 2) = down. Unmapped codes, non KEY/ABS types ignored.

Hat: `HAT0X` −1 → DPAD_LEFT, +1 → DPAD_RIGHT, 0 → both up. `HAT0Y` −1 → DPAD_UP, +1 → DPAD_DOWN, 0 → both up.

# Axes

Axis exists when `absInfo()` non-null and `maximum > minimum`. `supportedAxes()` in `GamepadAxis::cases()` order. Axes read `0.0` until first event (resting `AbsInfo::value` not applied).

| evdev | axis |
|---|---|
| ABS_X / ABS_Y | LEFT_X / LEFT_Y |
| ABS_RX / ABS_RY | RIGHT_X / RIGHT_Y |
| ABS_Z / ABS_RZ, pad has ABS_RX | LEFT_TRIGGER / RIGHT_TRIGGER |
| ABS_Z / ABS_RZ, no ABS_RX | RIGHT_X / RIGHT_Y |

- Stick: `2·(v − min)/(max − min) − 1`, clamped −1…1. `0.0` when `|v − centre| ≤ flat`, centre = `(min + max)/2`.
- Trigger: `(v − min)/(max − min)`, clamped 0…1. No flat zone.

**Z/RZ rule, why:** xpad-style drivers put the right stick on RX/RY and analog triggers on Z/RZ. Classic DirectInput / HID generic pads have no RX and put the right stick on Z/RZ. Presence of `ABS_RX` separates the two.

**Digital triggers:** `BTN_TL2` / `BTN_TR2` drive LEFT_TRIGGER / RIGHT_TRIGGER as `1.0` / `0.0` only when that side has no analog trigger axis. Such a pad still lists the trigger in `supportedAxes()`. With an analog trigger, TL2/TR2 key events ignored.

Surface's `ICInput` builds a GameController device only when `supportedAxes()` holds LEFT_X or RIGHT_X; hat-only / button-only pads become plain GamePads.

# poll() and unplug

Order: settle every `DigitalButton` → if disconnected, return → `revents()` has `POLLHUP` or `POLLERR` → disconnect, return → fold every event from `read()`. Never waits.

Unplug seen only through `EvdevDevice::revents()`: ext-posi `ppoll` returns a ready count, not a mask; `EvdevDevice` latches `POLLHUP` after a failed read. First poll after a failed read disconnects.

Disconnect: `connected()` false for good, every button `update(false)` (held buttons show `wasReleased` on that poll), axes → `0.0`, no further reads. `close()`: disconnects, closes the device once; idempotent.

# Draining and SYN_DROPPED

`poll()` calls `read(64)` until a batch comes back short (cap 64 batches). A DualSense queues thousands of `EV_ABS`/s; one read per tick falls behind and the kernel buffer overflows. On `SYN_DROPPED`: ignore events until the next `SYN_REPORT` (state kept across polls), then resync — every mapped key from `keyDown()`, every ranged axis from `absInfo()->value`, folded like fresh events (so a release lost in the drop still edges).

# Rescan is the consumer's job

Package holds no timer, no watcher, no connect/disconnect events. Consumer (GTK input engine, sketch) calls `gamepads()` on its own cadence, opens new nodes, drops disconnected pads, emits `GamepadConnected` / `GamepadDisconnected`. A disconnected pad never reconnects — reopen the node.
