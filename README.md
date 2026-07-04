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

Before starting the project on a production server, replace `localhost` with your production domain in the following files:

* `app/.env` — set `APP_URL` to `https://your-domain.tld`
* `wordpress/.env` — set `WP_HOME` to `https://your-domain.tld/wordpress` (`WP_SITEURL` is derived from it)
* `docker/app/supervisord.conf` — replace `--host=localhost` in the `octane:frankenphp` command with `--host=your-domain.tld`
* `install.sh` — set `WORDPRESS_URL` to `https://your-domain.tld/wordpress`

After updating these values, rebuild the app image and bring the stack up:

```bash
./create-network.sh   # only the first time
./build.sh
./up.sh
./install.sh          # only the first time — seeds WordPress
```

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
