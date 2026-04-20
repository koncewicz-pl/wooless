<?php

namespace App\Http\Controllers;

use App\Services\Auth;
use App\Services\FrontCart;
use App\Services\WooCommerce\WooCommerceClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function __construct(
        protected FrontCart $frontCart,
        protected Auth $auth,
        protected WooCommerceClient $client,
    )
    {}

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required','min:8'],
        ]);

        $promises = [
            'create_customer' => $this->client->postAsync(
                uri: 'wc/v3/customers',
                options: [
                    'headers' => [
                        'Authorization' => 'Basic ' . base64_encode(
                            string: config('services.wordpress.wc_key') . ':' . config('services.wordpress.wc_secret')
                        ),
                    ],
                    'json' => [
                        'email' => $request->email,
                        'username' => $request->email,
                        'password' => $request->password,
                    ],
                ]
            ),
        ];

        try {
            Promise\Utils::unwrap($promises);
        } catch (ClientException|RequestException $e) {
            $this->handleRegisterException($e);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'exception' => [__('Can not create account.')],
            ]);
        }

        $promises = [
            'auth' => $this->client->postAsync(
                uri: 'jwt-auth/v1/token',
                options: [
                    'json' => [
                        'username' => $request->email,
                        'password' => $request->password
                    ]
                ]
            )
        ];

        try {
            $responses = Promise\Utils::unwrap($promises);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'exception' => [__('E-mail or password are incorrect.')],
            ]);
        }

        $this->auth->save($responses['auth']);

        return response()->redirectToRoute('order.index');
    }

    protected function handleRegisterException(RequestException $e): void
    {
        if (!$e->hasResponse()) {
            throw ValidationException::withMessages([
                'exception' => [__('Can not create account.')],
            ]);
        }

        $resp = (string) $e->getResponse()->getBody();
        $json = json_decode($resp, true);

        if (!is_array($json)) {
            throw ValidationException::withMessages([
                'exception' => [__('Can not create account.')],
            ]);
        }

        $code = $json['code'] ?? null;

        if ($code === 'registration-error-email-exists') {
            throw ValidationException::withMessages([
                'email' => [__('An account with this email already exists. Please log in or use a different email address.')],
            ]);
        }

        if ($code === 'registration-error-invalid-email') {
            throw ValidationException::withMessages([
                'email' => [__('Please provide a valid email address.')],
            ]);
        }

        if ($code === 'registration-error-invalid-username') {
            throw ValidationException::withMessages([
                'email' => [__('Please provide a valid account username.')],
            ]);
        }

        if ($code === 'registration-error-username-exists') {
            throw ValidationException::withMessages([
                'email' => [__('An account is already registered with that username. Please choose another.')],
            ]);
        }

        // TODO: handle more error cases here

        throw ValidationException::withMessages([
            'exception' => [__('Can not create account.')],
        ]);
    }

    public function index(Request $request): Response|RedirectResponse
    {
        $promises = [
            'auth' => $this->client->postAsync(
                uri: 'jwt-auth/v1/token/validate',
                options: [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->auth->token()
                    ]
                ]
            ),

            'cart' => $this->client->getAsync('wc/store/v1/cart')
        ];

        $responses = Promise\Utils::settle($promises)->wait();

        if ($responses['auth']['state'] === 'fulfilled') {
            return response()->redirectToRoute('account.index');
        }

        $rejected = $this->rejected($responses, ['cart']);
        if ($rejected) {
            return Inertia::render('Register/Index', [
                'cart' => []
            ]);
        }

        return Inertia::render('Register/Index', [
            'cart' => $this->frontCart->cartResponse($responses['cart']['value'])
        ]);
    }
}
