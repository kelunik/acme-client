# acme

![unstable](https://img.shields.io/badge/api-unstable-orange.svg?style=flat-square)
![MIT license](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)

`kelunik/acme-client` is a standalone ACME client written in PHP.
It's an alternative for the [official client](https://github.com/letsencrypt/letsencrypt) which is written in python.

> **Warning**: This software is under development. Use at your own risk.

The client has been updated on Mar 12th in a non-backwards compatible manner. Please review the changes or use a new clone.

## Installation

```
git clone https://github.com/kelunik/acme-client
cd acme-client
composer install
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