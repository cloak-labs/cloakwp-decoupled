<?php

declare(strict_types=1);

namespace CloakWP\Decoupled;

use CloakWP\BlockParser\BlockParser;
use CloakWP\Decoupled\Auth\NoAuthProvider;
use CloakWP\Decoupled\Blocks\BlockRegistry;
use CloakWP\Decoupled\Contracts\AuthProvider;
use CloakWP\Decoupled\Contracts\FrontendResolver;
use CloakWP\Decoupled\Contracts\GlobalsRepository;
use CloakWP\Decoupled\Contracts\ImageFormatter as ImageFormatterContract;
use CloakWP\Decoupled\Contracts\MenuRepository;
use CloakWP\Decoupled\Media\ImageFormatter;
use CloakWP\Decoupled\Providers\AcfPostFiltersProvider;
use CloakWP\Decoupled\Providers\BlockParserProvider;
use CloakWP\Decoupled\Providers\ConfiguredResourcesProvider;
use CloakWP\Decoupled\Providers\CorsProvider;
use CloakWP\Decoupled\Providers\DraftSlugsProvider;
use CloakWP\Decoupled\Providers\EditorAssetsProvider;
use CloakWP\Decoupled\Providers\FrontendLinksProvider;
use CloakWP\Decoupled\Providers\ImageFormattingProvider;
use CloakWP\Decoupled\Providers\MaintenanceProvider;
use CloakWP\Decoupled\Providers\PreviewProvider;
use CloakWP\Decoupled\Providers\RevalidationProvider;
use CloakWP\Decoupled\Providers\RestApiProvider;
use CloakWP\Decoupled\Providers\RestEndpointsProvider;
use CloakWP\Decoupled\Providers\ServiceProvider;
use CloakWP\Decoupled\Providers\SessionAuthProvider;
use CloakWP\Decoupled\Providers\VirtualFieldsProvider;
use CloakWP\Decoupled\Repositories\EmptyGlobalsRepository;
use CloakWP\Decoupled\Repositories\NativeMenuRepository;
use CloakWP\Decoupled\Repositories\WpSessionStore;
use CloakWP\Decoupled\Services\MaintenanceState;
use CloakWP\Decoupled\Services\GlobalsExposure;
use CloakWP\Decoupled\Services\PreviewToken;
use CloakWP\Decoupled\Services\PreviewUrlHandler;
use CloakWP\Decoupled\Services\RevalidationManager;
use CloakWP\Decoupled\Services\SessionManager;
use CloakWP\Decoupled\Services\SessionTokenCodec;
use CloakWP\Decoupled\Support\FrontendUrlTransformer;
use Inpsyde\WpContext;

/**
 * CloakWP Decoupled application root.
 *
 * Configure from a theme or plugin. Boot is delayed until `init` priority 20
 * so application code can finish registering resources first. Configuration
 * is immutable after boot.
 *
 * ```php
 * use CloakWP\Decoupled\CMS;
 * use CloakWP\Decoupled\Frontend;
 *
 * CMS::getInstance()
 *   ->frontends([
 *     Frontend::make('website', $url)->authSecret(...)->revalidateEntriesOnSave(),
 *   ])
 *   ->assets([$editorCss]);
 * ```
 */
final class CMS implements FrontendResolver
{
  /** @var array<int, self> */
  private static array $instances = [];

  private bool $booted = false;

  private bool $registered = false;

  private bool $bootScheduled = false;

  private FrontendRegistry $frontendRegistry;

  private BlockRegistry $blockRegistry;

  private ImageFormatterContract $imageFormatter;

  private AuthProvider $authProvider;

  private MenuRepository $menuRepository;

  private GlobalsRepository $globalsRepository;

  private ?BlockParser $blockParser = null;

  private FrontendUrlTransformer $frontendUrls;

  private MaintenanceState $maintenance;

  private GlobalsExposure $globalsExposure;

  private PreviewToken $previewToken;

  private PreviewUrlHandler $previewUrls;

