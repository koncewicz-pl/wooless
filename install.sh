#!/bin/bash
set -e

WORDPRESS_URL="https://localhost/wordpress"
WORDPRESS_TITLE="Wooless Shop"
WORDPRESS_ADMIN_USER="wordpress"
WORDPRESS_ADMIN_PASSWORD="secret"
WORDPRESS_ADMIN_EMAIL="marek@koncewicz.io"

WP="./bin/wordpress vendor/bin/wp"

if $WP core is-installed 2>/dev/null; then
  echo "WordPress is already installed. Aborting."
  exit 1
fi

echo "Installing WordPress..."
$WP core install \
    --url="$WORDPRESS_URL" \
    --title="$WORDPRESS_TITLE" \
    --admin_user="$WORDPRESS_ADMIN_USER" \
    --admin_password="$WORDPRESS_ADMIN_PASSWORD" \
    --admin_email="$WORDPRESS_ADMIN_EMAIL" \
    --skip-email

echo "Setting language to Polish..."
$WP language core install pl_PL
$WP site switch-language pl_PL

echo "Setting permalinks..."
$WP option update permalink_structure '/%postname%/'
#$WP rewrite flush

echo "Activating ACF plugin..."
$WP plugin activate advanced-custom-fields

echo "Activating ACF to REST API plugin..."
$WP plugin activate acf-to-rest-api

echo "Activating WooCommerce plugin..."
$WP plugin activate woocommerce
#$WP wc update
#$WP action-scheduler run

echo "Configuring WooCommerce..."
$WP option update woocommerce_store_address "Mińska 25B"
$WP option update woocommerce_store_address_2 "U2"
$WP option update woocommerce_store_city "Warszawa"
$WP option update woocommerce_store_postcode "03-808"

$WP option update woocommerce_default_country "PL"
$WP option update woocommerce_default_customer_address "base"

$WP option update woocommerce_currency "PLN"
$WP option update woocommerce_currency_pos "right_space"

$WP option update woocommerce_price_thousand_sep " "
$WP option update woocommerce_price_decimal_sep ","
$WP option update woocommerce_price_num_decimals "2"
$WP option update woocommerce_prices_include_tax "no"

$WP option update woocommerce_tax_based_on "shipping"
$WP option update woocommerce_tax_round_at_subtotal "no"
$WP option update woocommerce_tax_display_shop "excl"
$WP option update woocommerce_tax_display_cart "excl"
$WP option update woocommerce_tax_total_display "itemized"

$WP option update woocommerce_shipping_tax_class "inherit"
$WP option update woocommerce_shipping_cost_requires_address "no"
$WP option update woocommerce_shipping_debug_mode "no"

$WP option update woocommerce_enable_coupons "no"
$WP option update woocommerce_enable_ajax_add_to_cart "yes"
$WP option update woocommerce_enable_reviews "yes"
$WP option update woocommerce_enable_review_rating "yes"
$WP option update woocommerce_enable_shipping_calc "yes"
$WP option update woocommerce_enable_guest_checkout "yes"
$WP option update woocommerce_enable_checkout_login_reminder "no"
$WP option update woocommerce_enable_signup_and_login_from_checkout "yes"
$WP option update woocommerce_enable_delayed_account_creation "no"
$WP option update woocommerce_enable_myaccount_registration "no"

$WP option update woocommerce_allowed_countries "specific"
$WP option update woocommerce_specific_allowed_countries --format=json '["PL"]'

echo "Creating shipping zone..."
SHIPPING_ZONE_ID=$($WP wc shipping_zone create \
    --name="Polska" \
    --user=$WORDPRESS_ADMIN_USER \
    --porcelain)

$WP wc shipping_zone_method create $SHIPPING_ZONE_ID \
    --method_id=flat_rate \
    --enabled=1 \
    --settings='{"title":"InPost Paczkomaty 24/7","cost":"15","tax_status":"taxable"}' \
    --user=$WORDPRESS_ADMIN_USER \
    --porcelain

$WP wc shipping_zone_method create $SHIPPING_ZONE_ID \
    --method_id=flat_rate \
    --enabled=1 \
    --settings='{"title":"DPD Pickup","cost":"17","tax_status":"taxable"}' \
    --user=$WORDPRESS_ADMIN_USER \
    --porcelain

