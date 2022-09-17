## Advanced Usage

Please read the document about [basic usage](./usage.md) first.

## Register an Account

```
acme-client setup --agree-terms --email me@example.com
```

After a successful registration you're able to issue certificates.
This client assumes you have a HTTP server setup and running.
You must have a document root setup in order to use this client.

## Issue a Certificate

```
acme-client issue -d example.com:www.example.com -p /var/www/example.com
```

You can separate multiple domains (`-d`) with `,`, `:` or `;`. You can separate multiple document roots (`-p`) with your system's path separator:
 * Colon (`:`) for Unix
 * Semicolon (`;`) for Windows

If you specify less paths than domains, the last one will be used for the remaining domains.

Please note that Let's Encrypt has rate limits. Currently it's five certificates per domain per seven days. If you combine multiple subdomains in a single certificate, they count as just one certificate. If you just want to test things out, you can use their staging server, which has way higher rate limits by appending `--server letsencrypt:staging`.

## Revoke a Certificate

To revoke a certificate, you need a valid account key, just like for issuance.

```
acme-client revoke --name example.com
```

`--name` is the common name of the certificate that you want to revoke.

## Renew a Certificate

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