  private RevalidationManager $revalidation;

  private SessionManager $session;

  /** @var list<\CloakWP\Core\Enqueue\Stylesheet|\CloakWP\Core\Enqueue\Script> */
  private array $assets = [];

  /** @var list<object> */
  private array $configuredBlocks = [];

  private array|bool|null $allowedCoreBlocks = null;

  /** @var list<ServiceProvider> */
  private array $providers = [];

  private function __construct(
    private readonly WpContext $context,
  )
  {
    $this->frontendRegistry = new FrontendRegistry();
    $this->blockRegistry = new BlockRegistry();
    $this->imageFormatter = new ImageFormatter();
    $this->authProvider = new NoAuthProvider();
    $this->menuRepository = new NativeMenuRepository();
    $this->globalsRepository = new EmptyGlobalsRepository();
    $this->frontendUrls = new FrontendUrlTransformer($this);
    $this->maintenance = new MaintenanceState();
    $this->globalsExposure = new GlobalsExposure();
    $this->previewToken = new PreviewToken();
    $this->previewUrls = new PreviewUrlHandler($this->previewToken);
    $this->revalidation = new RevalidationManager($this, $this->maintenance);
    $this->session = $this->createDefaultSession();
    $this->providers = $this->defaultProviders();
  }

  public static function getInstance(?WpContext $context = null): self
  {
    $siteId = function_exists('get_current_blog_id')
      ? (int) get_current_blog_id()
      : 1;

    return self::forSite($siteId, $context);
  }

  public static function forSite(
    int $siteId,
    ?WpContext $context = null,
    bool $scheduleBoot = true,
  ): self
  {
    if ($siteId < 1) {
      throw new \InvalidArgumentException('A positive WordPress site ID is required.');
    }

    if (!isset(self::$instances[$siteId])) {
      self::$instances[$siteId] = new self($context ?? WpContext::determine());
      if ($scheduleBoot) {
        self::$instances[$siteId]->scheduleBoot();
      }
    }

    return self::$instances[$siteId];
  }

  /**
   * @internal Reset singleton between tests.
   */
  public static function resetInstance(?int $siteId = null): void
  {
    if ($siteId === null) {
      self::$instances = [];
      return;
    }

    unset(self::$instances[$siteId]);
  }

  /**
   * @param list<Frontend> $frontends
   */
  public function frontends(array $frontends): static
  {
    $this->assertConfigurable();
    $this->frontendRegistry->set($frontends);

    return $this;
  }

  /**
   * @param list<\CloakWP\Core\Enqueue\Stylesheet|\CloakWP\Core\Enqueue\Script> $assets
   */
  public function assets(array $assets): static
  {
    $this->assertConfigurable();
    $this->assets = $assets;

    return $this;
  }

  /**
   * @param list<object> $blocks
   */
  public function blocks(array $blocks): static
  {
    $this->assertConfigurable();
    $this->configuredBlocks = $blocks;

    return $this;
  }

  public function allowedCoreBlocks(array|bool $blocks): static
  {
    $this->assertConfigurable();
    $this->allowedCoreBlocks = $blocks;

    return $this;
  }

  public function blockParser(BlockParser $blockParser): static
  {
    $this->assertConfigurable();
    $this->blockParser = $blockParser;

    return $this;
  }

  public function getBlockParser(): ?BlockParser
  {
    return $this->blockParser;
  }

  public function images(): ImageFormatterContract
  {
    return $this->imageFormatter;
  }

  public function useImageFormatter(ImageFormatterContract $formatter): static
  {
    $this->assertConfigurable();
    $this->imageFormatter = $formatter;

    return $this;
  }

  public function auth(): AuthProvider
  {
    return $this->authProvider;
  }

  public function useAuth(AuthProvider $auth): static
  {
    $this->assertConfigurable();
    $this->authProvider = $auth;

    return $this;
  }

  public function session(): SessionManager
  {
    return $this->session;
  }

  public function useSession(SessionManager $session): static
  {
    $this->assertConfigurable();
    $this->session = $session;

    return $this;
  }

