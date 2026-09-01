# CloakWP Decoupled

CloakWP Decoupled supplies the WordPress-side services needed by a decoupled
frontend: REST resources, frontend URL mapping, previews, image formatting, and
on-demand cache revalidation.

It does **not** require Advanced Custom Fields or any other third-party plugin.
ACF is an optional integration: when it is installed, a few providers format
ACF values and an `AcfGlobalsRepository` can read ACF Options pages. When it is
absent, those paths no-op.

## Installation

```bash
composer require cloakwp/decoupled
```

The Composer package type is `wordpress-muplugin`. It's recommended to load it with `roots/bedrock-autoloader`. Other WordPress installations need an
MU-plugin loader that requires `decoupled/cloakwp-decoupled.php`.

The repository can also be installed as a traditional plugin under a folder
named `decoupled`.

## Basic configuration

Configure the CMS from a theme or application bootstrap:

```php
use CloakWP\Core\Enqueue\Stylesheet;
use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Frontend;

CMS::getInstance()
  ->frontends([
    Frontend::make('website', $frontendUrl)
      ->authSecret(CLOAKWP_AUTH_SECRET)
      ->deployments([$stagingUrl])
      ->revalidateEntriesOnSave(),
  ])
  ->assets([
    Stylesheet::make('editor-styles')
      ->hooks(['enqueue_block_assets'])
      ->adminOnly()
      ->src(get_theme_file_uri('/assets/css/editor.css')),
  ]);
```

At least one frontend is required. Configuration is collected before WordPress
`init`; the CMS boots at priority 20 so **application code** can finish
registering resources (content types, menus, REST routes, field groups, blocks)
first. Configuration is immutable after boot.

## Multisite

CMS instances are isolated by WordPress site ID. `CMS::getInstance()` resolves
the current site.

Long-running multisite jobs can configure another site before booting it:

```php
switch_to_blog($siteId);

$cms = CMS::forSite($siteId, scheduleBoot: false)
  ->frontends([
    Frontend::make('website', $frontendUrl),
  ]);

$cms->boot();
```

## Frontends

Each `Frontend` has a stable key and public URL. Optional configuration includes:

- `apiBasePath()` and `apiRouterBasePath()` for frontend API routing
- `apiRouteUrl()` when API traffic uses a different origin
- `deployments()` for additional cache-revalidation targets
- `authSecret()` for signed server-to-server requests
- `blockPreviewPath()` and `previewTokenTtl()` for editor previews
- `revalidationTimeout()` for outbound cache-revalidation requests
- `revalidateEntriesOnSave()` to revalidate an entry when it is saved

Retrieve frontends with `getActiveFrontend()`, `getFrontend($key)`, or
`getFrontends()`.

## REST resources

The package registers:

- `/wp-json/cloakwp/frontpage`
- `/wp-json/cloakwp/menus`
- `/wp-json/cloakwp/menus/{menu_slug}`
- `/wp-json/cloakwp/globals`
- `/wp-json/cloakwp/globals/{global_slug}`
- `/wp-json/cloakwp/auth/authorize`
- `/wp-json/cloakwp/auth/establish-session`
- `/wp-json/cloakwp/auth/establish-logout`
- `/wp-json/cloakwp/auth/logout`
- `/wp-json/cloakwp/auth/generate` (reserved; returns 501)

Menus use WordPress's native navigation APIs. Replace the implementation with
`useMenuRepository()` when a project needs another source.

## Globals

**Globals** are site-wide data that any page might need: company details,
default layout, social links, contact info, and similar. They are not a
WordPress `get_option()` dump, not per-entry post meta, and not ACF-specific (but often come from ACF Options pages for users of ACF).
They exist so the frontend can load one shared payload (often alongside menus)
instead of repeating that content on every document.

Nothing is public until you expose it:

```php
CMS::getInstance()->exposeGlobals([
  'company',
  'layout',
  'links',
]);

// Appropriate only when every global field is public content.
CMS::getInstance()->exposeAllGlobals();
```

The default globals repository is empty. Plug in a source with
`useGlobalsRepository()`. Projects that store this data in ACF Options pages
can use the bundled adapter:

```php
use CloakWP\Decoupled\Repositories\AcfGlobalsRepository;

CMS::getInstance()->useGlobalsRepository(new AcfGlobalsRepository());
```

## Authentication

CloakWP splits auth into two jobs:

1. **Machine REST** (drafts, writes): WordPress Application Passwords on the
   Next.js server. Send HTTP Basic from server-only env vars
   `WP_APPLICATION_USER` and `WP_APPLICATION_PASSWORD`. Never put these in a
   browser bundle. Create one with:

   ```bash
   wp user application-password create admin "CloakWP Next" --porcelain
   ```

