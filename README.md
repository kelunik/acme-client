# acme

![unstable](https://img.shields.io/badge/api-unstable-orange.svg?style=flat-square)
![MIT license](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)

`kelunik/acme-client` is a standalone ACME client written in PHP.
It's an alternative for the [official client](https://github.com/letsencrypt/letsencrypt) which is written in python.

> **Warning**: This software is under development. Use at your own risk.

## Installation

**Requirements**

* PHP 5.5+
* Composer

**Instructions**

```bash
# Clone repository
git clone https://github.com/kelunik/acme-client && cd acme-client

# Checkout latest release
git checkout $(git describe --tags `git rev-list --tags --max-count=1`)

# Install dependencies
composer install --no-dev
```

## Migration from 0.1.x to 0.2.x

```bash
# Start in ./data
cd data

# Move your account key to new location:

mkdir accounts
mv account/key.pem accounts/acme-v01.api.letsencrypt.org.directory.pem
# or accounts/acme-staging.api.letsencrypt.org.directory.pem if it's a staging key

# account should now be empty or contain just a config.json, you can delete the folder then
rm -rf account

# Migrate certificates to new location:

cd certs
mkdir acme-v01.api.letsencrypt.org.directory

# Move all your certificate directories
# Repeat for all directories!
mv example.com acme-v01.api.letsencrypt.org.directory
# or acme-staging.api.letsencrypt.org.directory

# Delete all config.json files which may exist
find -name "config.json" | xargs rm

# Update to current version
git checkout master && git pull

# Check out latest release
git checkout $(git describe --tags `git rev-list --tags --max-count=1`)

# Update dependencies
composer update --no-dev

# Reconfigure your webserver to use the new paths
# and check (and fix) your automation commands.
```

## Usage

> **Note**: This client stores all data in `./data`, be sure to backup this folder regularly.
> It contains your account keys, domain keys and certificates.

Before you can issue certificates, you have to register an account first and read and understand the terms of service of the ACME CA you're using.
For Let's Encrypt there's a [subscriber agreement](https://letsencrypt.org/repository/) you have to accept.

By using this client you agree to any agreement and any further updates by continued usage.
You're responsible to react to updates and stop the automation if you no longer agree with the terms of service.

```
bin/acme setup -s letsencrypt --email me@example.com
```

`-s` / `--server` can either be a URI or a shortcut. Available shortcuts:
 * `letsencrypt` / `letsencrypt:production`
 * `letsencrypt:staging`

After a successful registration you're able to issue certificates.
This client assumes you have a HTTP server setup and running.
You must have a document root setup in order to use this client.

```
bin/acme issue -s letsencrypt -d example.com:www.example.com -p /var/www/example.com
```

To revoke a certificate, you need a valid account key currently, just like for issuance.

```
bin/acme revoke --name example.com
```

For renewal, there's the `bin/acme check` subcommand.
It exists with a non-zero exit code, if the certificate is going to expire soon.
Default check time is 30 days, but you can use `--ttl` to customize it.

You may use this as daily cron:

```
bin/acme check --name example.com --ttl 30 -s letsencrypt || bin/acme issue ...
```

You can also use a more advanced script to automatically reload the server as well.

```bash
#!/usr/bin/env bash

cd /git/kelunik/acme-client

bin/acme check --name example.com --ttl 30 -s letsencrypt

if [ $? -eq 1 ]; then
        bin/acme issue -d example.com:www.example.com -p /var/www -s letsencrypt

        if [ $? -eq 0 ]; then
                nginx -t -q

                if [ $? -eq 0 ]; then
                        nginx -s reload
                fi
        fi
fi
```