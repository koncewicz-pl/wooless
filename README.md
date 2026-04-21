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
