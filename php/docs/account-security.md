# Passkeys and signup confirmation

## Passkeys

Install dependencies from `php/`:

```sh
composer install --no-dev --no-plugins --no-scripts
```

Enable PHP OpenSSL and Sodium. Set the gallery's permanent HTTPS address in
private `config.local.php`:

```php
'passkey_origin' => 'https://gallery.example.com',
```

Members can add a passkey under **Your account > Passkeys**, then use
**Sign in with a passkey** on the login page. Adding or removing one requires
the account password. Password sign-in remains available. Up to ten passkeys
can be registered per account.

Passkeys are tied to the hostname. Redirect alternate hostnames to the canonical
address before enabling them. Keep that address when moving servers. Local
testing works at `http://localhost:PORT`; use localhost rather than an IP address.
Docker installs the dependencies automatically.

The server stores credential IDs, public keys, signature counters and an opaque
account handle. Private keys and device biometrics stay with the authenticator.
Sign-in requires user verification, such as the device PIN or fingerprint.

## Signup email confirmation

First configure [email delivery](comment-notifications.md) and verify that mail
arrives. Then enable **Admin > Settings > Require email confirmation for new
accounts**.

New members must confirm their email before posting, uploading or downloading.
They can browse and sign in while waiting. Links expire after 24 hours and work
once. Your account offers a resend form, including a way to correct the address;
it requires the password and is rate limited. The notification worker delivers
confirmation emails using the existing schedule.

Existing accounts retain access. Turning off the setting stops requiring
confirmation for future signups; it does not verify pending accounts. Signup
confirmation does not subscribe anyone to comment notifications or provide
email password recovery.

## Tests

```sh
php tests/security.php
node tests/security-browser.mjs
```

The browser test uses Chromium's virtual authenticator to create and verify real
signatures. It checks rejected signatures, missing user verification, replay,
wrong origins, disabled accounts and email confirmation. Mail tests use an
injected transport; they send no email.
