<?php

namespace Nevs;

class Main
{
    static function Run(): void
    {
        global $DB;
        $DB = new Database();
        date_default_timezone_set(Config::Get('timezone'));

        $global_middlewares = ['ExampleMiddleware'];

        $uri = $_SERVER['REQUEST_URI'];
        if (Config::Get('router_base') != '') {
            $uri = implode('', explode(Config::Get('router_base'), $uri, 2));
        }
        $request = new Request($uri, $_SERVER['REQUEST_METHOD']);

        Log::Write('Routing', 'started: ' . $request->Serialize());
        $route = Router::Get()->GetRoute($request);
        if ($route !== null) {
            if ($route instanceof Route) {
                Log::Write('Routing', 'route match');
                $class = "App\\Controllers\\" . $route->class;
                $function = $route->function;
                $controller = new $class($request);
                $middlewares = $route->GetAllMiddlewares();

                $response = null;

                foreach (array_merge($global_middlewares, $middlewares) as $middleware_name) {
                    $middleware_class = "App\\Middleware\\" . $middleware_name;
                    $middleware = new $middleware_class();
                    if ($middleware instanceof Middleware) {
                        $result = $middleware->Before($request);
                        if ($result !== null) {
                            $response = $result;
                            break;
                        }
                    }
                }
                if ($response === null) {
                    $response = $controller->$function();
                    if ($response instanceof Response) {
                        foreach (array_reverse(array_merge($global_middlewares, $middlewares)) as $middleware_name) {
                            $middleware_class = "App\\Middleware\\" . $middleware_name;
                            $middleware = new $middleware_class();
                            if ($middleware instanceof Middleware) {
                                $middleware->After($request, $response);
                            }
                        }
                    }
                }

                if ($response instanceof Response) {
                    foreach ($response->headers as $header) {
                        header($header);
                    }
                    echo($response->content);
                }
            }
        } else {
            Log::Write('Routing', 'route not found');
            header('HTTP/1.1 404 Not Found');
        }
    }
}