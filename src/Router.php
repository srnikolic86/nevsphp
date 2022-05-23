<?php

namespace Nevs;

class Router
{
    public function __construct(public array $members)
    {
    }

    public function GetRoute(Request &$request)
    {
        foreach ($this->members as $member) {
            $route = $member->CheckForRequest($request);
            if ($route !== null) {
                return $route;
            }
        }
        return null;
    }

    public static function Get(): Router
    {
        return \NEVS_ROUTER;
    }
}