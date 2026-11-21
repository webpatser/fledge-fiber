FROM php:8.6-cli

# The stock image lacks pcntl, sockets and mbstring (the event loop driver
# tests need the first two for signals and socket options).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install -j"$(nproc)" pcntl sockets mbstring \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
