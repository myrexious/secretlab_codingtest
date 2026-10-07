# 02 — Deployment

Secretlab Tech Exercise: a version-controlled key-value store.

This file covers deployment and CI/CD. The application design lives in
[`01-application.md`](./01-application.md).

Host-specific values — addresses, account names, credentials — are deliberately
not in this repository. They live in a git-ignored `LOCAL-host-notes.md` and in
GitHub repository secrets.

Target: `https://secretlab.kreio.tech`. Default branch `master`, to match the
PRD wording.

## 1. Shape of the deployment

```
internet ──► Traefik (on the host, ports 80/443, terminates TLS)
                │  routes by Docker label, over the traefik-public network
                ▼
           app container (FrankenPHP)  ──►  Redis container (internal only)
                │
                └─►  PostgreSQL, running natively on the host
```

Three things are worth noticing:

- **The app container publishes no ports.** Not even on loopback. Traefik
  reaches it over an internal Docker network, so nothing else can.
- **PostgreSQL is not in Docker.** It runs natively on the host, so the
  container has to cross a network boundary to reach it. See section 3.
- **Redis is its own container.** Two processes in one container break health
  reporting: PID 1 would be the application, so a dead Redis would still look
  healthy.

## 2. DNS — do this first

Traefik issues certificates over an HTTP-01 challenge, so the hostname must
resolve to the VPS **before** anything can be deployed or verified.

```bash
dig +short secretlab.kreio.tech
```

No answer means no certificate, and the whole deployment is blocked.

## 3. PostgreSQL across the Docker boundary

PostgreSQL is native; the app is containerised. A container's `localhost` is its
own loopback, so three changes are needed. Missing any one produces a refused
connection that looks like a firewall fault and is not.

1. Create a dedicated database and role (`kvstore` / `kvstore`), rather than
   reusing an existing one. This scopes the `pg_hba` rule below to one database.
2. `postgresql.conf`: `listen_addresses = 'localhost,172.17.0.1'`.
   **Restart** after this, not reload.
3. `pg_hba.conf`: `host kvstore kvstore 172.16.0.0/12 scram-sha-256`, then
   reload. That range is the Docker bridge subnet.
4. `compose.yml` already carries
   `extra_hosts: ["host.docker.internal:host-gateway"]`, so set
   `DB_HOST=host.docker.internal` in the production `.env`.

**Then verify the port is not public:**

```bash
sudo ss -lntp | grep 5432
```

The output must name `172.17.0.1` and `127.0.0.1`. It must never name
`0.0.0.0`. If `ufw` is active, no rule may allow inbound 5432.

## 4. Traefik routing

Traefik watches the Docker socket, so the container only needs labels. They are
already in `compose.yml`:

```yaml
traefik.enable: "true"
traefik.docker.network: "traefik-public"
traefik.http.routers.kv.rule: "Host(`secretlab.kreio.tech`)"
traefik.http.routers.kv.entrypoints: "websecure"
traefik.http.routers.kv.tls.certresolver: "letsencrypt"
traefik.http.services.kv.loadbalancer.server.port: "80"
```

Two traps:

- `exposedByDefault` is false on this Traefik, so `traefik.enable=true` is
  mandatory. Without it the container is simply never routed.
- **Do not add an HTTP-to-HTTPS redirect.** The `web` entrypoint already
  redirects globally. A second redirect creates a loop.

Verify the network exists before the first deploy, since Traefik names it
explicitly:

```bash
docker network ls | grep traefik-public
```

## 5. The deploy account

CI connects over SSH with a dedicated key that carries a **forced command**:

```
command="/srv/kv/deploy.sh",no-port-forwarding,no-agent-forwarding,no-X11-forwarding,no-pty ssh-ed25519 AAAA... kv-ci
```

That key can run one script. It cannot open a shell, forward a port, or read a
file.

This is not optional. A deploy account must be in the `docker` group to run
`docker compose`, and **membership in the `docker` group is equivalent to root**
— anyone who can run `docker` can mount `/` into a container and write anywhere
on the host. A forced command is what keeps a stolen CI key from becoming a host
compromise.

The key file is `~/.ssh/authorized_keys`, mode `600`, owned by the deploy user.

## 6. Files on the VPS

```
/srv/kv/
├── compose.yml      # from the repository
├── .env             # mode 600, owned by the deploy user, NEVER committed
└── deploy.sh        # the forced-command target, mode 750
```

[`deploy/deploy.sh`](../deploy/deploy.sh) in this repository is the script to
install at `/srv/kv/deploy.sh`. It pulls the image CI built, starts it, waits for
the container to report healthy, runs migrations, and caches config and routes.

The production `.env` is created once, by hand, on the VPS. CI never writes it.

## 7. CI/CD

GitHub Actions, on push to `master` — see
[`.github/workflows/ci.yml`](../.github/workflows/ci.yml):

1. Run Pest with coverage against a throwaway `postgres:16` service container.
   CI never touches the production database.
2. Check style with Pint.
3. Build the image and push it to GHCR, tagged `latest` and with the commit SHA.
4. SSH to the VPS. The forced command runs `deploy.sh`.
5. Smoke-test `https://secretlab.kreio.tech/health`.
6. Upload coverage to Codecov.

**The image that passed the tests is the image that deploys.** The VPS never
rebuilds, so it cannot drift from what was tested.

Repository secrets: `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`, and `CODECOV_TOKEN`.
The Codecov step skips itself while that token is absent, so a missing token
cannot turn the build red.

## 8. Before making the repository public

Git history becomes public along with the working tree. Check it first.

Never commit: the production `.env`, any SSH key, the VPS address, the Redis
password, or `LOCAL-host-notes.md`.

## Verification

Run in order. Each step must pass before the next.

| # | Check | Command |
|---|---|---|
| 1 | DNS resolves | `dig +short secretlab.kreio.tech` |
| 2 | PostgreSQL is not public | `sudo ss -lntp \| grep 5432` |
| 3 | Traefik network exists | `docker network ls \| grep traefik-public` |
| 4 | Tests pass | `docker compose -f compose.dev.yml exec app ./vendor/bin/pest` |
| 5 | Coverage builds | `docker compose -f compose.dev.yml exec app ./vendor/bin/pest --coverage` |
| 6 | Container is healthy | `docker compose ps` |
| 7 | TLS works | `curl -I https://secretlab.kreio.tech/health` |
| 8 | Swagger loads | open `https://secretlab.kreio.tech/docs` |

Then exercise the API end to end:

1. Store `mykey` as `{"a":1}`. Note the returned timestamp.
2. Read it back. Confirm the value is an object, not a string.
3. Store `mykey` again as `[1,2,3]`.
4. Read the latest. Confirm it is the array.
5. Read with the first timestamp. Confirm it is the object.
6. Read with a timestamp before the first write. Confirm 404.
7. Read `/kv-value/history/mykey`. Confirm two records with different timestamps.
8. Store a key named `get-all-keys`. Read it back. Confirm it works.
9. Write straight into the database, then read with `?direct=true` and without
   it. Confirm the bypass sees the new value and the cache does not.
10. Loop past the anonymous limit. Confirm 429 with `Retry-After`.
11. Push a commit to `master`. Confirm Actions deploys and the smoke test passes.
