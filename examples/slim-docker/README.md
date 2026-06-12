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

Open http://localhost:38080/ — JSON home lists every demo route. Click `/login` to start OAuth against **https://idp.amtgard.com** (default `IDP_BASE_URL`).

## Routes

| Route | Purpose |
|-------|---------|
| `GET /health` | Integration probe (no session) |
| `GET /` | Home — auth status + `library_coverage` map |
| `GET /login` | Redirect to IDP authorize |
| `GET /oauth/callback` | OAuth callback (`completeLogin`) |
| `GET /logout` | Clear `SessionAuthStore` |
| `GET /me` | Cached session profile (from callback) |
| `GET /resources/userinfo` | Live `fetchUserProfile()` call |
| `GET /resources/validate` | `validate()` heartbeat |
| `GET /resources/jwt` | `fetchJwt()` |
| `POST /refresh` | `refresh()` + update session tokens |
| `POST /api/check-authorization` | `checkAuthorization()` — JSON body `{policy, requirement}` |

### Policy check example

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
