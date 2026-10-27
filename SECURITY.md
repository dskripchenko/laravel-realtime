# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 0.1.x   | yes       |

## Reporting a vulnerability

Please report vulnerabilities privately. Use GitHub's private vulnerability reporting:
**Security → Report a vulnerability** on
[github.com/dskripchenko/laravel-realtime](https://github.com/dskripchenko/laravel-realtime/security/advisories/new),
or write to denskrp90@gmail.com. Do not open a public issue for a vulnerability.
Include affected versions and, if possible, a proof of concept.

You will get an answer within five working days. A confirmed issue is fixed in a
patch release of every supported line, published as a GitHub security advisory once
the release is out, and credited in the changelog unless you ask otherwise.

## Security model in short

- A WebSocket connection is opened only with a one-time ticket (32 random bytes,
  stored in Redis as a SHA-256 hash, default TTL 30 s, consumed with `GETDEL`).
- The ticket fixes the user id, guard and scope (tenant) of the connection. Every
  channel is keyed by that scope, so a connection can never see another scope's
  events, whatever channel name it asks for.
- Private and presence channels are authorized by the host's callbacks inside the
  server process; public channels are open to every connection of the same scope.
- The server does not terminate TLS. Run it behind a reverse proxy that does, and keep
  the server port off the public network.

See [docs/en/authorization.md](docs/en/authorization.md) and
[docs/en/operations.md](docs/en/operations.md) for the details and hardening notes.