2. **Editor sessions / AdminBar**: a Next.js BFF session (`cloakwp_at` /
   `cloakwp_rt` httpOnly cookies plus a paint-only `cloakwp_ui` hint) plus a
   one-time `establish-session` handshake that calls `wp_set_auth_cookie()` so
   `/wp-admin` recognizes the same login.

Session endpoints are secret-gated with `X-CloakWP-Secret`, using
`CLOAKWP_SESSION_SECRET` or falling back to `CLOAKWP_AUTH_SECRET` / the
frontend `authSecret()`. Password grants call `wp_authenticate()` so 2FA
plugins can hook `authenticate`. Refresh tokens are hashed, rotated on every
use, and revoked on CloakWP logout, WordPress `wp_logout` (including the
wp-admin Log Out link), and password change. WordPress logout redirects to
the frontend `/api/cloakwp/auth/logout` endpoint so the decoupled frontend session cookies are cleared
on the same trip. `wp_safe_redirect()` only allows the CMS host by default; CloakWP adds
each configured frontend host to `allowed_redirect_hosts` so that hop is not stripped.

`GET /cloakwp/auth/generate` and `LoginForm strategy="redirect"` are reserved
for a later SSO/2FA bounce through `wp-login.php`. They are not implemented.

### Legacy JWT machine auth

Deployed frontends may still send `Authorization: Bearer $WP_JWT` from the
optional `cloakwp/jwt-auth` package. Keep `JwtAuthProvider` selected until
every site has application-password env vars and a production redeploy.
CloakWP session access tokens are verified first; jwt-auth Bearers still work
afterward. Removing jwt-auth is a follow-up sweep, not this release.

```php
use CloakWP\Decoupled\Auth\JwtAuthProvider;

CMS::getInstance()->useAuth(
  new JwtAuthProvider(expirationSeconds: 3600),
);
```

Selecting `JwtAuthProvider` without `cloakwp/jwt-auth` installed throws an
actionable exception. Custom integrations can implement `AuthProvider` and pass
it to `useAuth()`. Preview/ISR HMAC (`CLOAKWP_AUTH_SECRET`) is unchanged.

## Image formatting

Registered attachments include each available size's URL, width, and height,
plus alt text, caption, and title where present.

When ACF is active, image and gallery field values are formatted with the same
pipeline. Unknown external image URLs are returned as URLs without fetching
them from the WordPress server. This avoids server-side requests to untrusted
hosts.

Replace the formatter with `useImageFormatter()`.

## Preview URLs

Define a shared signing secret:

```php
define('CLOAKWP_AUTH_SECRET', '…');
```

Post and block-preview URLs contain an HMAC-signed token rather than the raw
secret. Tokens carry a preview key, pathname, and expiry; the default lifetime
is 12 hours. Configure it with `Frontend::previewTokenTtl()`.

The frontend must verify the token, expiry, pathname, and preview key. Editor
iframe messaging should also validate the exact origin, source window, message
type, and preview key (CloakWP's JS packages solve all of this).

## Cache revalidation

Trigger revalidation explicitly:

```php
CMS::getInstance()
  ->getActiveFrontend()
  ->revalidatePaths([$entryId, '/portfolio', '/']);
```

The package sends one blocking JSON `POST` per unique deployment. Requests use:

- `X-CloakWP-Timestamp`: the current Unix timestamp
- `X-CloakWP-Signature`: HMAC-SHA256 of `<timestamp>.<raw-body>`
- `{ "paths": [...] }`: the request body

The frontend returns `{"ok":true,"results":[...]}` only when every path
revalidates. Failed requests are stored in a per-site WordPress option and
retried through WP-Cron with bounded exponential backoff.

The package does not infer behavior from an environment name. Applications can
pause outbound requests explicitly without blocking REST:

```php
CMS::getInstance()->pauseOutboundRevalidation();
```

Public REST lockdown is a separate opt-in:

```php
CMS::getInstance()->enableRestMaintenanceLockdown();
```

## Providers and replacements

`CMS` is a composition root. Behavior is divided into providers for configured
resources, REST endpoints, previews, image formatting, CORS, URL rewriting, and
revalidation.

ACF-only filters (image/gallery formatting, REST link rewriting, relational
post-query args, the virtual `acf` field on REST documents, Gutenberg block
preview scripts) attach only when the ACF plugin is active (`get_field` exists).

Use `withProviders()`, `replaceProvider()`, `removeProvider()`,
`moveProviderBefore()`, and `moveProviderAfter()` before boot to change the
provider collection.
