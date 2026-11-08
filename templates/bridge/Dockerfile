FROM php:8.3-cli

RUN php -m | grep -qi '^curl$' || ( \
      apt-get update \
      && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
      && docker-php-ext-install curl \
      && rm -rf /var/lib/apt/lists/* \
    )

WORKDIR /app
COPY bridge.php /app/bridge.php

CMD ["php", "/app/bridge.php"]
