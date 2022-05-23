<?php

namespace Nevs;

class RouterMember
{
    public RouteGroup $group;
    public string $path;
    public array $middlewares;

    public function CheckForRequest(Request &$request): null|RouterMember
    {
        return null;
    }

    public function GetFullPath(): string
    {
        if (isset($this->group)) return $this->group->GetFullPath() . $this->path;
        return $this->path;
    }

    public function GetAllMiddlewares(): array
    {
        if (isset($this->group)) return array_merge($this->group->GetAllMiddlewares(), $this->middlewares);
        return $this->middlewares;
    }
}