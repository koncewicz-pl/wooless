# Wooless

Mobile-first, self-hosted and simple WooCommerce Headless solution, which adapted for the Polish market.

* WordPress (Bedrock)
* WooCommerce
* FrankenPHP
* Laravel
* VueJS
* SSR
* InertiaJS
* Tailwind Plus
* Furgonetka
* Przelewy24

[Demo](https://sklep.wooless.pl)

## Production deployment

The public domain lives only in the two `.env` files; nothing under `docker/` or in the scripts
needs editing on the server:

* `app/.env` — set `OCTANE_HOST` to `your-domain.tld` and `APP_URL` to `https://your-domain.tld`.
  `OCTANE_REDIRECT_HOSTS` lists the aliases that redirect to the main domain (comma-separated;
  empty means `www.your-domain.tld`). Caddy obtains a certificate for the main domain and for every
  alias, so each of them needs a DNS record pointing at the server
* `wordpress/.env` — set `WP_HOME` to `https://your-domain.tld/wordpress` (`WP_SITEURL` is derived
  from it) and the `WP_ADMIN_*` values for the administrator account that `install.sh` creates

Caddy listens on ports 80 and 443 and redirects HTTP to HTTPS. After updating these values, build
the images and bring the stack up:

```bash
./create-network.sh   # only the first time
./build.sh
./up.sh
./install.sh          # only the first time — seeds WordPress with the account from wordpress/.env
```

After a later change of `OCTANE_HOST` or `OCTANE_REDIRECT_HOSTS` run `./bin/app php artisan config:cache` and
`docker exec wooless-app supervisorctl restart octane`.

## Local tunnel (ngrok)

To expose a locally running shop through an ngrok tunnel, replace the WordPress URL with your tunnel domain:

* `wordpress/.env` — set `WP_HOME` to `https://your-tunnel-domain.ngrok-free.dev/wordpress` (it overrides the database values)
* WordPress database — replace the URL in existing content:

```bash
./bin/wordpress vendor/bin/wp search-replace 'https://localhost/wordpress' 'https://your-tunnel-domain.ngrok-free.dev/wordpress' --skip-columns=guid
```

Then start the tunnel with the `Host` header rewritten to `localhost`, so requests match the app's vhost:

```bash
ngrok http --url=your-tunnel-domain.ngrok-free.dev --host-header=localhost 443
```
