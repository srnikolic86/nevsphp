<?php

namespace Nevs;

class Config
{
    static function Get(string $key): bool|int|string|array|float|null
    {
        $key_array = explode('.', $key);
        $iterator = \NEVS_CONFIG;
        foreach ($key_array as $key) {
            if (isset($iterator[$key])) {
                $iterator = $iterator[$key];
            } else {
                return null;
            }
        }
        return $iterator;
    }
}