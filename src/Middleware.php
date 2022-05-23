<?php

namespace Nevs;

class Middleware
{
    public function Before(Request &$request): null|Response {
        return null;
    }

    public function After(Request &$request, Response &$response): void {
    }
}