# Changelog

## Unreleased

### Added
- Restore `/cloakwp/options` and `/cloakwp/options/{option_slug}` as aliases of `/cloakwp/globals` so ISR deploys still calling the pre-2.0 path keep receiving site-wide fields.
- Contract tests covering `/globals` vs `/options` payload parity, empty/unexposed error codes, nested ACF shapes, and the legacy `option_slug` parameter.
- GitHub Actions template at `ci/github-workflows/test.yml` (copy to `.github/workflows/`).

### Changed
- `?relative_images=1` now also rewrites ACF **file** field URLs (videos, PDFs, etc.) to path-only srcs, including nested Gutenberg-stored attachment arrays that skip `format_value`.

## 2.0.0

### Breaking
- `CloakWP\DecoupledCMS` → `CloakWP\Decoupled\CMS`
- `CloakWP\DecoupledFrontend` → `CloakWP\Decoupled\Frontend`
- `CMS` no longer extends Core and no longer auto-applies agency admin/Yoast opinions in the constructor.
- `enabledCoreBlocks()` → `allowedCoreBlocks()`
- Boot is delayed to `init` priority 20 so application code can finish registering resources first (not because ACF is required).
- JWT authentication and Eloquent menus are no longer required dependencies.
- A frontend is now required and configuration freezes after boot.
- Globals (site-wide fields) are denied until explicitly exposed via `exposeGlobals()` / `exposeAllGlobals()`. REST path is `/cloakwp/globals`.
- ACF is optional. Globals default to an empty repository; use `useGlobalsRepository(new AcfGlobalsRepository())` when reading ACF Options pages.
- Block previews and ISR requests use signed payloads instead of query secrets.

### Added
- Provider-based architecture under `CloakWP\Decoupled\Providers\`.
- Swappable contracts: `ImageFormatter`, `AuthProvider`, `MenuRepository`, `GlobalsRepository`, `FrontendResolver`.
- Native WordPress menu repository and invokable Core REST handlers.
- Batched ISR requests with verified responses and per-site WP-Cron retries.
- Provider replacement, removal, and ordering APIs.
- Unit tests for lifecycle, contexts, endpoint policies, preview tokens, ISR,
  multisite queues, CORS, and image global-state safety.
