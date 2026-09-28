# MAX CA bundle

`max-api-ca-bundle.pem` contains Russian Trusted Root CA, ISRG Root X1 and ISRG Root X2.
SHA-256 fingerprints and validity are checked by `tests/SafetyTest.php`.
These are public certificates, not private keys.

The bundle is passed to cURL only for `platform-api2.max.ru`, never installed in the
system trust store and never used for storage uploads or custom API hosts.
An application may pass its replacement path through `Config::caBundle`.

MAX's [API documentation](https://dev.max.ru/docs-api) requires the Russian root for
this API host. ISRG root certificates are published by
[Let's Encrypt](https://letsencrypt.org/certificates/).

TLS checked on 2026-09-28 for `fu.oneme.ru` and `iu.oneme.ru`: both presented
`*.oneme.ru` issued by Let's Encrypt YR1 (valid through 2026-10-14). Verification
succeeded against the system trust store without this bundle. This checks current
TLS endpoints only; real upload URLs and media delivery still need runtime testing.
