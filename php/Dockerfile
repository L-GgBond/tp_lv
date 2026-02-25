# php/Dockerfile
FROM php:8.1-fpm-alpine

# 替换 Alpine 镜像源为阿里云，加速下载
RUN sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories

# 安装 TP 必须的系统依赖和 PHP 扩展
RUN apk add --no-cache \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql gd bcmath zip opcache

# 复制 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 设置 Composer 使用国内镜像
RUN composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/

WORKDIR /var/www