  /**
   * Origins of configured frontends and their deployments, used for CORS and
   * session handshake redirect allowlisting.
   *
   * @return list<string>
   */
  public function frontendOrigins(): array
  {
    $origins = [];
    foreach ($this->getFrontends() as $frontend) {
      $origins[] = $this->originFromUrl($frontend->getUrl());
      $deployments = $frontend->getSettings('deployments');
      if (is_array($deployments)) {
        foreach ($deployments as $deployment) {
          if (is_string($deployment)) {
            $origins[] = $this->originFromUrl($deployment);
          }
        }
      }
    }

    return array_values(array_unique(array_filter($origins)));
  }

  public function wordpressOrigin(): string
  {
    return $this->originFromUrl(get_site_url());
  }

  public function useMenuRepository(MenuRepository $repository): static
  {
    $this->assertConfigurable();
    $this->menuRepository = $repository;

    return $this;
  }

  public function menuRepository(): MenuRepository
  {
    return $this->menuRepository;
  }

  public function useGlobalsRepository(GlobalsRepository $repository): static
  {
    $this->assertConfigurable();
    $this->globalsRepository = $repository;

    return $this;
  }

  public function globalsRepository(): GlobalsRepository
  {
    return $this->globalsRepository;
  }

  /**
   * Expose only the named site-wide fields through /cloakwp/globals.
   *
   * @param list<string> $names
   */
  public function exposeGlobals(array $names): static
  {
    $this->assertConfigurable();
    $this->globalsExposure->allow($names);

    return $this;
  }

  public function exposeAllGlobals(): static
  {
    $this->assertConfigurable();
    $this->globalsExposure->allowAll();

    return $this;
  }

  public function globalsExposure(): GlobalsExposure
  {
    return $this->globalsExposure;
  }

  public function pauseOutboundRevalidation(bool $paused = true): static
  {
    $this->assertConfigurable();
    $this->maintenance->pauseRevalidation($paused);

    return $this;
  }

  public function enableRestMaintenanceLockdown(bool $enabled = true): static
  {
    $this->assertConfigurable();
    $this->maintenance->lockRestApi($enabled);

    return $this;
  }

  public function maintenance(): MaintenanceState
  {
    return $this->maintenance;
  }

  public function previewToken(): PreviewToken
  {
    return $this->previewToken;
  }

  public function previewUrls(): PreviewUrlHandler
  {
    return $this->previewUrls;
  }

  public function revalidation(): RevalidationManager
  {
    return $this->revalidation;
  }

  public function context(): WpContext
  {
    return $this->context;
  }

  public function frontendUrls(): FrontendUrlTransformer
  {
    return $this->frontendUrls;
  }

  public function getActiveFrontend(): Frontend
  {
    return $this->frontendRegistry->active();
  }

  /** @return list<Frontend> */
  public function getFrontends(): array
  {
    return $this->frontendRegistry->all();
  }

  public function getFrontend(string $key): ?Frontend
  {
    return $this->frontendRegistry->get($key);
  }

  public function getBlocks(): array
  {
    return $this->blockRegistry->all();
  }

  /**
   * @internal Called by ConfiguredResourcesProvider during boot.
   */
  public function registerConfiguredBlocks(): void
  {
    $this->blockRegistry->register($this->configuredBlocks, $this);
  }

  public function convertToDecoupledUrl($permalink, $post): string
  {
    $decoupledFrontend = $this->getActiveFrontend();
    $decoupledPostUrl = $decoupledFrontend->getUrl();

    if ($permalink) {
      $decoupledPostUrl = str_replace(home_url(), $decoupledPostUrl, $permalink);
    } else {
      $decoupledPostUrl = $decoupledPostUrl ?: home_url();
    }

    return apply_filters('cloakwp/decoupled_post_link', $decoupledPostUrl, $permalink, $post);
  }

