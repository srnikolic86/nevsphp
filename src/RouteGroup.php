<?php

namespace Nevs;

class RouteGroup extends RouterMember
{
    public function __construct(string $path, public array $members, array $middlewares = [])
    {
        $this->middlewares = $middlewares;
        $this->path = $path;
        foreach ($this->members as $member) {
            $member->group = $this;
        }
    }

    public function CheckForRequest(Request &$request): null|Route
    {
        Log::Write('Routing', 'checking group: ' . $this->GetFullPath());
        if (str_starts_with($request->url, $this->GetFullPath())) {
            foreach ($this->members as $member) {
                if ($member instanceof RouteGroup) {
                    $route = $member->CheckForRequest($request);
                    if ($route != null) {
                        return $route;
                    }
                }
                if ($member instanceof Route) {
                    if ($member->CheckForRequest($request)) {
                        return $member;
                    }
                }
            }
        }
        return null;
    }
}