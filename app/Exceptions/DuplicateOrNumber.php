<?php

namespace App\Exceptions;

class DuplicateOrNumber extends \RuntimeException
{
    public static function forOrNumber(string $orNumber): self
    {
        return new self("OR number {$orNumber} is already used this school year.");
    }
}
