# Production mailer configuration

`MAILER_DSN` from `.env.deploy` must point to a real SMTP provider in production.
Do not use `smtp://mailcatcher:1025` in production because `mailcatcher` is only available in local/dev compose override.

Example (SMTP over TLS):

```ini
MAILER_DSN=smtp://USERNAME:PASSWORD@smtp.example.com:587?encryption=tls&auth_mode=login
MAILER_FROM=no-reply@infratak.com
```

If SMTP transport is unavailable, registration now returns a user-facing error instead of HTTP 500.
