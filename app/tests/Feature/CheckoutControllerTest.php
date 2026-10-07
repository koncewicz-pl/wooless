<?php

namespace Tests\Feature;

use App\Services\WooCommerce\WooCommerceClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CheckoutControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->startSession();
    }

    /**
     * @return array<string, mixed>
     */
    protected function moneyPayload(string $total = '2500'): array
    {
        return [
            'total_price' => $total,
            'total_items' => $total,
            'total_shipping' => '0',
            'currency_minor_unit' => 2,
            'currency_decimal_separator' => ',',
            'currency_thousand_separator' => ' ',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function cartPayload(): array
    {
        return [
            'items' => [],
            'totals' => $this->moneyPayload('0'),
            'shipping_address' => [],
            'billing_address' => [],
            'items_count' => 0,
            'needs_payment' => false,
            'shipping_rates' => [],
            'extensions' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function orderPayload(int $id): array
    {
        return [
            'id' => $id,
            'status' => 'processing',
            'items' => [],
            'totals' => $this->moneyPayload(),
            'shipping_address' => ['first_name' => 'Jan', 'last_name' => 'Kowalski', 'country' => 'PL', 'postcode' => '03-808', 'city' => 'Warszawa', 'address_1' => 'Mińska 25B'],
            'billing_address' => ['first_name' => 'Jan', 'last_name' => 'Kowalski', 'country' => 'PL', 'postcode' => '03-808', 'city' => 'Warszawa', 'address_1' => 'Mińska 25B', 'email' => 'jan@example.com'],
            'needs_payment' => false,
            'needs_shipping' => true,
        ];
    }

    /**
     * Mocks the three requests the order page issues; the order request resolves with the given promise.
     */
    protected function mockOrderPage(int $orderId, string $key, FulfilledPromise|RejectedPromise $orderPromise): MockInterface
    {
        $mock = Mockery::mock(WooCommerceClient::class);
        $this->app->instance(WooCommerceClient::class, $mock);

        $mock->shouldReceive('getAsync')
            ->once()
            ->with('wc/store/v1/order/'.$orderId.'?key='.$key.'&billing_email=', Mockery::any())
            ->andReturn($orderPromise);

        $mock->shouldReceive('getAsync')
            ->once()
            ->with('wc/store/v1/cart')
            ->andReturn(new FulfilledPromise(new Response(200, [], json_encode($this->cartPayload()))));

        $mock->shouldReceive('postAsync')
            ->once()
            ->with('jwt-auth/v1/token/validate', Mockery::any())
            ->andReturn(new RejectedPromise(new \RuntimeException('no token')));

        return $mock;
    }

    protected function clientException(int $status, string $body): ClientException
    {
        return new ClientException(
            'Client error',
            new Request('GET', 'wc/store/v1/order/33'),
            new Response($status, [], $body)
        );
    }

    public function test_order_page_renders_order_when_woocommerce_returns_it(): void
    {
        $this->mockOrderPage(33, 'wc_order_abc', new FulfilledPromise(
            new Response(200, [], json_encode($this->orderPayload(33)))
        ));

        $response = $this->get(route('checkout.order', ['order' => 33, 'key' => 'wc_order_abc']));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Checkout/Order', false)
            ->where('orderId', 33)
            ->where('orderRejected', false)
            ->where('orderRejectedCode', null)
            ->where('order.id', 33)
            ->where('order.formatted_totals.total_price', '25,00')
            ->where('logged', false)
        );
    }

    public function test_order_page_exposes_woocommerce_error_code_when_order_request_is_rejected(): void
    {
        $this->mockOrderPage(33, 'wc_order_abc', new RejectedPromise($this->clientException(401, json_encode([
            'code' => 'woocommerce_rest_invalid_billing_email',
            'message' => 'No billing email provided.',
            'data' => ['status' => 401],
        ]))));

        $response = $this->get(route('checkout.order', ['order' => 33, 'key' => 'wc_order_abc']));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Checkout/Order', false)
            ->where('orderId', 33)
            ->where('orderRejected', true)
            ->where('orderRejectedCode', 'woocommerce_rest_invalid_billing_email')
            ->where('order', [])
        );
    }

    public function test_order_page_handles_rejection_with_non_json_body(): void
    {
        $this->mockOrderPage(32, 'wc_order_xyz', new RejectedPromise($this->clientException(404, 'Not Found')));

        $response = $this->get(route('checkout.order', ['order' => 32, 'key' => 'wc_order_xyz']));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Checkout/Order', false)
            ->where('orderRejected', true)
            ->where('orderRejectedCode', null)
        );
    }

    public function test_order_page_handles_rejection_without_http_response(): void
    {
        $this->mockOrderPage(33, 'wc_order_abc', new RejectedPromise(new \RuntimeException('connection refused')));

        $response = $this->get(route('checkout.order', ['order' => 33, 'key' => 'wc_order_abc']));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Checkout/Order', false)
            ->where('orderRejected', true)
            ->where('orderRejectedCode', null)
        );
    }
}
