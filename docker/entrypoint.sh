#!/bin/sh
set -eu

# Validate configuration without echoing values, opening connections, or seeding.
php <<'PHP'
<?php
$errors = [];
$value = static fn (string $name): string => (string) (getenv($name) === false ? '' : getenv($name));
if ($value('APP_ENV') !== 'production') {
    $errors[] = 'APP_ENV harus production untuk image deployment.';
}
if (filter_var($value('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false) {
    $errors[] = 'APP_DEBUG harus false.';
}
if (parse_url($value('APP_URL'), PHP_URL_SCHEME) !== 'https' || ! parse_url($value('APP_URL'), PHP_URL_HOST)) {
    $errors[] = 'APP_URL wajib diatur ke URL HTTPS deployment yang sebenarnya.';
}
$key = $value('APP_KEY');
$decodedKey = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
if (! is_string($decodedKey) || strlen($decodedKey) !== 32) {
    $errors[] = 'APP_KEY wajib berisi kunci AES-256 yang tetap dan valid.';
}
if (! in_array($value('DB_CONNECTION'), ['mysql', 'mariadb'], true)) {
    $errors[] = 'DB_CONNECTION harus mysql atau mariadb dengan database eksternal.';
}
$host = $value('DB_HOST');
if ($value('DB_URL') !== '') {
    $url = parse_url($value('DB_URL'));
    if (! is_array($url) || ! in_array($url['scheme'] ?? '', ['mysql', 'mariadb'], true)
        || empty($url['user']) || empty($url['pass']) || empty(trim($url['path'] ?? '', '/'))) {
        $errors[] = 'DB_URL harus merupakan URL MySQL/MariaDB eksternal yang lengkap.';
    }
    $host = is_array($url) ? ($url['host'] ?? '') : '';
} else {
    foreach (['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $name) {
        if ($value($name) === '') {
            $errors[] = $name.' wajib diatur pada environment deployment.';
        }
    }
}
$host = strtolower(trim($host, '[]'));
if ($host === '' || in_array($host, ['localhost', '::1', '0.0.0.0', 'host.docker.internal'], true)
    || str_starts_with($host, '127.')) {
    $errors[] = 'DB_HOST/DB_URL harus menuju database eksternal, bukan localhost.';
}
if ($value('PRIVATE_FILESYSTEM_DISK') !== 's3') {
    $errors[] = 'PRIVATE_FILESYSTEM_DISK harus s3 agar dokumen tidak hilang di filesystem sementara.';
}
foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_BUCKET'] as $name) {
    if ($value($name) === '') {
        $errors[] = $name.' wajib diatur untuk penyimpanan privat persisten.';
    }
}
if ($value('AWS_ENDPOINT') !== '' && parse_url($value('AWS_ENDPOINT'), PHP_URL_SCHEME) !== 'https') {
    $errors[] = 'AWS_ENDPOINT penyimpanan produksi harus menggunakan HTTPS.';
}
foreach (['CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION'] as $name) {
    if ($value($name) !== 'database') {
        $errors[] = $name.' harus database pada konfigurasi deployment ini.';
    }
}
if ($value('PORT') !== '80') {
    $errors[] = 'PORT harus 80 sesuai konfigurasi Apache image ini.';
}
foreach (['LARAVEL_STORAGE_PATH', 'VIEW_COMPILED_PATH', 'APP_CONFIG_CACHE', 'APP_EVENTS_CACHE',
    'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_SERVICES_CACHE', 'APACHE_RUN_DIR',
    'APACHE_LOCK_DIR', 'APACHE_PID_FILE', 'APACHE_LOG_DIR'] as $name) {
    $path = $value($name);
    if (! str_starts_with($path, '/tmp/pam-storage/') && $path !== '/tmp/pam-storage') {
        $errors[] = $name.' harus berada di bawah /tmp/pam-storage.';
    }
    if (str_contains($path, '/../') || str_ends_with($path, '/..')) {
        $errors[] = $name.' tidak boleh keluar dari direktori sementara aplikasi.';
    }
}
if ($errors !== []) {
    file_put_contents('php://stderr', "Konfigurasi deployment PAM belum lengkap:\n- ".implode("\n- ", $errors)."\n");
    exit(1);
}
PHP

umask 027
mkdir -p \
    "$LARAVEL_STORAGE_PATH/app/private" \
    "$LARAVEL_STORAGE_PATH/app/public" \
    "$LARAVEL_STORAGE_PATH/framework/cache/data" \
    "$LARAVEL_STORAGE_PATH/framework/sessions" \
    "$LARAVEL_STORAGE_PATH/framework/views" \
    "$LARAVEL_STORAGE_PATH/logs" \
    "$LARAVEL_STORAGE_PATH/tmp" \
    "$LARAVEL_STORAGE_PATH/fonts" \
    "$VIEW_COMPILED_PATH" \
    "$APACHE_RUN_DIR" "$APACHE_LOCK_DIR" "$APACHE_LOG_DIR"
for cache_file in "$APP_CONFIG_CACHE" "$APP_EVENTS_CACHE" "$APP_PACKAGES_CACHE" "$APP_ROUTES_CACHE" "$APP_SERVICES_CACHE"; do
    mkdir -p "$(dirname "$cache_file")"
done

if [ -f /var/www/html/bootstrap/cache/packages.php ]; then
    cp /var/www/html/bootstrap/cache/packages.php "$APP_PACKAGES_CACHE"
fi
if [ -f /var/www/html/bootstrap/cache/services.php ]; then
    cp /var/www/html/bootstrap/cache/services.php "$APP_SERVICES_CACHE"
fi
# Apache's default virtual host writes through these standard log filenames.
ln -sf /proc/self/fd/2 "$APACHE_LOG_DIR/error.log"
ln -sf /proc/self/fd/1 "$APACHE_LOG_DIR/access.log"
ln -sf /proc/self/fd/1 "$APACHE_LOG_DIR/other_vhosts_access.log"
chown -R -h www-data:www-data "$LARAVEL_STORAGE_PATH"

exec docker-php-entrypoint "$@"
