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
