# --- PHP 相依套件階段 ---
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --ignore-platform-reqs

# --- 應用程式映像檔 ---
FROM php:8.4-fpm-alpine AS app

RUN apk add --no-cache \
        nginx \
        supervisor \
        bash \
        curl \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        postgresql-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mysqli \
        pdo_pgsql \
        gd \
        zip \
        intl \
        mbstring \
        opcache \
        bcmath

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

# php:fpm-alpine 預設 pm.max_children=5，那是給極小容器的值 —— 每個節點最多同時處理
# 5 個請求，水平擴展再多節點也會卡在這個天花板上。
#
# static 而不是 dynamic：容器的資源是固定配額，沒必要讓 fpm 反覆 fork/kill 去「省」
# 記憶體 —— 那只會在流量尖峰、也就是最不該多花時間的時候，多付一次建立行程的成本。
#
# ⚠️ 這個值是依「max_children × 單一行程大小（Laravel 約 30-60MB）≤ 節點記憶體」推算
# 出來的工程判斷，不是實測調出來的。本機量不出差異：3 個節點打 200 個併發請求時，
# 5 個與 20 個 worker 的吞吐都落在 90-112 req/s 的雜訊範圍內，而三個節點的 CPU 全程
# 低於 10% —— 瓶頸在壓測用戶端（200 個 forked 行程各自建 Guzzle client），不在 fpm。
# 真正該回頭調它的時機，是節點上線後觀察到 fpm 的請求佇列積壓。
RUN { \
        echo '[www]'; \
        echo 'pm = static'; \
        echo 'pm.max_children = 20'; \
    } > /usr/local/etc/php-fpm.d/zz-pool.conf

WORKDIR /var/www/html

# 這個容器要不要自己處理佇列。supervisord.conf 讀不到這個變數會直接解析失敗，
# 因此預設值必須存在於映像裡 —— 單容器部署靠的就是這個 true。
ENV QUEUE_IN_WEB=true

COPY . .
COPY --from=vendor /app/vendor ./vendor

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
