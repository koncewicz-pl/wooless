<?php

namespace App\Services\WooCommerce;

use App\Services\FrontCart;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class CartTokenMiddleware
{
    public function __construct(protected FrontCart $frontCart) {}

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            $sentToken = $this->frontCart->cartToken();

            if ($sentToken !== null) {
                $request = $request->withHeader('Cart-Token', $sentToken);
            }

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($sentToken) {
                    $receivedTokens = $response->getHeader('Cart-Token');

                    if (empty($receivedTokens)) {
                        return $response;
                    }

                    $receivedToken = $receivedTokens[0];

                    $this->logTokenChange($sentToken, $receivedToken);
                    $this->frontCart->saveCartToken($receivedToken);

                    return $response;
                }
            );
        };
    }

    protected function logTokenChange(?string $sentToken, string $receivedToken): void
    {
        $receivedUserId = $this->extractUserId($receivedToken);

        if ($sentToken === null) {
            Log::info("WC cart session generated: {$receivedUserId}");
            return;
        }

        $sentUserId = $this->extractUserId($sentToken);

        if ($sentUserId === $receivedUserId) {
            return;
        }

        Log::warning("WC cart session changed: {$sentUserId} -> {$receivedUserId}");
    }

    protected function extractUserId(string $jwt): ?string
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return $payload['user_id'] ?? null;
    }
}
