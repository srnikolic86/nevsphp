<?php

namespace Nevs;

class Log
{
    static function Write(string $category, string $message): void {
        if (in_array($category, Config::Get('enabled_logs'))) {
            error_log($category . ": " . $message);
        }
    }
}