
FROM php:8.3-fpm

ARG DUCKDB_VERSION=2.0.0
ARG TARGETARCH

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    ca-certificates \
    libcurl4-openssl-dev \
    libzip-dev \
    && docker-php-ext-install curl zip pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Install DuckDB CLI
RUN set -eux; \
    case "${TARGETARCH}" in \
      amd64) \
        DUCKDB_SHA256="c6507206baf7793c6013dbd82368469a45f20dc0e17a6f92f204602f652ed2bc" \
        ;; \
      arm64) \
        DUCKDB_SHA256="859e1875d2a9515fbb4bf8871061437a14af82320da68c753f094c4b776348fe" \
        ;; \
      *) \
        echo "Unsupported architecture: ${TARGETARCH}"; exit 1 \
        ;; \
    esac; \
    DUCKDB_URL="https://github.com/duckdb/duckdb/releases/download/v${DUCKDB_VERSION}/duckdb-cli-linux-${TARGETARCH}.tar.gz"; \
    curl -fsSL "$DUCKDB_URL" -o /tmp/duckdb.tar.gz; \
    echo "${DUCKDB_SHA256}  /tmp/duckdb.tar.gz" | sha256sum -c -; \
    tar -xzf /tmp/duckdb.tar.gz -C /usr/local/bin; \
    chmod +x /usr/local/bin/duckdb; \
    duckdb --version; \
    rm /tmp/duckdb.tar.gz

WORKDIR /var/www/html


# PHP-FPM listens on port 9000
EXPOSE 9000

CMD ["php-fpm"]
