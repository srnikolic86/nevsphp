<?php

namespace Nevs;

class Route extends RouterMember
{
    public function __construct(public string $method, string $path, public string $class, public string $function, public array $parameters = [], array $middlewares = [])
    {
        $this->path = $path;
        $this->middlewares = $middlewares;
    }

    public function CheckForRequest(Request &$request): null|Route
    {
        Log::Write('Routing', 'checking route: ' . $this->Serialize());
        if ($request->method != $this->method) return null;
        if (count($this->parameters) == 0) {
            return ($request->url === $this->GetFullPath()) ? $this : null;
        } else {
            if (!str_starts_with($request->url, $this->GetFullPath())) return null;
            $request_array = explode('/', $request->url);
            Log::Write('Routing', 'request elements: ' . count($request_array));
            $route_array = explode('/', $this->GetFullPath());
            Log::Write('Routing', 'route elements: ' . count($route_array));
            if (count($this->parameters) != count($request_array) - count($route_array)) return null;
            for ($i = count($route_array); $i < count($request_array); $i++) {
                Log::Write('Routing', 'checking element: ' . $i);
                $request->parameters[$this->parameters[$i - count($route_array)]] = urldecode($request_array[$i]);
            }
            return $this;
        }
    }

    public function Serialize(): string
    {
        return json_encode([
            'method' => $this->method,
            'path' => $this->path,
            'full_path' => $this->GetFullPath(),
            'class' => $this->class,
            'function' => $this->function,
            'parameters' => $this->parameters
        ]);
    }

}