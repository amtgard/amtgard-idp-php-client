# Slim + Docker example

Reference [Slim 4](https://www.slimframework.com/) app exercising **every** public `IdpClient` method and Slim accelerator from `amtgard/idp-php-client`.

## Library coverage

| `IdpClient` method | Route | Auth |
|--------------------|-------|------|
| `beginAuthorization()` | `GET /login` | Session (`IdpAuthController`) |
| `completeLogin()` | `GET /oauth/callback` | Session (`IdpAuthController`) |
| `fetchUserProfile()` | `GET /resources/userinfo` | Bearer (logged-in session) |
| `validate()` | `GET /resources/validate` | Bearer |
| `fetchJwt()` | `GET /resources/jwt` | Bearer |
| `refresh()` | `POST /refresh` | Bearer |
| `checkAuthorization()` | `POST /api/check-authorization` | None (local `ork-iam` evaluation via example route) |
| `SessionAuthStore` | `GET /me`, `/logout` | Session |

`completeAuthorization()` is used internally by `completeLogin()` on callback.

## Quick start

From this directory:

```bash
cp .env.example .env
# Edit .env — register IDP_CLIENT_ID / IDP_CLIENT_SECRET with IDP maintainers
docker compose up --build -d
```

Open http://localhost:38080/ — the HTML dashboard lists every demo route with one-click request tracing. Use **Login** to start OAuth against **https://idp.amtgard.com** (default `IDP_BASE_URL`). Machine-readable coverage is at `GET /api/home`.

## Routes

| Route | Purpose |
|-------|---------|
| `GET /health` | Integration probe (no session) |
| `GET /` | HTML dashboard — auth status, endpoint buttons, request trace |
| `GET /api/home` | JSON home — auth status + `library_coverage` map |
| `GET /login` | Redirect to IDP authorize |
| `GET /oauth/callback` | OAuth callback (`completeLogin`) |
| `GET /logout` | Clear `SessionAuthStore` |
| `GET /me` | Cached session profile (from callback) |
| `GET /resources/userinfo` | Live `fetchUserProfile()` call |
| `GET /resources/validate` | `validate()` heartbeat |
| `GET /resources/jwt` | `fetchJwt()` |
| `POST /refresh` | `refresh()` + update session tokens |
| `POST /api/check-authorization` | `checkAuthorization()` — JSON body `{policy, requirement}` (both optional; see defaults below) |
| `GET /api/client-iam/service-format` | Client IAM service format (when configured) |
| `POST /api/client-iam/compose-claim` | Compose integrator claim ORN — JSON `{segments, resource}` (both optional; see defaults below) |

### Demo defaults

Authorization check and Client IAM compose-claim use shared defaults from `ExampleDefaults` (overridable in `.env`):

| Variable | Default | Used by |
|----------|---------|---------|
| `EXAMPLE_POLICY` | `["Idp:0:0:0:0:IDP/EditClient"]` | Authorization check policy |
| `EXAMPLE_POLICY_REQUIREMENT` | `Idp:0:0:0:0:IDP/EditClient` | Authorization check requirement |

Client IAM compose-claim defaults are derived from `GET /api/client-iam/service-format` (`compose_defaults` in the JSON response). The dashboard loads them on page open. An empty `segments` object in the POST body is treated as “use derived defaults” (zero-filled keys from `service_format`). Integrator clients with a custom `iam_service` get `Editor/Write` as the sample resource; built-in `Idp` clients get `IDP/EditClient`.

Optional offline overrides when the IDP returns `is_default` without `iam_service`:

| Variable | Example |
|----------|---------|
| `IDP_IAM_SERVICE` | `Idp` |
| `IDP_IAM_SERVICE_FORMAT` | `["Configuration","Game","Kingdom","Park"]` |

With the built-in authorization defaults, `POST /api/check-authorization` with `{}` returns `is_authorized: true`.

### Policy check example

```bash
curl -s -X POST http://localhost:38080/api/check-authorization \
  -H 'Content-Type: application/json' \
  -d '{}'
```

Explicit empty policy (deny):

```bash
curl -s -X POST http://localhost:38080/api/check-authorization \
  -H 'Content-Type: application/json' \
  -d '{"policy":[],"requirement":"Idp:0:0:0:0:IDP/EditClient"}'
```

## IDP pairing

Default `IDP_BASE_URL` is **https://idp.amtgard.com**. Register an OAuth client with the IDP maintainers whose redirect URI matches `IDP_REDIRECT_URI` exactly:

```
http://localhost:38080/oauth/callback
```

## Integration tests

From the **repository root**:

```bash
cp examples/slim-docker/.env.example examples/slim-docker/.env
docker compose -f examples/slim-docker/docker-compose.yml up --build -d

SLIM_INTEGRATION=1 composer test -- --testsuite Integration --filter SlimDocker
```

Or use the composer shortcut:

```bash
composer integration:slim
```

Teardown:

```bash
composer integration:slim:down
```

Live IDP checks use `https://idp.amtgard.com` unless `IDP_BASE_URL` is overridden.
