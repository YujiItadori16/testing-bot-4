# Apache + PHP 8.2 image
FROM php:8.2-apache

# Make sure mod_rewrite is on
RUN a2enmod rewrite

# Recommended PHP settings
RUN cat > /usr/local/etc/php/conf.d/zz-php.ini <<'PHPINI'
display_errors=Off
log_errors=On
error_log=/var/log/apache2/php-error.log
PHPINI

# Copy app
WORKDIR /var/www/html
COPY . /var/www/html

# Ensure writable data file
RUN touch /var/www/html/users.json && \
    chown -R www-data:www-data /var/www/html && \
    chmod 664 /var/www/html/users.json

# Apache config: allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Environment (Render → set these in Dashboard; below are *safe fallbacks* for local dev)
ENV BOT_TOKEN="8401609959:AAFGmYh29uJM-JJNUMJc0ByKVfDfQSlILMc" \
    ADMIN_ID="1702919355" \
    CH1="@bigbumpersaleoffers" \
    CH1_LINK="https://t.me/bigbumpersaleoffers" \
    CH2="@backupchannelbum" \
    CH2_LINK="https://t.me/backupchannelbum" \
    CONTACT_LINK="https://t.me/rk_production_house" \
    WEBHOOK_SECRET="change-this-secret"

EXPOSE 80

# Run Apache in foreground
CMD ["apache2-foreground"]
