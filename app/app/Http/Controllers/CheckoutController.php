<?php

namespace App\Http\Controllers;

use App\Services\Auth;
use App\Services\FrontCart;
use App\Services\FrontOrder;
use App\Services\Order;
use App\Services\WooCommerce\WooCommerceClient;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Promise;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    protected const array paymentMethods = [
        'blik' => '154',
        'ing' => '112',
        'pko' => '31',
        'mbank' => '270',
        'santander' => '20',
    ];

    public function __construct(
        protected FrontCart $frontCart,
        protected FrontOrder $frontOrder,
        protected Auth $auth,
        protected Order $order,
        protected WooCommerceClient $client,
    ) {}

    /**
     * @throws ValidationException
     */
    public function process(Request $request)
    {
        $request->validate([
            'payment_method' => 'required|in:blik,ing,mbank,pko,santander',
        ]);

        $promises = [
            'auth' => $this->client->postAsync(
                uri: 'jwt-auth/v1/token/validate',
                options: ['headers' => ['Authorization' => 'Bearer '.$this->auth->token()]]
            ),
            'cart' => $this->client->getAsync('wc/store/v1/cart'),
        ];

        $responses = Promise\Utils::settle($promises)->wait();

        $logged = false;
        if ($responses['auth']['state'] === 'fulfilled') {
            $logged = true;
        }

        $rejected = $this->rejected($responses, ['cart']);
        if ($rejected) {
            throw ValidationException::withMessages([
                'exception' => [__('Can not process.')],
            ]);
        }

        $cart = $this->frontCart->cartResponse($responses['cart']['value']);

        if (! $cart['items_count']) {
            throw ValidationException::withMessages([
                'exception' => [__('Can not process.')],
            ]);
        }

        if (! $cart['billing_address']['email']) {
            throw ValidationException::withMessages([
                'exception' => [__('Can not process.')],
            ]);
        }

        $password = $this->frontCart->createAccountPassword();

        $checkoutHeaders = [];

        if ($logged) {
            $password = null;
            $cart['billing_address']['email'] = $this->auth->customerEmail();
            $checkoutHeaders['Authorization'] = 'Bearer '.$this->auth->token();
        }

        $promises = [
            'checkout' => $this->client->postAsync(
                uri: 'wc/store/v1/checkout',
                options: [
                    'headers' => $checkoutHeaders,
                    'json' => [
                        'create_account' => (bool) $password,
                        'customer_password' => $password ?? '',
                        'billing_address' => $cart['billing_address'],
                        'shipping_address' => $cart['shipping_address'],
                        'payment_method' => 'p24-online-payments-'.self::paymentMethods[$request->payment_method],
                        'payment_data' => [
                            ['key' => 'regulation', 'value' => true],
                            ['key' => 'wc-p24-online-payments-'.self::paymentMethods[$request->payment_method].'-new-payment-method', 'value' => false],
                        ],
                    ],
                ]
            ),
        ];

        try {
            $responses = Promise\Utils::unwrap($promises);
        } catch (ResponseException $e) {
            $this->processExceptionMessage($e);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'exception' => [__('Can not process.')],
            ]);
        }

        $order = json_decode($responses['checkout']->getBody()->getContents(), true);

        return Inertia::location($order['payment_result']['redirect_url']);
    }

    /**
     * @throws ValidationException
     */
    protected function processExceptionMessage(ResponseException $e): void
    {
        $response = json_decode($e->getResponse()->getBody(), true);

        if (! isset($response['code'])) {
            throw ValidationException::withMessages([
                'exception' => [__('Can not process.')],
            ]);
        }

        throw ValidationException::withMessages([
            'exception' => [$response['message']],
        ]);
    }

    public function index(Request $request): Response|RedirectResponse
    {
        $promises = [
            'auth' => $this->client->postAsync(
                uri: 'jwt-auth/v1/token/validate',
                options: ['headers' => ['Authorization' => 'Bearer '.$this->auth->token()]]
            ),
            'settings' => $this->client->getAsync(
                uri: 'wc/store/v1/settings'
            ),
            'cart' => $this->client->getAsync('wc/store/v1/cart'),
        ];

        $responses = Promise\Utils::settle($promises)->wait();

        $logged = false;
        if ($responses['auth']['state'] === 'fulfilled') {
            $logged = true;
        }

        $rejected = $this->rejected($responses, ['cart']);
        if ($rejected) {
            abort(503);
        }

        $cart = $this->frontCart->cartResponse($responses['cart']['value']);

        if (! $cart['items_count']) {
            return redirect()->route('cart.index');
        }

        $password = $this->frontCart->createAccountPassword();

        return Inertia::render('Checkout/Index', [
            'settings' => json_decode($responses['settings']['value']->getBody()->getContents(), true),
            'cart' => $cart,
            'create_account' => (bool) $password,
            'password' => $password,
            'logged' => $logged,
            'customer_email' => $logged ? $this->auth->customerEmail() : '',
        ]);
    }

    public function shipping(Request $request): Response|RedirectResponse
    {
        $promises = [
            'cart' => $this->client->getAsync('wc/store/v1/cart'),
            'settings' => $this->client->getAsync(
                uri: 'wc/store/v1/settings'
            ),
        ];

        try {
            $responses = Promise\Utils::unwrap($promises);
        } catch (\Throwable $e) {
            abort(404);
        }

        $cart = $this->frontCart->cartResponse($responses['cart']);

        if (! $cart['items_count']) {
            return redirect()->route('cart.index');
        }

        if (! $cart['billing_address']['email']) {
            return redirect()->route('checkout.index');
        }

        return Inertia::render('Checkout/Shipping', [
            'cart' => $cart,
            'settings' => json_decode($responses['settings']->getBody()->getContents(), true),
        ]);
    }

    public function payment(Request $request): Response|RedirectResponse
    {
        $promises = [
            'cart' => $this->client->getAsync('wc/store/v1/cart'),
        ];

        try {
            $responses = Promise\Utils::unwrap($promises);
        } catch (\Throwable $e) {
            abort(404);
        }

        $cart = $this->frontCart->cartResponse($responses['cart']);

        if (! $cart['items_count']) {
            return redirect()->route('cart.index');
        }

        if (! $cart['billing_address']['email']) {
            return redirect()->route('checkout.index');
        }

        return Inertia::render('Checkout/Payment', [
            'cart' => $cart,
        ]);
    }

    public function order(Request $request, $order): Response|RedirectResponse
    {
        if (in_array(request()->header('Referer'), [
            'https://sandbox-go.przelewy24.pl/',
            'https://go.przelewy24.pl/',
        ])) {
            $this->frontCart->clearCartToken();
            $this->frontCart->clearCreateAccountPassword();

            return response()
                ->redirectToRoute('checkout.order', ['order' => $order, 'key' => $request->key])
                ->header('Referrer-Policy', 'no-referrer');
        }

        $promises = [
            'order' => $this->client->getAsync(
                uri: 'wc/store/v1/order/'.$order.'?key='.$request->key.'&billing_email='.$request->email,
                options: ['headers' => ['Authorization' => 'Bearer '.$this->auth->token()]]
            ),
            'cart' => $this->client->getAsync('wc/store/v1/cart'),
            'auth' => $this->client->postAsync(
                uri: 'jwt-auth/v1/token/validate',
                options: [
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->auth->token(),
                    ],
                ]
            ),
        ];

        $responses = Promise\Utils::settle($promises)->wait();

        $logged = false;
        if ($responses['auth']['state'] === 'fulfilled') {
            $logged = true;
        }

        $rejected = $this->rejected($responses, ['cart']);
        if ($rejected) {
            abort(503);
        }

        $rejected = $this->rejected($responses, ['order']);
        $orderRejected = false;
        $orderRejectedCode = null;
        if ($rejected) {
            $orderRejected = true;
            $orderRejectedCode = $this->orderRejectedCode($responses['order']['reason']);
        }

        $orderId = $order;
        $order = [];
        if (! $orderRejected) {
            $order = $this->frontOrder->orderResponse($responses['order']['value']);
        }

        return Inertia::render('Checkout/Order', [
            'order' => $order,
            'cart' => $this->frontCart->cartResponse($responses['cart']['value']),
            'orderId' => (int) $orderId,
            'logged' => $logged,
            'orderRejected' => $orderRejected,
            'orderRejectedCode' => $orderRejectedCode,
        ]);
    }

    /**
     * Guzzle 8 exposes the response only on ResponseException (ClientException, ServerException);
     * other rejection reasons carry none.
     */
    protected function orderRejectedCode(mixed $reason): ?string
    {
        if (! $reason instanceof ResponseException) {
            return null;
        }

        $data = json_decode((string) $reason->getResponse()->getBody(), true);

        return is_array($data) ? ($data['code'] ?? null) : null;
    }
}
