# Installation

## Installation using Phar

This is the preferred installation method for usage on a production system.

### Requirements

* PHP 5.5+

### Instructions

```bash
# Go to https://github.com/kelunik/acme-client/releases/latest
# Download the latest release archive.

# Make it executable.
chmod +x acme-client.phar

# Run it.
./acme-client.phar

# Or install it globally.
mv ./acme-client.phar /usr/local/bin/acme-client
acme-client
```

If you want to update, just replace the old `.phar` with a new one.

All commands require a `--storage` argument when using the Phar. That's the path where your keys and certificates will be stored.
On Unix you could use something like `--storage /etc/acme`.

You can add a file named `acme-client.yml` next to the `.phar` with the two keys `storage` and `server`.
These values will be used as default if you don't specify them, but you can still use another server by explicitly adding it as argument.

```yml
# Sample YAML configuration.

# Storage directory for certificates and keys.
storage: /etc/acme

# Server to use. Available shortcuts: letsencrypt, letsencrypt:staging
# You can also use full URLs to the directory resource of an ACME server
server: letsencrypt
```

## Installation using Composer

If you plan to actively develop this client, you probably don't want the Phar but install the dependencies using Composer.

### Requirements

* PHP 5.5+
* [Composer](https://getcomposer.org/)

### Instructions

```bash
# Clone repository
git clone https://github.com/kelunik/acme-client && cd acme-client

# Install dependencies
composer install
```

You can use `./bin/acme` as script instead of the Phar. Please note, that all data will be stored in `./data` as long as you don't provide the `--storage` argument.
