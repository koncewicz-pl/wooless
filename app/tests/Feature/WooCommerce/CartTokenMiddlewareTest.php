<?php

namespace Tests\Feature\WooCommerce;

use App\Services\FrontCart;
use App\Services\WooCommerce\CartTokenMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CartTokenMiddlewareTest extends TestCase
{
    protected MockHandler $mockHandler;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockHandler = new MockHandler;

        $stack = HandlerStack::create($this->mockHandler);
        $stack->push(new CartTokenMiddleware($this->app->make(FrontCart::class)));

        $this->client = new Client(['handler' => $stack]);
    }

    protected function jwt(string $userId, int $iat = 1000): string
    {
        $header = rtrim(strtr(base64_encode('{"alg":"HS256","typ":"JWT"}'), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['user_id' => $userId, 'iat' => $iat])), '+/', '-_'), '=');

        return "{$header}.{$payload}.signature";
    }

    public function test_attaches_cart_token_header_when_session_has_token(): void
    {
        $token = $this->jwt('t_abc');
        session(['cart_token' => $token]);
        $this->mockHandler->append(new Response(200, ['Cart-Token' => $token], '{}'));

        $this->client->get('wc/store/v1/cart')->getBody()->getContents();

        $sentRequest = $this->mockHandler->getLastRequest();
        $this->assertSame($token, $sentRequest->getHeaderLine('Cart-Token'));
    }

    public function test_does_not_attach_cart_token_header_when_session_is_empty(): void
    {
        $this->mockHandler->append(new Response(200, ['Cart-Token' => $this->jwt('t_fresh')], '{}'));

        $this->client->get('wc/store/v1/cart');

        $sentRequest = $this->mockHandler->getLastRequest();
        $this->assertFalse($sentRequest->hasHeader('Cart-Token'));
    }

    public function test_saves_token_from_response_to_session(): void
    {
        $token = $this->jwt('t_fresh');
        $this->mockHandler->append(new Response(200, ['Cart-Token' => $token], '{}'));

        $this->client->get('wc/store/v1/cart');

        $this->assertSame($token, session('cart_token'));
    }

    public function test_logs_info_when_token_is_generated_first_time(): void
    {
        Log::spy();
        $this->mockHandler->append(new Response(200, ['Cart-Token' => $this->jwt('t_fresh')], '{}'));

        $this->client->get('wc/store/v1/cart');

        Log::shouldHaveReceived('info')
            ->once()
            ->with('WC cart session generated: t_fresh');
    }

    public function test_logs_warning_when_user_id_in_token_changes(): void
    {
        $old = $this->jwt('t_old');
        $new = $this->jwt('t_new');
        session(['cart_token' => $old]);
        Log::spy();
        $this->mockHandler->append(new Response(200, ['Cart-Token' => $new], '{}'));

        $this->client->get('wc/store/v1/cart');

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('WC cart session changed: t_old -> t_new');
        $this->assertSame($new, session('cart_token'));
    }

    public function test_does_not_log_when_user_id_is_unchanged_but_iat_differs(): void
    {
        $old = $this->jwt('t_same', iat: 1000);
        $new = $this->jwt('t_same', iat: 2000);
        session(['cart_token' => $old]);
        Log::spy();
        $this->mockHandler->append(new Response(200, ['Cart-Token' => $new], '{}'));

        $this->client->get('wc/store/v1/cart');

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        $this->assertSame($new, session('cart_token'));
    }

    public function test_does_nothing_when_response_has_no_cart_token_header(): void
    {
        $token = $this->jwt('t_keep');
        session(['cart_token' => $token]);
        Log::spy();
        $this->mockHandler->append(new Response(200, [], '{}'));

        $this->client->post('jwt-auth/v1/token/validate');

        $this->assertSame($token, session('cart_token'));
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_overwrites_incoming_cart_token_header_with_session_value(): void
    {
        $token = $this->jwt('t_from_session');
        session(['cart_token' => $token]);
        $this->mockHandler->append(new Response(200, ['Cart-Token' => $token], '{}'));

        $this->client->get('wc/store/v1/cart', [
            'headers' => ['Cart-Token' => 'passed-in-manually'],
        ]);

        $sentRequest = $this->mockHandler->getLastRequest();
        $this->assertSame($token, $sentRequest->getHeaderLine('Cart-Token'));
    }
}
