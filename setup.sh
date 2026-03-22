#!/bin/bash
set -euo pipefail

echo "=== Claude Connect Host Setup ==="
echo ""

# Phase 1: PHP 8.3 + Extensions
echo "--- Step 1: Adding ondrej/php PPA ---"
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update

echo "--- Step 2: Installing PHP 8.3 + extensions ---"
sudo apt install -y \
    php8.3-cli php8.3-dev php8.3-redis php8.3-curl \
    php8.3-mbstring php8.3-xml php8.3-zip php8.3-bcmath

echo "--- Step 3: Installing Swoole build dependencies ---"
sudo apt install -y \
    php-pear build-essential libssl-dev libcurl4-openssl-dev \
    libpcre3-dev libbrotli-dev zlib1g-dev

echo "--- Step 4: Installing Swoole via PECL ---"
sudo pecl install swoole <<< $'\n\n\n\n\n\n'
echo "extension=swoole.so" | sudo tee /etc/php/8.3/cli/conf.d/20-swoole.ini

echo "--- Step 5: Installing Composer ---"
if ! command -v composer &> /dev/null; then
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
fi

echo "--- Step 6: Verify PHP + Swoole ---"
php -v
php -m | grep swoole
php -m | grep redis

echo "--- Step 7: Starting Redis container ---"
cd "$(dirname "$0")"
docker compose up -d

echo "--- Step 8: Installing Composer dependencies ---"
composer install --no-dev --optimize-autoloader

echo "--- Step 9: Setting up runtime directory ---"
mkdir -p runtime
chmod 755 runtime

echo "--- Step 10: Installing systemd service ---"
sudo cp claude-connect.service /etc/systemd/system/claude-connect.service
sudo systemctl daemon-reload
sudo systemctl enable claude-connect

echo ""
echo "=== Setup Complete ==="
echo ""
echo "To start the server:"
echo "  sudo systemctl start claude-connect"
echo ""
echo "To check status:"
echo "  sudo systemctl status claude-connect"
echo "  curl http://localhost:9501/health"
