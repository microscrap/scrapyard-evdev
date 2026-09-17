---
okf_version: "0.2"
---

# microscrap/scrapyard-evdev Knowledge Bundle

Package knowledge for `microscrap/scrapyard-evdev` (Linux evdev gamepads as Surface input circuits, v0.8.0).
Read this index first; open only the concepts needed for the task.

**Trust rule:** Prefer `status: stable`. Treat `deprecated` as historical only. New agent-written concepts stay `status: draft` until a human verifies them.
**Placement:** Bundle lives at the package root only — never under `src/`.
**Scope:** Gamepad policy over `microscrap/evdev`. Struct layouts, ioctls, permissions live in that package's bundle.
**Dist note:** `.okf/`, `tests/`, root `AGENTS.md` are `export-ignore`.

# Concepts

* [Gamepads](gamepads.md) - scanning rule, positional button map, axis normalisation, Z/RZ rule, flat zone, unplug detection, rescan ownership.

# Log

* [Directory update log](log.md)
