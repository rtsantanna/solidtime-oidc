# solidtime-oidc

Generic **OIDC single sign-on** for [solidtime](https://github.com/solidtime-io/solidtime),
applied as a **minimal, rebasable patch** on top of the upstream `v0.21.0` release.

Works with any OpenID Connect provider. Validated against **Keycloak federating Microsoft
Entra ID**. Not Google-only, and not a full fork of an old base.

## Why this exists

Upstream solidtime has no SSO yet. The feature request
([discussion #238](https://github.com/solidtime-io/solidtime/discussions/238)) has been open
since October 2024, and PR
[#1131 "Add core support for extendable authentication"](https://github.com/solidtime-io/solidtime/pull/1131)
is still open. The maintainer has indicated SSO may land on-premise first and could become a
paid feature.

This repository exists so self-hosters can have OIDC **today**, with a patch small enough to
rebase onto each new upstream release — and to throw away entirely once upstream ships it.

## Credits

- **Upstream:** [solidtime-io/solidtime](https://github.com/solidtime-io/solidtime) — AGPL-3.0.
  All the actual product is theirs.
- **OIDC implementation:** adapted from
  [intersecato/solidtime](https://github.com/intersecato/solidtime), which built the original
  generic OIDC support. This repository extracts **only** that work and rebases it onto the
  current upstream release.

Licensed **AGPL-3.0**, same as upstream.

## What is included

Five new files plus small edits to eight existing ones:

| New | Purpose |
|---|---|
| `app/Service/Auth/OidcService.php` | The OIDC flow: authorization URL, callback, user resolution |
| `app/Http/Controllers/Auth/OidcController.php` | Two routes: redirect and callback |
| `app/Http/Middleware/PreventOidcProfilePhotoChanges.php` | Blocks local edits to OIDC-managed profile photos |
| `app/Extensions/Fortify/CustomLogoutResponse.php` | Federated (end-session) logout |
| `database/migrations/..._add_oidc_sub_to_users_table.php` | `oidc_sub`, nullable, UNIQUE |
| `tests/Feature/OidcAuthenticationTest.php` | Feature tests |

Edited: `Kernel.php`, `User.php`, `FortifyServiceProvider.php`, `routes/web.php`,
`config/auth.php`, `config/services.php`, `composer.json`, plus `Login.vue` and
`AuthenticationCard.vue`.

Dependency added: [`facile-it/php-openid-client`](https://github.com/facile-it/php-openid-client).

## Security properties

The flow implements `state` (compared with `hash_equals`), `nonce`, and **PKCE S256**. The `sub`
claim from the ID token is cross-checked against the userinfo response. Users are matched by
`oidc_sub` first and only then by e-mail, and an account already bound to a different `sub` is
refused rather than re-linked.

**Recommended hardening** (see configuration below):

- `OIDC_SYNC_PROFILE_PHOTO=false` — the profile-photo sync fetches the URL from the `picture`
  claim server-side, following redirects, with no scheme or address allowlist. On a container
  platform that is a blind SSRF vector. Disabling it removes the code path entirely.
- `OIDC_REQUIRE_VERIFIED_EMAIL=true` — without it, e-mail auto-linking can take over an existing
  account if your IdP does not guarantee verified addresses.
- `OIDC_AUTO_REGISTER=false` — otherwise anyone who can authenticate at your IdP gets an account,
  which silently overrides `APP_ENABLE_REGISTRATION`.

## Differences from the source fork

Only the OIDC work was taken. MySQL support, billing toggles, invoicing, daily activities,
custom login branding and the clients toggle were **deliberately left out**.

Four corrections were made while rebasing onto `v0.21.0`:

1. **Invitation matching.** The original queried `lower(name)` on `organization_invitations`.
   That column does not exist upstream and `email` is `NOT NULL`, so it would fail at runtime.
   Invitations are now matched by e-mail only.
2. **`canRegister`.** The original used `(bool) config('app.enable_registration')`. Upstream
   stores that as a string (`on` / `off` / `invite-only`), and `(bool) 'off'` is `true` in PHP —
   the register link would appear with registration disabled. Now uses upstream's
   `RegistrationMode` enum.
3. **`loginView` location.** The original registers it in `JetstreamServiceProvider`; upstream
   `v0.21.0` registers it in `FortifyServiceProvider`. Adding a second registration would
   silently shadow the first. The existing one is modified instead.
4. **Dead config removed.** Login-logo settings were dropped along with the custom logo
   component, which is branding rather than OIDC.

## Configuration

```dotenv
OIDC_ENABLED=true
OIDC_ISSUER=https://idp.example.com/realms/your-realm
OIDC_CLIENT_ID=solidtime
OIDC_CLIENT_SECRET=...
OIDC_REDIRECT_URI=https://timetracker.example.com/auth/oidc/callback
OIDC_SCOPES="openid profile email"

OIDC_REQUIRE_VERIFIED_EMAIL=true
OIDC_SYNC_PROFILE_PHOTO=false
OIDC_AUTO_REGISTER=false
OIDC_AUTO_LINK=true
OIDC_LOGOUT_ENABLED=true

# Set to false only after verifying SSO works - it locks out password login.
AUTH_PASSWORD_LOGIN_ENABLED=true
```

Register `<APP_URL>/auth/oidc/callback` as the redirect URI at your provider.

## Building

This candidate targets PHP 8.4.26 and FrankenPHP 1.12.7. Prepare production dependencies and assets before building, as in upstream's `build-public.yml`:

```bash
cp .env.production .env
composer install --no-dev --prefer-dist --classmap-authoritative
npm ci && npm run build
docker build -f docker/prod/Dockerfile --build-arg DOCKER_FILES_BASE_PATH=docker/prod/ .
```

The production `Dockerfile` expects an already-built tree — `composer install` and `npm run build`
are commented out inside it. Use Node.js 24 for the frontend build. The OIDC client requires
`brick/math` 0.12.3; all other packages already present in the upstream v0.21.0 lock keep their
upstream versions. This dependency exception needs validation whenever the patch is rebased.

> **Note on flaky builds:** upstream installs 20 PHP extensions in a single `RUN`. When
> `pecl.php.net` throttles, a random extension fails and the whole layer is rebuilt. Splitting
> that `RUN` into groups lets Docker cache the ones that succeeded. Not included here, since it
> is unrelated to OIDC.

## Rebasing onto a new upstream release

```bash
git remote add upstream https://github.com/solidtime-io/solidtime.git
git fetch upstream --tags
git rebase v0.X.Y
```

Re-check `FortifyServiceProvider` (where `loginView` lives), the `organization_invitations`
schema, and `RegistrationMode`.

**This patch has an expiry date.** When upstream merges #1131 and ships OIDC, drop it and move
the configuration over.
