<?php

namespace App\Services\WooCommerce;

use GuzzleHttp\Promise\PromiseInterface;

interface WooCommerceClient
{
    public function getAsync(string $uri, array $options = []): PromiseInterface;

    public function postAsync(string $uri, array $options = []): PromiseInterface;
}
