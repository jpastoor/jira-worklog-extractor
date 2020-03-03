FROM php:7.4-cli

RUN apt-get update && apt-get install -y \
    libzip-dev \
	git-core \
	zip \
    unzip \
&& rm -rf /var/lib/apt/lists/*

# Everything that does not PECL
RUN docker-php-ext-install zip

# Install composer
COPY --from=composer:1.9 /usr/bin/composer /usr/bin/composer
ENV PATH /root/.composer/vendor/bin:$PATH
