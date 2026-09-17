<?php

namespace Microscrap\ScrapyardEvdev;

use Surface\Contracts\HumanInput\HumanInputException;

class EvdevGamepadException extends HumanInputException
{
    public static function unreadable(string $id): static
    {
        return new static("'{$id}' is not a readable evdev device.");
    }
}
