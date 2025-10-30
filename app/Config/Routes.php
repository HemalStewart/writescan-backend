<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

$routes->group('api', static function ($routes) {
    $routes->get('auth/me', 'Api\Auth::me');
    $routes->post('auth/request-otp', 'Api\Auth::requestOtp');
    $routes->post('auth/verify-otp', 'Api\Auth::verifyOtp');
    $routes->post('auth/unregister', 'Api\Auth::unregister');
    $routes->match(['GET', 'POST'], 'auth/logout', 'Api\Auth::logout');

    $routes->get('config', 'Api\Config::show');

    $routes->get('documents', 'Api\Documents::index');
    $routes->get('documents/(:segment)', 'Api\Documents::show/$1');
    $routes->post('documents', 'Api\Documents::create');
    $routes->patch('documents/(:segment)', 'Api\Documents::update/$1');
    $routes->delete('documents/(:segment)', 'Api\Documents::delete/$1');

    $routes->get('folders', 'Api\Folders::index');
    $routes->post('folders', 'Api\Folders::create');
    $routes->patch('folders/(:segment)', 'Api\Folders::update/$1');
    $routes->delete('folders/(:segment)', 'Api\Folders::delete/$1');

    $routes->get('bots', 'Api\Bots::index');
    $routes->get('bots/(:segment)', 'Api\Bots::show/$1');
    $routes->post('bots', 'Api\Bots::create');
    $routes->delete('bots/(:segment)', 'Api\Bots::delete/$1');

    $routes->get('bots/(:segment)/messages', 'Api\BotMessages::index/$1');
    $routes->post('bots/(:segment)/messages', 'Api\BotMessages::create/$1');

    $routes->get('general-chat', 'Api\GeneralChat::index');
    $routes->post('general-chat', 'Api\GeneralChat::create');
});
