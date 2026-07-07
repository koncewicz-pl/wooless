<?php

namespace Tests\Feature;

use App\Services\WooCommerce\WooCommerceClient;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CartControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->startSession();
    }

    /**
     * @return array<string, mixed>
     */
    protected function cartPayload(): array
    {
        return [
            'items' => [],
            'totals' => [
                'total_price' => '1000',
                'total_items' => '1000',
                'total_shipping' => '0',
                'currency_minor_unit' => 2,
                'currency_decimal_separator' => ',',
                'currency_thousand_separator' => ' ',
            ],
            'shipping_address' => [],
            'billing_address' => [],
            'items_count' => 3,
            'needs_payment' => true,
            'shipping_rates' => [],
            'extensions' => [],
        ];
    }

    protected function mockClient(): MockInterface
    {
        $mock = Mockery::mock(WooCommerceClient::class);
        $this->app->instance(WooCommerceClient::class, $mock);

        return $mock;
    }

    public function test_add_item_flashes_computed_cart_state_for_next_request(): void
    {
        $this->mockClient()
            ->shouldReceive('postAsync')
            ->once()
            ->with('wc/store/v1/cart/add-item', Mockery::any())
            ->andReturn(new FulfilledPromise(new Response(200, [], json_encode($this->cartPayload()))));

        $response = $this->post(route('cart.add-item'), [
            'id' => 7,
            'quantity' => 2,
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('cart.index'));
        $response->assertSessionHas('cart', function ($cart) {
            return $cart['items_count'] === 3
                && $cart['formatted_totals']['total_price'] === '10,00';
        });
    }

    public function test_remove_item_flashes_computed_cart_state_for_next_request(): void
    {
        $this->mockClient()
            ->shouldReceive('postAsync')
            ->once()
            ->with('wc/store/v1/cart/remove-item', Mockery::any())
            ->andReturn(new FulfilledPromise(new Response(200, [], json_encode($this->cartPayload()))));

        $response = $this->post(route('cart.remove-item'), [
            'key' => 'abc',
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('cart.index'));
        $response->assertSessionHas('cart', fn ($cart) => $cart['items_count'] === 3);
    }

    public function test_update_item_flashes_computed_cart_state_for_next_request(): void
    {
        $this->mockClient()
            ->shouldReceive('postAsync')
            ->once()
            ->with('wc/store/v1/cart/update-item', Mockery::any())
            ->andReturn(new FulfilledPromise(new Response(200, [], json_encode($this->cartPayload()))));

        $response = $this->post(route('cart.update-item'), [
            'key' => 'abc',
            'quantity' => 4,
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('cart.index'));
        $response->assertSessionHas('cart', fn ($cart) => $cart['items_count'] === 3);
    }

    public function test_index_uses_flashed_cart_without_fetching_from_woocommerce(): void
    {
        $this->mockClient()->shouldNotReceive('getAsync');

        $cart = ['items_count' => 5, 'items' => [], 'totals' => []];

        $response = $this->withSession(['cart' => $cart])
            ->get(route('cart.index'));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cart/Index', false)
            ->where('cart', $cart)
        );
    }

    public function test_index_fetches_cart_when_no_flashed_state_is_present(): void
    {
        $this->mockClient()
            ->shouldReceive('getAsync')
            ->once()
            ->with('wc/store/v1/cart')
            ->andReturn(new FulfilledPromise(new Response(200, [], json_encode($this->cartPayload()))));

        $response = $this->get(route('cart.index'));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cart/Index', false)
            ->where('cart.items_count', 3)
        );
    }

    public function test_failed_add_item_does_not_flash_cart_state(): void
    {
        $this->mockClient()
            ->shouldReceive('postAsync')
            ->once()
            ->with('wc/store/v1/cart/add-item', Mockery::any())
            ->andReturn(new RejectedPromise(new \RuntimeException('boom')));

        $response = $this->from(route('cart.index'))
            ->post(route('cart.add-item'), [
                'id' => 7,
                'quantity' => 2,
                '_token' => csrf_token(),
            ]);

        $response->assertSessionHasErrors('error');
        $response->assertSessionMissing('cart');
    }
}
