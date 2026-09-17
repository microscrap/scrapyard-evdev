<?php

/*
| scrapyard-evdev is the gamepad policy over microscrap/evdev. Nothing here
| touches a kernel: devices are ScriptedEvdevDevice subclasses, so the
| button map, axis normalisation, the Z/RZ rule and hot-unplug are proven
| in pure PHP. Real nodes are proven on the Pi with a USB pad.
*/
