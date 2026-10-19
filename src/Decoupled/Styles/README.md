# Optional content style catalog

This integration installs no hooks until explicitly registered for a site:

```php
use CloakWP\Decoupled\Styles\ContentStyleCatalog;

ContentStyleCatalog::make()
  ->site('example')
  ->workerUrl('http://127.0.0.1:4919/rebuild')
  ->token($serverOnlySecret)
  ->publishing()
  ->register();
```

Configuration freezes after registration; repeated `register()` calls are safe.
Omit `publishing()` for a native-development candidate feed with ordinary ISR.
`postTypes([...])` optionally restricts inventory roots. Otherwise public post
types with blocks are used, excluding attachments. Published inventories expand
referenced patterns; the authenticated `include_unpublished=1` development feed
also includes drafts and unreferenced patterns.

The `X-Class-Catalog-Token` header authenticates:

- `GET /wp-json/cloakwp/class-catalog`: versioned inventory, site ID, content
  revision and upload metadata. A worker fetches this after notifications so block
  formatting runs in the same REST context as frontend content.
- With publishing enabled, `GET /wp-json/cloakwp/class-catalog/manifest?release=...`
  reads the manifest for this WordPress blog and frontend release.
- With publishing enabled, `POST /wp-json/cloakwp/class-catalog/manifest` commits
  a manifest after the worker has published and verified the immutable CSS file.
  Manifests contain version, site, release, SHA-256 hash, CSS URL, byte counts and
  `contentRevision`. An outdated revision returns 409 and leaves the pointer intact.

Manifests and revision markers are feature data in non-autoloaded WordPress
options, isolated by multisite blog. Secrets and storage configuration are never
stored there. Manifest responses carry `Cache-Control: no-store`; the frontend
may explicitly cache them server-side with its own invalidation tag.

On real saves/deletions, ACF and WordPress events coalesce into one shutdown
notification: `{version: 1, siteId, event: "content-changed"}`. Revisions and
autosaves are ignored. The worker serializes inventory/build/publication work.
Publishing disables the normal automatic save revalidation via
`cloakwp/decoupled/revalidate_on_save`; the worker must invalidate frontends after
publication. Explicit `CMS::getInstance()->revalidation()->revalidate(...)` remains
available for other integrations.

The worker URL is a single notification target. If a site serves several active
frontend releases/deployments, coordinate all required builds and invalidations
before enabling publishing. Normal on-save ISR is otherwise left to the existing
revalidation manager. This feature does not make WordPress content publication
itself transactional or implement a persistent job queue.
