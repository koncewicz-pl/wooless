<?php

namespace App\Services\WooCommerce;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;

class GuzzleWooCommerceClient implements WooCommerceClient
{
    public function __construct(protected Client $client) {}

    public function getAsync(string $uri, array $options = []): PromiseInterface
    {
        return $this->client->getAsync($uri, $options);
    }

    public function postAsync(string $uri, array $options = []): PromiseInterface
    {
        return $this->client->postAsync($uri, $options);
    }
}
