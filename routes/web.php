<?php

use Illuminate\Support\Facades\Route;

Route::get('/debug', function () {
    $routes = collect(Route::getRoutes())->map(function ($route) {
        return [
            'method' => implode('|', $route->methods()),
            'uri' => $route->uri(),
            'name' => $route->getName(),
            'action' => $route->getActionName(),
        ];
    });

    return response()->json([
        'total_routes' => $routes->count(),
        'routes' => $routes
    ]);
});

Route::get('/', function () {
    return view('welcome');
});
