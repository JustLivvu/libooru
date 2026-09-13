#!/bin/sh
set -eu

APP_DIR=/home/admin/Homelab/Services/libooru
PM2_USER=admin
PM2_HOME_DIR=/home/admin/.pm2
PM2_SERVER_NAME='server libooru.rape.ink'
NGINX_CONF=/usr/local/etc/nginx/nginx.conf
FPM_POOL_DIR=/usr/local/etc/php-fpm.d

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root: sudo sh $APP_DIR/deploy/freebsd/install.sh" >&2
    exit 1
fi

run_pm2() {
    su -m "$PM2_USER" -c "env PM2_HOME=$PM2_HOME_DIR /usr/local/bin/pm2 $*"
}

echo "Installing nginx..."
pkg install -y nginx

echo "Installing nginx and PHP-FPM configuration..."
install -d -o root -g wheel -m 0755 /usr/local/etc/nginx "$FPM_POOL_DIR"
if [ -f "$NGINX_CONF" ] && [ ! -f "$NGINX_CONF.before-libooru" ]; then
    cp -p "$NGINX_CONF" "$NGINX_CONF.before-libooru"
fi
if [ -f "$FPM_POOL_DIR/www.conf" ] && [ ! -f "$FPM_POOL_DIR/www.conf.disabled-by-libooru" ]; then
    mv "$FPM_POOL_DIR/www.conf" "$FPM_POOL_DIR/www.conf.disabled-by-libooru"
fi
install -o root -g wheel -m 0644 "$APP_DIR/deploy/freebsd/nginx.conf" "$NGINX_CONF"
install -o root -g wheel -m 0644 "$APP_DIR/deploy/freebsd/libooru-fpm.conf" "$FPM_POOL_DIR/libooru.conf"

echo "Granting PHP-FPM access to writable application data..."
chown -R www:admin "$APP_DIR/data"
find "$APP_DIR/data" -type d -exec chmod 2770 {} +
find "$APP_DIR/data" -type f -exec chmod 0660 {} +

sysrc php_fpm_enable=YES
sysrc nginx_enable=YES

echo "Validating configuration..."
/usr/local/sbin/php-fpm -t
/usr/local/sbin/nginx -t -c "$NGINX_CONF"

if service php_fpm onestatus >/dev/null 2>&1; then
    service php_fpm restart
else
    service php_fpm start
fi

rollback() {
    echo "Deployment failed; restoring the PM2 PHP server..." >&2
    service nginx onestop >/dev/null 2>&1 || true
    run_pm2 "restart '$PM2_SERVER_NAME'" >/dev/null 2>&1 || true
}

trap rollback EXIT INT TERM
run_pm2 "stop '$PM2_SERVER_NAME'"

if service nginx onestatus >/dev/null 2>&1; then
    service nginx restart
else
    service nginx start
fi

/usr/local/bin/curl --fail --silent --show-error --max-time 15 http://127.0.0.1:40001/ >/dev/null

run_pm2 "delete '$PM2_SERVER_NAME'"
run_pm2 save
trap - EXIT INT TERM

echo "Libooru is running through nginx + PHP-FPM on localhost:40001."