$WP wc shipping_zone_method create $SHIPPING_ZONE_ID \
    --method_id=flat_rate \
    --enabled=1 \
    --settings='{"title":"Kurier InPost","cost":"20","tax_status":"taxable"}' \
    --user=$WORDPRESS_ADMIN_USER \
    --porcelain

$WP wc shipping_zone_method create $SHIPPING_ZONE_ID \
    --method_id=flat_rate \
    --enabled=1 \
    --settings='{"title":"Kurier DPD","cost":"24","tax_status":"taxable"}' \
    --user=$WORDPRESS_ADMIN_USER \
    --porcelain

echo "Activating Przelewy24 plugin..."
$WP plugin activate woo-przelewy24

echo "Activating Furgonetka plugin..."
$WP plugin activate furgonetka

echo "Activating Wooless plugin..."
$WP plugin activate wooless
$WP wooless acf-import /var/www/web/app/plugins/wooless/acf/data.json

echo "Adding products..."
CATEGORY_ID=$($WP term create product_cat "Bluzy" --slug="hoodies" --porcelain)
$WP wooless acf-update \
    --id="term_$CATEGORY_ID" \
    --data="{\"en_product_category_name\": \"Hoodies\"}"

PRODUCT_ID=$($WP wc product create \
    --name="Bluza z kapturem" \
    --slug="woo-hoodie" \
    --type="simple" \
    --status="publish" \
    --sku="woo-hoodie" \
    --regular_price="45" \
    --description="Bluza z kapturem wykonana z miękkiego i przyjemnego w dotyku materiału. Jej luźny krój zapewnia komfort noszenia, a wyrazisty nadruk z emotikonem dodaje charakteru i pozytywnej energii każdej stylizacji. Świetnie sprawdzi się zarówno do codziennych, jak i sportowych outfitów. Posiada ściągacze na rękawach i dole, a także regulowany kaptur dla dodatkowej wygody." \
    --short_description="Stylowa i wygodna bluza z kapturem – idealna na co dzień." \
    --stock_quantity="20" \
    --categories="[{\"id\":$CATEGORY_ID}]" \
    --images='[{"src": "https://woocommercecore.mystagingwebsite.com/wp-content/uploads/2017/12/hoodie-2.jpg"}]' \
    --user=$WORDPRESS_ADMIN_USER \
    --porcelain)
$WP wooless acf-update \
    --id=$PRODUCT_ID \
    --data='{"en_product_name": "Hoodie with Logo", "en_product_description": "This hoodie is made from soft, high-quality fabric, ensuring maximum comfort. The relaxed fit allows for ease of movement, while the playful emoji design adds a unique and cheerful touch to your wardrobe. Ideal for casual and sporty looks, it features ribbed cuffs and hem, along with an adjustable hood for extra coziness.", "en_product_short_description": "A stylish and comfortable hoodie featuring a fun emoji print – perfect for everyday wear."}'

CATEGORY_ID=$($WP term create product_cat "Akcesoria" --slug="accessories" --porcelain)
$WP wooless acf-update \
    --id="term_$CATEGORY_ID" \
    --data="{\"en_product_category_name\": \"Accessories\"}"

PRODUCT_ID=$($WP wc product create \
    --name="Okulary przeciwsłoneczne" \
    --slug="woo-sunglasses" \
    --type="simple" \
    --status="publish" \
    --sku="woo-sunglasses" \
    --regular_price="22" \
    --description="Stylowe okulary przeciwsłoneczne, które chronią Twoje oczy przed szkodliwym promieniowaniem UV. Wykonane z lekkiego, ale trwałego materiału, zapewniają komfort noszenia przez cały dzień. Idealne na słoneczne dni, do codziennych stylizacji i wakacyjnych wyjazdów." \
    --short_description="Modne i wygodne okulary przeciwsłoneczne, idealne na lato." \
    --stock_quantity="25" \
    --categories="[{\"id\":$CATEGORY_ID}]" \
    --images='[{"src": "https://woocommercecore.mystagingwebsite.com/wp-content/uploads/2017/12/sunglasses-2.jpg"}]' \
    --user=$WORDPRESS_ADMIN_USER \
    --porcelain)
