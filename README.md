# acme

![unstable](https://img.shields.io/badge/api-unstable-orange.svg?style=flat-square)
![MIT license](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)

`kelunik/acme-client` is a standalone ACME client written in PHP.
It's an alternative for the [official client](https://github.com/letsencrypt/letsencrypt) which is written in python.

> **Warning**: This software is under heavy development. Use at your own risk. Revocation is not yet supported by this client.

## Installation

```
git clone https://github.com/kelunik/acme-client
cd acme-client
composer install
```

## Usage

Before you can issue certificates, you have to register an account first and read and understand the terms of service of the ACME CA you're using.
For Let's Encrypt there's a [subscriber agreement](https://letsencrypt.org/repository/) you have to accept.

By using this client you agree to any agreement and any further updates by continued usage.
You're responsible to react to updates and stop the automation if you no longer agree with the terms of service.

```
sudo bin/acme register \
    --server acme-v01.api.letsencrypt.org/directory \
    --email me@example.com
```

After a successful registration you're able to issue certificates.
This client assumes you have a HTTP server setup and running.
You must have a document root setup in order to use this client.

```
sudo bin/acme issue \
    --server acme-v01.api.letsencrypt.org/directory \
    --domains example.com,www.example.com \
    --path /var/www/example.com
```

For renewal, just run this command again.