![`kelunik/acme-client`](./res/logo.png)

`kelunik/acme-client` is a command-line ACME client implemented in PHP, enabling the issuance and renewal of certificates via the ACME protocol used by [Let's Encrypt](https://letsencrypt.org). It supports PHP 8.1+ with OpenSSL and runs on Unix-like systems and Windows.

## Installation

### Requirements

* PHP 8.1+ with OpenSSL
* Unix-like system or Windows

### Installation using PHAR

This is the preferred installation method for usage on a production system. You can download `acme-client.phar` in the [release section](https://github.com/kelunik/acme-client/releases).

#### Instructions

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

All commands require a `--storage` argument when using the PHAR. That's the path where your keys and certificates will be stored.
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

### Installation using Composer

If you plan to actively develop this client, you don't want the PHAR but install the dependencies using [Composer](https://getcomposer.org/).

#### Instructions

```bash
# Clone repository
git clone https://github.com/kelunik/acme-client && cd acme-client

# Install dependencies
composer install
```

You can use `./bin/acme` as script instead of the PHAR. Please note, that all data will be stored in `./data` as long as you don't provide the `--storage` argument.

## Usage

The client stores your account keys, domain keys and certificates in a single directory. If you're using the PHAR,
you usually configure the storage in the configuration file. If you're using it with Composer, all data is stored in `./data`.

**Be sure to backup that directory regularly.**

Before you can issue certificates, you have to register an account. You have to read and understand the terms of service
of the certificate authority you're using. For the Let's Encrypt certificate authority, there's a
[subscriber agreement](https://letsencrypt.org/repository/) you have to accept.

By using this client you agree to any agreement and any further updates by continued usage. You're responsible to react
to updates and stop the automation if you no longer agree with the terms of service.

These usage instructions assume you have installed the client globally as a PHAR. If you are using the PHAR,
but don't have it globally, replace `acme-client` with the location to your PHAR or add that path to your `$PATH` variable.

### Configuration

The client can be configured using a (global) configuration file. The client takes the first available of
`./acme-client.yml` (if running as PHAR), `$HOME/.acme-client.yml`, `/etc/acme-client.yml` (if not on Windows).

The configuration file has the following format:

```yml
# Storage directory for certificates and keys.
storage: /etc/acme

# Server to use. URL to the ACME directory.
# "letsencrypt" and "letsencrypt:staging" are valid shortcuts.
server: letsencrypt

# E-mail to use for the setup.
# This e-mail will receive expiration notices from Let's Encrypt.
email: me@example.com

# List of certificates to issue.
certificates:
    # For each certificate, there are a few options.
    #
    # Required: paths
    # Optional: bits, user
    #
    # paths: Map of document roots to domains. Maps each path to one or multiple
    #        domains. If one domain is given, it's automatically converted to an
    #        array. The first domain will be the common name.
    #
    #        The client will place a file into $path/.well-known/acme-challenge/
    #        to verify ownership to the CA
    #
    # bits:  Number of bits for the domain private key
    #
    # user:  User running the web server. Challenge files are world readable,
    #        but some servers might require to be owner of files they serve.
    #
    # rekey: Regenerate certificate key pairs even if a key pair already exists.
    #
    - bits: 4096
      rekey: true
      paths:
        /var/www/example:
            - example.org
            - www.example.org
    # You can have multiple certificate with different users and key options.
    - user: www-data
      paths:
        /var/www: example.org
```

All configuration keys are optional and can be passed as arguments directly (except for `certificates` when using `acme-client auto`).

Before you can issue certificates, you must create an account using `acme-client setup --agree-terms`.

### Certificate Issuance

You can use `acme-client auto` to issue certificates and renew them if necessary. It uses the configuration file to
determine the certificates to request. It will store certificates in the configured storage in a sub directory called `./certs`.

If everything has been successful, you'll see a message for each issued certificate. If nothing has to be renewed,
the script will be quiet to be cron friendly. If an error occurs, the script will dump all available information.

You should execute `acme-client auto` as a daily cron. It's recommended to setup e-mail notifications for all output of
that script.

Create a new script, e.g. in `/usr/local/bin/acme-renew`. The `PATH` might need to be modified to suit your system.

```bash
#!/usr/bin/env bash

export PATH='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'

acme-client auto

RC=$?

if [ $RC = 4 ] || [ $RC = 5 ]; then
    service nginx reload
fi
```

```sh
# Cron Job Configuration
0 0 * * * /usr/local/bin/acme-renew
```

| Exit Code | Description |
|-----------|-------------|
| 0         | Nothing to do, all certificates still valid. |
| 1         | Config file invalid. |
| 2         | Issue during account setup. |
| 3         | Error during issuance. |
| 4         | Error during issuance, but some certificates could be renewed. |
| 5         | Everything fine, new certificates have been issued. |

Exit codes `4` and `5` usually need a server reload, to reload the new certificates. It's already handled in the recommended
cron setup.

If you want a more fine grained control or revoke certificates, you can have a look at the [advanced usage](./advanced-usage.md) document. The client allows to handle setup / issuance / revocation and other commands
separately from `acme-client auto`.

## Advanced Usage

Most users should use the `auto` command described above.

### Register an Account

```
acme-client setup --agree-terms --email me@example.com
```

After a successful registration you're able to issue certificates.
This client assumes you have a HTTP server setup and running.
You must have a document root setup in order to use this client.

### Issue a Certificate

```
acme-client issue -d example.com:www.example.com -p /var/www/example.com
```

You can separate multiple domains (`-d`) with `,`, `:` or `;`. You can separate multiple document roots (`-p`) with your system's path separator:
* Colon (`:`) for Unix
* Semicolon (`;`) for Windows

If you specify less paths than domains, the last one will be used for the remaining domains.

Please note that Let's Encrypt has rate limits. Currently it's five certificates per domain per seven days. If you combine multiple subdomains in a single certificate, they count as just one certificate. If you just want to test things out, you can use their staging server, which has way higher rate limits by appending `--server letsencrypt:staging`.

### Revoke a Certificate

To revoke a certificate, you need a valid account key, just like for issuance.

```
acme-client revoke --name example.com
```

`--name` is the common name of the certificate that you want to revoke.

### Renew a Certificate

For renewal, there's the `acme-client check` subcommand.
It exists with a non-zero exit code, if the certificate is going to expire soon.
Default check time is 30 days, but you can use `--ttl` to customize it.

You may use this as daily cron:

```
acme-client check --name example.com || acme-client issue ...
```

You can also use a more advanced script to automatically reload the server as well. For this example we assume you're using Nginx.
Something similar should work for Apache. But usually you shouldn't need any script, see [basic usage](./usage.md).

```bash
#!/usr/bin/env bash

acme-client check --name example.com --ttl 30

if [ $? -eq 1 ]; then
        acme-client issue -d example.com:www.example.com -p /var/www

        if [ $? -eq 0 ]; then
                nginx -t -q

                if [ $? -eq 0 ]; then
                        nginx -s reload
                fi
        fi
fi
```
