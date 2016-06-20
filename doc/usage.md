# Basic Usage

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

## Configuration

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
    - bits: 4096
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

## Certificate Issuance

You can use `acme-client auto` to issue certificates and renew them if necessary. It uses the configuration file to
determine the certificates to request. It will store certificates in the configured storage in a sub directory called `./certs`.

If everything has been successful, you'll see a message for each issued certificate. If nothing has to be renewed,
the script will be quiet to be cron friendly. If an error occurs, the script will dump all available information.

You should execute `acme-client auto` as a daily cron. It's recommended to setup e-mail notifications for all output of
that script.

```bash
0 0 * * * acme-client auto; exit=$?; if [[ $exit = 4 ]] || [[ $exit = 5 ]]; then service nginx reload; fi
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
