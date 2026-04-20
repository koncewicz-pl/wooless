<?php

namespace App\Http\Controllers;

use App\Services\Auth;
use App\Services\FrontCart;
use App\Services\WooCommerce\WooCommerceClient;
use GuzzleHttp\Promise;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function __construct(
        protected FrontCart $frontCart,
        protected Auth $auth,
        protected WooCommerceClient $client,
    )
    {}

    public function store(Request $request): RedirectResponse
    {
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
            return Inertia::render('Login/Index', [
                'cart' => []
            ]);
        }

        return Inertia::render('Login/Index', [
            'cart' => $this->frontCart->cartResponse($responses['cart']['value'])
        ]);
    }
}
