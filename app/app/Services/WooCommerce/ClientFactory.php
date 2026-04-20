<?php

namespace App\Services\WooCommerce;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

class ClientFactory
{
    public function __construct(protected CartTokenMiddleware $cartTokenMiddleware) {}

    public function make(): WooCommerceClient
    {
        $stack = HandlerStack::create();
        $stack->push($this->cartTokenMiddleware, 'cart_token');

        $client = new Client([
            'base_uri' => config('services.wordpress.container_url').'/wordpress/wp-json/',
            'verify' => false,
            'handler' => $stack,
        ]);

        return new GuzzleWooCommerceClient($client);
    }
}
