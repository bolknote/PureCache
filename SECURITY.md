# Security Policy

## Supported Versions

PureCache targets **PHP 8.3+** (see `composer.json`). Security fixes are applied to the default branch (`main`).

## Reporting a Vulnerability

Please report security issues privately rather than opening a public GitHub issue.

1. Email the maintainers with a description of the issue, affected versions, and steps to reproduce.
2. Include proof-of-concept code only if necessary to demonstrate impact.
3. Allow reasonable time for a fix before public disclosure.

We will acknowledge receipt and aim to provide a remediation timeline.

## Threat Model Notes

PureCache implements cache client protocols in userland PHP. Treat cached data and connection configuration as security-sensitive:

- **Deserialization**: PHP, igbinary, and msgpack serializers can execute object instantiation. Use `OPT_ALLOW_SERIALIZED_CLASSES` only when the cache is fully trusted. Default behavior rejects object classes.
- **Encryption**: `setEncodingKey()` enables libmemcached-compatible or AEAD payload encryption. Keys must come from a secrets manager, not source code. AEAD is recommended for new deployments.
- **TLS**: Redis TLS options (`OPT_TLS_CA_FILE`, `OPT_TLS_PEER_NAME`) must validate server identity in production. Do not disable peer verification.
- **Network trust**: A compromised memcached, Redis, or Ignite endpoint can return malicious payloads; combine TLS, encryption, and safe serializers where the cache is not fully trusted.

## Recommendations

- Run cache servers on private networks.
- Enable AEAD encryption for values that leave a trusted enclave.
- Keep optional extensions (`openssl`, `zlib`, `zstd`, etc.) updated with the OS or container image.
- Pin client library versions and monitor releases for security advisories.
