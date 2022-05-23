<?php

namespace Nevs;

class Response
{
    public function __construct(public string $content, public array $headers = [])
    {
    }
}

//TODO make JSONResponse