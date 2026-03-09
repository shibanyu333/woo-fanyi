FROM alpine:3.20

RUN apk add --no-cache \
    php82 \
    php82-session \
    php82-imap \
    php82-pdo_sqlite \
    php82-sqlite3 \
    php82-mbstring \
    php82-fileinfo \
    php82-openssl \
    php82-phar \
    php82-tokenizer \
    php82-ctype \
    php82-iconv \
    && ln -sf /usr/bin/php82 /usr/bin/php

WORKDIR /app
COPY public ./public
COPY src ./src
COPY data ./data

RUN mkdir -p /app/data

EXPOSE 8099
CMD ["php", "-S", "0.0.0.0:8099", "-t", "public"]