  public function handleSlugsForDrafts($post_id, $post, $update): void
  {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
      return;
    }
    if (!in_array($post->post_status, ['draft', 'pending'], true)) {
      return;
    }
    if (!empty($post->post_name)) {
      return;
    }

    $basis = $post->post_title ?: 'untitled';
    $slug = sanitize_title($basis);
    $slug = wp_unique_post_slug($slug, $post_id, $post->post_status, $post->post_type, $post->post_parent);

    remove_action('save_post', [$this, 'handleSlugsForDrafts'], 10);
    wp_update_post([
      'ID' => $post_id,
      'post_name' => $slug,
    ]);
    add_action('save_post', [$this, 'handleSlugsForDrafts'], 10, 3);
  }

  public static function renderBlockIframePreview($block, $content, $is_preview, $post_id, $wp_block, $context): void
  {
    include __DIR__ . '/block-preview.php';
  }

  /**
   * @param list<class-string<ServiceProvider>|ServiceProvider> $providers
   */
  public function withProviders(array $providers): static
  {
    $this->assertConfigurable();
    foreach ($providers as $provider) {
      $this->providers[] = $this->resolveProvider($provider);
    }

    return $this;
  }

  /**
   * @param class-string<ServiceProvider> $provider
   * @param class-string<ServiceProvider>|ServiceProvider $replacement
   */
  public function replaceProvider(string $provider, string|ServiceProvider $replacement): static
  {
    $this->assertConfigurable();
    $replacement = $this->resolveProvider($replacement);
    foreach ($this->providers as $index => $registeredProvider) {
      if ($registeredProvider instanceof $provider) {
        $this->providers[$index] = $replacement;
        return $this;
      }
    }

    throw new \InvalidArgumentException("Provider {$provider} is not registered.");
  }

  /**
   * @param class-string<ServiceProvider> $provider
   */
  public function removeProvider(string $provider): static
  {
    $this->assertConfigurable();
    $this->providers = array_values(array_filter(
      $this->providers,
      static fn(ServiceProvider $registeredProvider): bool => !$registeredProvider instanceof $provider,
    ));

    return $this;
  }

  /**
   * @param class-string<ServiceProvider> $provider
   * @param class-string<ServiceProvider> $before
   */
  public function moveProviderBefore(string $provider, string $before): static
  {
    return $this->moveProvider($provider, $before, false);
  }

  /**
   * @param class-string<ServiceProvider> $provider
   * @param class-string<ServiceProvider> $after
   */
  public function moveProviderAfter(string $provider, string $after): static
  {
    return $this->moveProvider($provider, $after, true);
  }

  /**
   * Compose provider services without attaching WordPress behavior.
   */
  public function register(): void
  {
    if ($this->registered) {
      return;
    }

    $this->frontendRegistry->validate();
    foreach ($this->frontendRegistry->all() as $frontend) {
      $frontend->bindServices($this->previewUrls, $this->revalidation);
    }

    foreach ($this->providers as $provider) {
      $provider->register($this);
    }

    $this->registered = true;
  }

  /**
   * Attach all WordPress hooks after theme configuration is complete.
   */
  public function boot(): void
  {
    if ($this->booted) {
      return;
    }

    $this->register();
    foreach ($this->frontendRegistry->all() as $frontend) {
      $frontend->freeze();
    }
    $this->booted = true;

    foreach ($this->providers as $provider) {
      $provider->boot($this);
    }
  }

  public function isBooted(): bool
  {
    return $this->booted;
  }

  public function isRegistered(): bool
  {
    return $this->registered;
  }

  /** @return list<\CloakWP\Core\Enqueue\Stylesheet|\CloakWP\Core\Enqueue\Script> */
  public function configuredAssets(): array
  {
    return $this->assets;
  }

  /** @return list<object> */
  public function configuredBlocks(): array
  {
    return $this->configuredBlocks;
  }

  public function configuredAllowedCoreBlocks(): array|bool|null
  {
    return $this->allowedCoreBlocks;
  }

  /** @return list<ServiceProvider> */
  public function providers(): array
  {
    return $this->providers;
  }

  private function scheduleBoot(): void
  {
    if ($this->bootScheduled) {
      return;
    }
    $this->bootScheduled = true;

    if (did_action('init')) {
      $this->boot();
      return;
    }

    add_action('init', [$this, 'boot'], 20);
  }

  /**
   * @return list<ServiceProvider>
   */
  private function defaultProviders(): array
  {
    return [
      new ConfiguredResourcesProvider(),
      new EditorAssetsProvider(),
      new RestEndpointsProvider(),
      new SessionAuthProvider(),
      new BlockParserProvider(),
      new VirtualFieldsProvider(),
      new CorsProvider(),
      new ImageFormattingProvider(),
      new AcfPostFiltersProvider(),
      new PreviewProvider(),
      new FrontendLinksProvider(),
      new DraftSlugsProvider(),
      new RestApiProvider(),
      new MaintenanceProvider(),
      new RevalidationProvider(),
    ];
  }

  /**
   * @param class-string<ServiceProvider>|ServiceProvider $provider
   */
  private function resolveProvider(string|ServiceProvider $provider): ServiceProvider
  {
    $resolved = is_string($provider) ? new $provider() : $provider;
    if (!$resolved instanceof ServiceProvider) {
      throw new \InvalidArgumentException('Providers must implement ' . ServiceProvider::class . '.');
    }

    return $resolved;
  }

  /**
   * @param class-string<ServiceProvider> $provider
   * @param class-string<ServiceProvider> $anchor
   */
  private function moveProvider(string $provider, string $anchor, bool $after): static
  {
    $this->assertConfigurable();
    $moving = null;
    $remaining = [];
    foreach ($this->providers as $registeredProvider) {
      if ($moving === null && $registeredProvider instanceof $provider) {
        $moving = $registeredProvider;
        continue;
      }
      $remaining[] = $registeredProvider;
    }

    if ($moving === null) {
      throw new \InvalidArgumentException("Provider {$provider} is not registered.");
    }

    foreach ($remaining as $index => $registeredProvider) {
      if ($registeredProvider instanceof $anchor) {
        array_splice($remaining, $index + ($after ? 1 : 0), 0, [$moving]);
        $this->providers = array_values($remaining);
        return $this;
      }
    }

    throw new \InvalidArgumentException("Provider {$anchor} is not registered.");
  }

  private function assertConfigurable(): void
  {
    if ($this->booted) {
      throw new \LogicException('CloakWP Decoupled configuration is frozen after boot.');
    }
  }

  private function createDefaultSession(): SessionManager
  {
    $secretResolver = fn(): string => $this->sessionSecret();

    return new SessionManager(
      new WpSessionStore(),
      new SessionTokenCodec($secretResolver),
      $secretResolver,
      fn(): array => $this->frontendOrigins(),
      fn(): string => $this->wordpressOrigin(),
    );
  }

  private function sessionSecret(): string
  {
    if (defined('CLOAKWP_SESSION_SECRET') && is_string(\CLOAKWP_SESSION_SECRET) && \CLOAKWP_SESSION_SECRET !== '') {
      return \CLOAKWP_SESSION_SECRET;
    }

    if (defined('CLOAKWP_AUTH_SECRET') && is_string(\CLOAKWP_AUTH_SECRET) && \CLOAKWP_AUTH_SECRET !== '') {
      return \CLOAKWP_AUTH_SECRET;
    }

    foreach ($this->frontendRegistry->all() as $frontend) {
      $secret = $frontend->getSettings('authSecret');
      if (is_string($secret) && $secret !== '') {
        return $secret;
      }
    }

    throw new \RuntimeException(
      'CLOAKWP_SESSION_SECRET or CLOAKWP_AUTH_SECRET must be defined before CloakWP can issue editor sessions.',
    );
  }

  public function originFromUrl(string $url): string
  {
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
      return '';
    }

    $origin = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
    if (isset($parts['port'])) {
      $origin .= ':' . $parts['port'];
    }

    return $origin;
  }
}