$WP wooless acf-update \
    --id=$PRODUCT_ID \
    --data='{"en_product_name": "Sunglasses", "en_product_description": "Stylish sunglasses that protect your eyes from harmful UV rays. Made from lightweight yet durable material, they ensure all-day comfort. Perfect for sunny days, everyday outfits, and vacation trips.", "en_product_short_description": "Trendy and comfortable sunglasses, perfect for summer."}'

CATEGORY_ID=$($WP term create product_cat "T-Shirty" --slug="t-shirts" --porcelain)
$WP wooless acf-update \
    --id="term_$CATEGORY_ID" \
    --data="{\"en_product_category_name\": \"T-Shirts\"}"

PRODUCT_ID=$($WP wc product create \
    --name="T-Shirt z logo" \
    --slug="woo-tshirt-logo" \
    --type="simple" \
    --status="publish" \
    --sku="woo-tshirt-logo" \
    --regular_price="25" \
    --description="Klasyczny t-shirt z nadrukowanym logo, wykonany z wysokiej jakości bawełny. Jest lekki, przewiewny i wygodny, idealny na każdą okazję. Doskonały wybór zarówno do codziennych stylizacji, jak i na luźniejsze spotkania." \
    --short_description="Uniwersalny t-shirt z logo, wygodny i stylowy." \
    --stock_quantity="40" \
    --categories="[{\"id\":$CATEGORY_ID}]" \
    --images='[{"src": "https://woocommercecore.mystagingwebsite.com/wp-content/uploads/2017/12/t-shirt-with-logo-1.jpg"}]' \
    --user=$WORDPRESS_ADMIN_USER \
    --porcelain)
$WP wooless acf-update \
    --id=$PRODUCT_ID \
    --data='{"en_product_name": "T-Shirt with Logo", "en_product_description": "A classic t-shirt with a printed logo, made from high-quality cotton. It is lightweight, breathable, and comfortable, perfect for any occasion. A great choice for everyday outfits and casual meetings.", "en_product_short_description": "A versatile t-shirt with a logo, comfortable and stylish."}'

echo "Removing default post..."
$WP post delete 1 --force

echo "Adding posts..."
CATEGORY_ID=$($WP term create category "Nowości" --slug="new-arrivals" --porcelain)

IMAGE_ID=$($WP media import "https://woocommercecore.mystagingwebsite.com/wp-content/uploads/2017/12/hoodie-2.jpg" --porcelain)
POST_ID=$($WP post create \
    --post_type="post" \
    --post_title="Ciepło, styl i odrobina humoru!" \
    --post_status="publish" \
    --post_content="Czy może być coś lepszego na chłodniejsze dni niż stylowa bluza z kapturem?" \
    --post_excerpt="Czy może być coś lepszego na chłodniejsze dni niż stylowa bluza z kapturem?" \
    --post_author=1 \
    --post_category="$CATEGORY_ID" \
    --porcelain)
$WP post meta update $POST_ID _thumbnail_id $IMAGE_ID

IMAGE_ID=$($WP media import "https://woocommercecore.mystagingwebsite.com/wp-content/uploads/2017/12/t-shirt-with-logo-1.jpg" --porcelain)
POST_ID=$($WP post create \
    --post_type="post" \
    --post_title="T-shirt z logo, który wyróżnia się z tłumu!" \
    --post_status="publish" \
    --post_content="Stylowe, wygodne i uniwersalne – idealne połączenie, które podkreśli Twój charakter." \
    --post_excerpt="Stylowe, wygodne i uniwersalne – idealne połączenie, które podkreśli Twój charakter." \
    --post_author=1 \
    --post_category="$CATEGORY_ID" \
    --porcelain)
$WP post meta update $POST_ID _thumbnail_id $IMAGE_ID

echo "Activating JWT Auth plugin..."
$WP plugin activate jwt-auth

echo "Finalizing WooCommerce..."
$WP option update woocommerce_coming_soon "no"
$WP option update woocommerce_store_pages_only "no"
$WP option update woocommerce_onboarding_profile --format=json '{"completed":true}'
$WP option update woocommerce_task_list_complete "yes"

echo "WordPress installed successfully!"
echo "Admin panel: $WORDPRESS_URL/wp/wp-admin/"
echo "User: $WORDPRESS_ADMIN_USER"
echo "Password: $WORDPRESS_ADMIN_PASSWORD"
