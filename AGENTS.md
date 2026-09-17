# AGENTS.md — microscrap/scrapyard-evdev

**Always read `.okf/index.md` first** before changing this package. Open only the concepts needed for the task; prefer `status: stable` when present. When you learn a durable package fact, update `.okf/` and append `.okf/log.md`.

## Role

Opinions over `microscrap/evdev` (bindings only). This package decides: which `/dev/input/event*` nodes are gamepads (`EvdevScanner`), the positional button map, axis normalisation, the Z/RZ rule, hot-unplug (`EvdevGamepad`). Output = Surface input circuits; the GTK input engine and `HumanInputManager::attach()` consume them.

## Rules

* Implements `Surface\Contracts\HumanInput\Circuits\GameController`. Names and types from that contract are law.
* Imports nothing from `GeneralPurposeIO\*`. Kernel access only through `microscrap/evdev`.
* Never blocks: `poll()` settles, checks `revents()`, drains `read()`. No `usleep`, no blocking read.
* Unplug detected only via `EvdevDevice::revents()` (`POLLHUP` / `POLLERR`), never `posix_ppoll` directly — ppoll returns a count, not a mask.
* Unmapped codes ignored, never thrown. Only throw `EvdevGamepadException` (a `HumanInputException`).
* No ServiceProvider — consumers construct `EvdevScanner` / `EvdevGamepad`.
* No class constants. Prefer `is_null($x)`. Strongly typed. Tests Pest v4, kernel-free via `tests/Support/ScriptedEvdevDevice.php`.

## Quick OKF map

| Need | Concept |
|------|---------|
| Button map, axis rules, Z/RZ, flat zone, unplug, rescan | `.okf/gamepads.md` |
