<?php

declare(strict_types=1);

namespace ProcessWire;

use BackedEnum;
use Closure;
use Totoglu\Inertia\Contracts\SsrGatewayInterface;
use Totoglu\Inertia\Debug\InertiaTracyPanel;
use Totoglu\Inertia\PageResponse;
use Totoglu\Inertia\Props\AlwaysProp;
use Totoglu\Inertia\Props\DeferredProp;
use Totoglu\Inertia\Props\MergeProp;
use Totoglu\Inertia\Props\MergeStrategy;
use Totoglu\Inertia\Props\OnceProp;
use Totoglu\Inertia\Props\OptionalProp;
use Totoglu\Inertia\Props\ScrollProp;
use Totoglu\Inertia\Provider\FieldDataProvider;
use Totoglu\Inertia\Provider\ProviderRegistry;
use Totoglu\Inertia\Provider\TemplateDataProvider;
use Totoglu\Inertia\Provider\UserDataProvider;
use Totoglu\Inertia\Request;
use Totoglu\Inertia\Response;
use Totoglu\Inertia\Ssr\HttpGateway;
use Totoglu\Inertia\Ssr\SsrResponse;
use Totoglu\Inertia\Support\ErrorBag;
use Totoglu\Inertia\Support\PropResolver;
use Totoglu\Inertia\Support\TemplateMode;
use Totoglu\Inertia\Support\TypeGenerator;
use Throwable;
use Tracy\Debugger;
use UnitEnum;

/**
 * Server-side Inertia.js v3 protocol adapter for ProcessWire.
 *
 * Manages page object assembly, prop resolution, template targeting (`mode` / `enabledTemplates`),
 * asset versioning, redirect status codes, SSR gateway dispatch, and TypeScript/Page class generation.
 *
 * @property string       $mode                       Template activation mode (`'selected'` or `'all'`)
 * @property list<string> $enabledTemplates           Template names enabled when `$mode === 'selected'`
 * @property bool         $disableAppendTemplateFile  Set `$template->noAppendTemplateFile = true` on Inertia templates
 * @property bool         $disablePrependTemplateFile Set `$template->noPrependTemplateFile = true` on Inertia templates
 * @property string       $rootView                   Root View template filename (default: `_inertia.php`)
 * @property string       $rootElementId              DOM ID of the root mount container (default: `app`)
 * @property bool         $syncJsonToPost             Populate `$input->post` from `application/json` request bodies
 * @property bool         $autoShareFlash             Share ProcessWire `$notices` under `props.flash`
 * @property bool         $autoShareCsrf              Share CSRF token under `props.csrf` and emit `XSRF-TOKEN` cookie
 * @property bool         $encryptHistory             Encrypt browser history state by default
 * @property bool         $ssrEnabled                 Enable Server-Side Rendering via the SSR HTTP Gateway
 * @property string       $ssrUrl                     SSR server endpoint URL (default: `http://127.0.0.1:13714/render`)
 * @property float        $ssrTimeout                 SSR HTTP timeout in seconds (default: `1.5`)
 * @property bool         $tracySupport               Register the Inertia panel in TracyDebugger when `$config->debug` is true
 * @property bool         $autoGenerateTypes          Regenerate `inertia.d.ts` on module config or schema save
 * @property bool         $autoScaffoldPageClasses    Scaffold missing `site/classes/[PascalCase]Page.php` classes
 * @property string       $typeScriptOutputPath       Output path for `inertia.d.ts` (default: `assets/js/types/inertia.d.ts`)
 *
 * @method void registerProviders()
 * @method array<string, mixed> buildPageObject(PageResponse $pageResponse)
 * @method string renderPageResponse(PageResponse $pageResponse)
 */
class Inertia extends WireData implements Module, ConfigurableModule
{
	public const string SESSION_NAMESPACE = 'inertia';
	public const string SESSION_CLEAR_HISTORY_KEY = 'clear_history';
	public const string SESSION_PRESERVE_FRAGMENT_KEY = 'preserve_fragment';
	public const string SESSION_FLASH_KEY = 'flash';

	public Request $request;

	public Response $response;

	public ErrorBag $errors;

	public PropResolver $resolver;

	protected ProviderRegistry $providers;

	private bool $providersBooted = false;

	/** @var list<FieldDataProvider|TemplateDataProvider|UserDataProvider> */
	private array $earlyProviders = [];

	protected SsrGatewayInterface $ssrGateway;

	/** @var array<string, mixed> Shared props registered across the request */
	protected array $sharedProps = [];

	/** @var array<string, mixed> Shared root view data (`_inertia.php` variables) */
	protected array $sharedViewData = [];

	/** @var array<string, mixed> Custom flashed data for top-level `page.flash` */
	protected array $flashedData = [];

	/** @var string|Closure|null Custom asset version resolver */
	protected string|Closure|null $versionResolver = null;

	/** @var PageResponse|null Active PageResponse registered during template or hook execution */
	protected ?PageResponse $activePageResponse = null;

	/** @var PageResponse|null PageResponse that has already been rendered via renderPageResponse() in the current cycle */
	private ?PageResponse $renderedPageResponse = null;

	/** @var string|null Cached output from the last renderPageResponse() call in the current cycle */
	private ?string $renderedOutput = null;

	/** @var bool Force 303 See Other on the next `Session::redirect` call */
	private bool $forceNextRedirect303 = false;

	/** @var SsrResponse|null Cached SSR response for the current initial page render */
	protected ?SsrResponse $activeSsrResponse = null;

	private bool $ssrDispatchedForCurrentRender = false;

	/** @var list<string> URL/component patterns excluded from SSR via `withoutSsr()` */
	protected array $withoutSsrPatterns = [];

	private bool $withoutSsrGlobal = false;

	private bool $demoWarningEmitted = false;

	/** @var array<string, mixed>|null Last applied `setting('inertia')` array so fluent runtime overrides are preserved */
	private ?array $lastAppliedGlobalSettings = null;

	/** @var array<string, array{template: Template, noAppend: mixed, noPrepend: mixed}> Templates mutated during the request */
	private array $modifiedTemplates = [];

	/** @var array<string, mixed> Diagnostics for InertiaTracyPanel */
	protected array $tracyDebug = [];

	/**
	 * @return array<string, mixed>
	 */
	public static function getModuleInfo(): array
	{
		return [
			'title' => __('Inertia', __FILE__),
			'version' => 10,
			'summary' => __('Standalone Inertia.js v3 server-side protocol adapter for ProcessWire with Deferred/Merge/Once/Scroll props, Vite versioning, and fault-tolerant SSR.', __FILE__),
			'href' => 'https://github.com/trk/Inertia',
			'author' => 'Iskender TOTOGLU @trk @ukyo',
			'requires' => [
				'PHP>=8.4',
				'ProcessWire>=3.0.271',
				'Vite',
			],
			'installs' => [],
			'permissions' => [],
			'icon' => 'bolt',
			'autoload' => true,
			'singular' => true,
		];
	}

	public function __construct()
	{
		parent::__construct();

		$wire = $this->wire();
		$classLoader = $wire->classLoader;
		$classLoader->addNamespace('Totoglu\\Inertia', __DIR__ . '/src/');
		$classLoader->addNamespace('Totoglu\\Inertia\\Commands', __DIR__ . '/commands/');

		require_once __DIR__ . '/functions.php';

		$this->request = new Request();
		$this->response = new Response();
		$this->errors = new ErrorBag();
		$this->providers = new ProviderRegistry();
		$this->resolver = new PropResolver($this->providers);
		$this->ssrGateway = new HttpGateway();

		$this->applyConfigMap($this->getDefaultConfig());
	}

	/**
	 * Return the active `ProviderRegistry` instance, booting integrations and project providers if not yet booted.
	 */
	public function providers(): ProviderRegistry
	{
		if (!$this->providersBooted) {
			$this->bootProviders();
		}

		return $this->providers;
	}

	/**
	 * Register a custom `FieldDataProvider`, `TemplateDataProvider`, or `UserDataProvider` instance.
	 */
	public function registerProvider(
		FieldDataProvider|TemplateDataProvider|UserDataProvider $provider
	): self {
		if (!$this->providersBooted) {
			$this->earlyProviders[] = $provider;

			return $this;
		}

		$this->providers->register($provider);

		return $this;
	}

	/**
	 * Hookable lifecycle method invoked when `ProviderRegistry` boots.
	 *
	 * Hook `Inertia::registerProviders` to register custom `FieldDataProvider`,
	 * `TemplateDataProvider`, or `UserDataProvider` instances.
	 */
	public function ___registerProviders(): void
	{
	}

	/**
	 * Boot the 3-tier `ProviderRegistry` pipeline (built-in defaults -> module hooks -> `site/inertia/*Provider.php`).
	 */
	protected function bootProviders(): void
	{
		$this->providersBooted = true;
		$this->providers->resetToDefaults();
		foreach ($this->earlyProviders as $earlyProvider) {
			$this->providers->register($earlyProvider);
		}
		$this->registerProviders();

		$siteInertiaDir = rtrim((string) $this->wire()->config->paths->site, '/\\') . '/inertia';
		$this->providers->discoverFromDirectory($siteInertiaDir);
	}

	public function typeGenerator(): TypeGenerator
	{
		return new TypeGenerator($this);
	}

	/**
	 * Resolve the shared `Inertia` module instance from ProcessWire context.
	 */
	public static function instance(?Wire $context = null): self
	{
		$wire = $context !== null ? $context->wire() : wire();
		$existing = $wire->inertia;
		if ($existing instanceof self) {
			return $existing;
		}

		$modules = $wire->modules;
		if ($modules->isInstalled('Inertia')) {
			$mod = $modules->get('Inertia');
			if ($mod instanceof self) {
				return $mod;
			}
		}

		$fallback = new self();
		$wire->wire($fallback);
		$wire->wire('inertia', $fallback);

		return $fallback;
	}

	public function wired(): void
	{
		parent::wired();
		$this->wire($this->request);
		$this->wire($this->errors);
		$this->wire($this->providers);
		$this->wire($this->resolver);
		if ($this->ssrGateway instanceof Wire) {
			$this->wire($this->ssrGateway);
		}
		$this->wire('inertia', $this);
	}

	/**
	 * @return array{
	 *     mode: string,
	 *     enabledTemplates: list<string>,
	 *     disableAppendTemplateFile: bool,
	 *     disablePrependTemplateFile: bool,
	 *     rootView: string,
	 *     rootElementId: string,
	 *     syncJsonToPost: bool,
	 *     autoShareFlash: bool,
	 *     autoShareCsrf: bool,
	 *     encryptHistory: bool,
	 *     ssrEnabled: bool,
	 *     ssrUrl: string,
	 *     ssrTimeout: float,
	 *     tracySupport: bool,
	 *     autoGenerateTypes: bool,
	 *     autoScaffoldPageClasses: bool,
	 *     typeScriptOutputPath: string
	 * }
	 */
	public function getDefaultConfig(): array
	{
		return [
			'mode' => TemplateMode::Selected->value,
			'enabledTemplates' => [],
			'disableAppendTemplateFile' => true,
			'disablePrependTemplateFile' => false,
			'rootView' => '_inertia.php',
			'rootElementId' => 'app',
			'syncJsonToPost' => true,
			'autoShareFlash' => true,
			'autoShareCsrf' => true,
			'encryptHistory' => false,
			'ssrEnabled' => false,
			'ssrUrl' => 'http://127.0.0.1:13714/render',
			'ssrTimeout' => 1.5,
			'tracySupport' => true,
			'autoGenerateTypes' => true,
			'autoScaffoldPageClasses' => true,
			'typeScriptOutputPath' => 'assets/js/types/inertia.d.ts',
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function setConfigData(array $data): void
	{
		$this->applyConfigMap(array_merge($this->getDefaultConfig(), $data));
	}

	public function init(): void
	{
		$this->applyGlobalSettingOverrides();

		$wire = $this->wire();
		$config = $wire->config;
		$config->inertia = $this->request->isInertia();

		if ($this->syncJsonToPost) {
			$this->request->syncJsonToPost();
		}

		$wire->addHookAfter('Modules::saveConfig', $this, 'hookAfterSaveModuleConfig');
		$wire->addHookAfter('Pages::saved', $this, 'hookAfterPageSavedOrDeleted');
		$wire->addHookAfter('Pages::deleted', $this, 'hookAfterPageSavedOrDeleted');
		$wire->addHookAfter('Pages::trashed', $this, 'hookAfterPageSavedOrDeleted');
		if ((bool) $config->debug && (bool) $this->autoGenerateTypes) {
			$wire->addHookAfter('Templates::saved', $this, 'hookAfterSchemaSaved');
			$wire->addHookAfter('Fields::saved', $this, 'hookAfterSchemaSaved');
		}
	}

	public function ready(): void
	{
		$this->applyGlobalSettingOverrides();

		$wire = $this->wire();
		/** @var Page|null $page */
		$page = $wire->page;

		if ($this->inAdmin() && !$this->shouldHandleAdminPage($page)) {
			return;
		}

		if ($this->autoShareCsrf) {
			$this->emitXsrfCookie();
		}

		if ($this->isTracySupportEnabled()) {
			$this->registerTracyPanel();
		}

		// Configure native ProcessWire template append/prepend settings for the resolved $page
		if ($page instanceof Page && ($this->shouldHandlePage($page) || $this->shouldHandleAdminPage($page))) {
			if ($this->shouldHandlePage($page)) {
				$this->configurePageTemplateFiles($page);
			}
			$this->enforceDemoModeGuard();
		}

		// Register lifecycle hooks
		$wire->addHookBefore('Session::redirect', $this, 'hookSessionRedirect');
		$wire->addHookAfter('Session::logout', $this, 'hookSessionLogout');
		$wire->addHookBefore('Page::render', $this, 'hookBeforePageRender', ['priority' => 1]);
		$wire->addHookBefore('TemplateFile::render', $this, 'hookBeforeTemplateFileRender');
		$wire->addHookAfter('TemplateFile::render', $this, 'hookAfterTemplateFileRender');
		$wire->addHookAfter('Page::render', $this, 'hookAfterPageRender', ['priority' => 99]);
	}

	/**
	 * Enable Inertia for specific templates (`mode = 'selected'`).
	 *
	 * @param string|list<string> $templates
	 */
	public function forTemplates(string|array $templates, bool $append = true): self
	{
		$incoming = $this->normalizeTemplateList($templates);
		$existing = $append ? $this->normalizeTemplateList($this->enabledTemplates) : [];

		$this->set('mode', TemplateMode::Selected->value);
		$this->set('enabledTemplates', array_values(array_unique(array_merge($existing, $incoming))));
		$this->syncCurrentPageTemplateFlags();

		return $this;
	}

	/**
	 * Enable Inertia across all non-admin frontend templates.
	 */
	public function forAllTemplates(bool $enabled = true): self
	{
		$this->set('mode', $enabled ? TemplateMode::All->value : TemplateMode::Selected->value);
		$this->syncCurrentPageTemplateFlags();

		return $this;
	}

	protected function syncCurrentPageTemplateFlags(): void
	{
		/** @var Page|null $page */
		$page = $this->wire()->page;
		if ($page instanceof Page && $page->id > 0 && $this->shouldHandlePage($page)) {
			$this->configurePageTemplateFiles($page);
		}
	}

	/**
	 * Determine whether the given ProcessWire `Page` or template name is configured to use Inertia.
	 */
	public function isTemplateEnabled(string|Template $template): bool
	{
		$this->applyGlobalSettingOverrides();

		$name = $template instanceof Template ? (string) $template->name : trim($template);
		if ($name === '' || $name === 'admin') {
			return false;
		}

		if ((string) $this->mode === TemplateMode::All->value) {
			return true;
		}

		$enabled = $this->normalizeTemplateList($this->enabledTemplates);

		return in_array($name, $enabled, true);
	}

	/**
	 * Determine whether Inertia is configured to handle the given `Page`.
	 */
	public function shouldHandlePage(Page $page): bool
	{
		if ($page->id === 0 || !$page->template instanceof Template) {
			return false;
		}

		return $this->isTemplateEnabled($page->template);
	}

	/**
	 * Determine whether the given `Process` module instance or class name uses `InteractWithInertia`
	 * (exposes `inertiaRender()`), inspecting only site modules or already-loaded classes without
	 * instantiating non-Inertia core admin Process modules.
	 */
	public function isProcessInertiaEnabled(Process|string|null $process): bool
	{
		if ($process instanceof Process) {
			return method_exists($process, 'inertiaRender');
		}

		$processName = is_string($process) ? trim($process) : '';
		if ($processName === '' || $processName === 'ProcessPageView') {
			return false;
		}

		$namespacedClass = "ProcessWire\\{$processName}";
		if (class_exists($namespacedClass, false)) {
			return method_exists($namespacedClass, 'inertiaRender');
		}
		if (class_exists($processName, false)) {
			return method_exists($processName, 'inertiaRender');
		}

		$wire = $this->wire();
		$modules = $wire->modules;
		$siteModulesPath = rtrim((string) $wire->config->paths->siteModules, '/\\');
		$moduleFile = (string) $modules->getModuleFile($processName);
		if (
			$moduleFile === ''
			|| ($siteModulesPath !== '' && !str_starts_with($moduleFile, $siteModulesPath . '/'))
			|| !is_file($moduleFile)
		) {
			return false;
		}

		require_once $moduleFile;

		if (class_exists($namespacedClass, false)) {
			return method_exists($namespacedClass, 'inertiaRender');
		}
		if (class_exists($processName, false)) {
			return method_exists($processName, 'inertiaRender');
		}

		return false;
	}

	/**
	 * Determine whether the given Admin `Page` (`template=admin`) is assigned to a `Process` module
	 * that uses `InteractWithInertia`.
	 */
	public function shouldHandleAdminPage(?Page $page): bool
	{
		if (!$page instanceof Page || $page->id === 0 || !$page->template instanceof Template || $page->template->name !== 'admin') {
			return false;
		}

		$rawProcess = $page->getUnformatted('process') ?: $page->process;
		if ($rawProcess instanceof Process) {
			return $this->isProcessInertiaEnabled($rawProcess);
		}

		return is_scalar($rawProcess) && $this->isProcessInertiaEnabled((string) $rawProcess);
	}

	/**
	 * Apply ProcessWire's native `$template->noAppendTemplateFile` / `$template->noPrependTemplateFile`
	 * flags on the active page's template when Inertia handles the page.
	 */
	protected function configurePageTemplateFiles(Page $page): void
	{
		$template = $page->template;
		if (!$template instanceof Template || $template->name === 'admin') {
			return;
		}

		$name = (string) $template->name;
		if (!array_key_exists($name, $this->modifiedTemplates)) {
			$this->modifiedTemplates[$name] = [
				'template' => $template,
				'noAppend' => $template->get('noAppendTemplateFile'),
				'noPrepend' => $template->get('noPrependTemplateFile'),
			];
		}

		if ((bool) $this->disableAppendTemplateFile) {
			$template->noAppendTemplateFile = true;
		}

		if ((bool) $this->disablePrependTemplateFile) {
			$template->noPrependTemplateFile = true;
		}
	}

	/**
	 * Create and register a `PageResponse` for the given frontend component.
	 *
	 * @param array<string, mixed> $props
	 */
	public function render(string|UnitEnum $component, array $props = []): PageResponse
	{
		if ($component instanceof BackedEnum) {
			$componentName = (string) $component->value;
		} elseif ($component instanceof UnitEnum) {
			$componentName = $component->name;
		} else {
			$componentName = $component;
		}

		$pageResponse = new PageResponse($this, $componentName, $props);
		$this->activePageResponse = $pageResponse;
		$this->renderedPageResponse = null;
		$this->renderedOutput = null;
		$this->activeSsrResponse = null;
		$this->ssrDispatchedForCurrentRender = false;

		/** @var Page|null $page */
		$page = $this->wire()->page;
		if ($page instanceof Page && $page->id > 0) {
			$this->configurePageTemplateFiles($page);

			// If called inside a template file that already had appendFilename queued before render(),
			// invoke ProcessWire's native TemplateFile::halt() so _main.php is skipped after the template file returns.
			if ((bool) $this->disableAppendTemplateFile) {
				$output = $page->output();
				if ($output instanceof TemplateFile) {
					$output->halt(true);
				}
			}
		}

		return $pageResponse;
	}

	public function getActiveResponse(): ?PageResponse
	{
		return $this->activePageResponse;
	}

	/**
	 * Reset request state, shared props, session flags, and template overrides (primarily for testing).
	 */
	public function resetState(): self
	{
		$this->activePageResponse = null;
		$this->renderedPageResponse = null;
		$this->renderedOutput = null;
		$this->forceNextRedirect303 = false;
		$this->activeSsrResponse = null;
		$this->ssrDispatchedForCurrentRender = false;
		$this->withoutSsrPatterns = [];
		$this->withoutSsrGlobal = false;
		$this->demoWarningEmitted = false;
		$this->lastAppliedGlobalSettings = null;
		$this->sharedProps = [];
		$this->sharedViewData = [];
		$this->flashedData = [];
		$this->consumeSessionFlash();
		$this->writeSessionFlag(self::SESSION_CLEAR_HISTORY_KEY, false);
		$this->writeSessionFlag(self::SESSION_PRESERVE_FRAGMENT_KEY, false);
		$this->versionResolver = null;
		$this->set('ssrEnabled', false);
		$this->ssrGateway = new HttpGateway();
		$this->wire($this->ssrGateway);
		$this->syncSsrGatewayConfig();
		$this->request->resetFake();
		$this->response->reset();
		$this->errors->clear();
		$this->resolver->clearTransformers();
		$this->earlyProviders = [];
		$this->providersBooted = false;
		$this->providers->resetToDefaults();
		foreach ($this->modifiedTemplates as $entry) {
			$entry['template']->set('noAppendTemplateFile', $entry['noAppend']);
			$entry['template']->set('noPrependTemplateFile', $entry['noPrepend']);
		}
		$this->modifiedTemplates = [];

		return $this;
	}

	/**
	 * Share data across all Inertia responses in the current request.
	 *
	 * @param string|array<string, mixed> $key
	 */
	public function share(string|array $key, mixed $value = null): self
	{
		$this->sharedProps = array_merge($this->sharedProps, is_array($key) ? $key : [$key => $value]);

		return $this;
	}

	/**
	 * @param string|array<string, mixed> $key
	 */
	public function shared(string|array $key, mixed $value = null): self
	{
		return $this->share($key, $value);
	}

	public function getShared(?string $key = null, mixed $default = null): mixed
	{
		if ($key === null) {
			return $this->sharedProps;
		}

		return $this->sharedProps[$key] ?? $default;
	}

	public function flushShared(): self
	{
		$this->sharedProps = [];

		return $this;
	}

	/**
	 * Share server-only PHP variables with the Root View (`_inertia.php`).
	 *
	 * @param string|array<string, mixed> $key
	 */
	public function viewData(string|array $key, mixed $value = null): self
	{
		$this->sharedViewData = array_merge($this->sharedViewData, is_array($key) ? $key : [$key => $value]);

		return $this;
	}

	/**
	 * @param string|array<string, mixed> $key
	 */
	public function withViewData(string|array $key, mixed $value = null): self
	{
		return $this->viewData($key, $value);
	}

	public function getViewData(?string $key = null, mixed $default = null): mixed
	{
		if ($key === null) {
			return $this->sharedViewData;
		}

		return $this->sharedViewData[$key] ?? $default;
	}

	public function flushViewData(): self
	{
		$this->sharedViewData = [];

		return $this;
	}

	/**
	 * Create an `OptionalProp` evaluated only when explicitly requested via `X-Inertia-Partial-Data`.
	 */
	public function optional(callable $callback): OptionalProp
	{
		return new OptionalProp($callback);
	}

	/**
	 * Create a `DeferredProp` loaded asynchronously by the client after initial page render.
	 */
	public function defer(callable $callback, string $group = 'default'): DeferredProp
	{
		return new DeferredProp($callback, $group);
	}

	/**
	 * Create a `MergeProp` (`append` strategy) for client-side state merging.
	 */
	public function merge(mixed $value): MergeProp
	{
		return new MergeProp($value, MergeStrategy::Append);
	}

	/**
	 * Create a `MergeProp` with `prepend` strategy.
	 */
	public function prepend(mixed $value): MergeProp
	{
		return new MergeProp($value, MergeStrategy::Prepend);
	}

	/**
	 * Create a `MergeProp` with `deepMerge` strategy.
	 */
	public function deepMerge(mixed $value): MergeProp
	{
		return new MergeProp($value, MergeStrategy::Deep);
	}

	/**
	 * Create a `OnceProp` resolved once and cached across client navigations.
	 */
	public function once(callable $callback): OnceProp
	{
		return new OnceProp($callback);
	}

	/**
	 * Register a shared `OnceProp` and return it for fluent `->as()`, `->until()`, or `->fresh()` configuration.
	 */
	public function shareOnce(string $key, callable $callback): OnceProp
	{
		$prop = $this->once($callback);
		$this->share($key, $prop);

		return $prop;
	}

	/**
	 * Create a `ScrollProp` for `<InfiniteScroll>` pagination (supports ProcessWire `PaginatedArray` / `PageArray`).
	 */
	public function scroll(mixed $value, string $wrapper = 'data', string $pageName = 'page'): ScrollProp
	{
		return new ScrollProp($value, $wrapper, $pageName);
	}

	/**
	 * Create an `AlwaysProp` that is always evaluated and included even during partial reloads.
	 */
	public function always(mixed $value): AlwaysProp
	{
		return new AlwaysProp($value);
	}

	/**
	 * Register a custom class-targeted object transformer with the prop resolver.
	 *
	 * @param class-string $className
	 */
	public function addTransformer(string $className, callable $callback): self
	{
		$this->resolver->addTransformer($className, $callback);

		return $this;
	}

	/**
	 * Set a custom asset version string or Closure resolver.
	 */
	public function version(string|Closure|null $version): self
	{
		$this->versionResolver = $version;

		return $this;
	}

	/**
	 * Resolve the current asset version string.
	 * Defaults to an `md5_file` hash of the active Vite `manifest.json` when available.
	 */
	public function getVersion(): string
	{
		if ($this->versionResolver !== null) {
			$resolved = $this->versionResolver instanceof Closure
				? ($this->versionResolver)()
				: $this->versionResolver;

			return is_scalar($resolved) ? (string) $resolved : '';
		}

		return $this->resolveViteManifestVersion();
	}

	public function setRootView(string $view): self
	{
		$this->set('rootView', trim($view));

		return $this;
	}

	public function encryptHistory(bool $encrypt = true): self
	{
		$this->set('encryptHistory', $encrypt);

		return $this;
	}

	/**
	 * Instruct the client router to clear encrypted history state on the current or next response.
	 */
	public function clearHistory(bool $clear = true): self
	{
		if ($this->activePageResponse !== null) {
			$this->activePageResponse->clearHistory($clear);
		}

		$this->writeSessionFlag(self::SESSION_CLEAR_HISTORY_KEY, $clear);

		return $this;
	}

	/**
	 * Instruct the client router to preserve the URL hash fragment across a redirect.
	 */
	public function preserveFragment(bool $preserve = true): self
	{
		if ($this->activePageResponse !== null) {
			$this->activePageResponse->preserveFragment($preserve);
		}

		$this->writeSessionFlag(self::SESSION_PRESERVE_FRAGMENT_KEY, $preserve);

		return $this;
	}

	/**
	 * Disable SSR globally for the current request or for matching URL/component wildcard patterns.
	 *
	 * @param bool|string|list<string> $patterns
	 */
	public function withoutSsr(bool|string|array $patterns = true): self
	{
		if (is_bool($patterns)) {
			$this->withoutSsrGlobal = $patterns;

			return $this;
		}

		$list = is_array($patterns) ? $patterns : [$patterns];
		foreach ($list as $pattern) {
			$trimmed = trim((string) $pattern);
			if ($trimmed !== '' && !in_array($trimmed, $this->withoutSsrPatterns, true)) {
				$this->withoutSsrPatterns[] = $trimmed;
			}
		}

		return $this;
	}

	/**
	 * Flash validation errors into the session `ErrorBag` so they are exposed under `props.errors`.
	 *
	 * @param array<string, mixed>|InputfieldWrapper $errors
	 */
	public function withErrors(array|InputfieldWrapper $errors, ?string $bag = null): self
	{
		$effectiveBag = $bag ?? $this->request->errorBag();
		$this->errors->flash($errors, $effectiveBag);

		return $this;
	}

	/**
	 * Flash custom key-value data into top-level `page.flash` (persisted in session across redirects).
	 *
	 * @param string|array<string, mixed> $key
	 */
	public function flash(string|array $key, mixed $value = null): self
	{
		$incoming = is_array($key) ? $key : [$key => $value];
		$this->flashedData = array_merge($this->flashedData, $incoming);
		$this->writeSessionFlash($incoming);

		return $this;
	}

	/**
	 * Trigger an internal SPA redirect (`409 Conflict` + `X-Inertia-Redirect` on XHR visits, or `303 See Other` on normal visits).
	 */
	public function redirect(string $url, bool $terminate = false): Response
	{
		if ($this->request->isInertia()) {
			$this->response->conflictRedirect($url);
			if ($terminate) {
				exit;
			}

			return $this->response;
		}

		return $this->prepareSeeOtherRedirect($url, $terminate);
	}

	/**
	 * Trigger an external / full-page redirect (`409 Conflict` + `X-Inertia-Location` when called during an XHR Inertia visit,
	 * or standard `302` redirect on normal visits).
	 */
	public function location(string $url, bool $terminate = false): Response
	{
		if ($this->request->isInertia()) {
			$this->response->conflictLocation($url);
			if ($terminate) {
				exit;
			}

			return $this->response;
		}

		if ($terminate) {
			$this->wire()->session->redirect($url, false);
		}

		$this->response->setStatusCode(302)->setHeader('Location', $url);

		return $this->response;
	}

	/**
	 * Redirect back to the referring page (or `$fallbackUrl`) with `303 See Other` semantics.
	 * Validates that `Referer` belongs to a trusted local path or configured `httpHost`/`httpHosts` to prevent Open Redirects.
	 */
	public function back(string $fallbackUrl = '/', bool $terminate = false): Response
	{
		$referer = trim((string) ($this->request->header('Referer') ?? ($_SERVER['HTTP_REFERER'] ?? '')));
		$target = ($referer !== '' && $this->isSafeBackTarget($referer)) ? $referer : $fallbackUrl;

		return $this->prepareSeeOtherRedirect($target, $terminate);
	}

	private function prepareSeeOtherRedirect(string $url, bool $terminate): Response
	{
		$this->response->setStatusCode(303)->setHeader('Location', $url);

		if ($terminate) {
			$this->forceNextRedirect303 = true;
			$this->wire()->session->redirect($url, 303);
		}

		return $this->response;
	}

	/**
	 * Ensure a `Referer` URL is either a relative path (`/path`) or points to a trusted host (`$config->httpHost` / `$config->httpHosts`).
	 */
	protected function isSafeBackTarget(string $url): bool
	{
		if ($url === '' || preg_match('/[\x00-\x20]/', $url) === 1) {
			return false;
		}

		if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_starts_with($url, '/\\')) {
			return true;
		}

		$parts = parse_url($url);
		if (!is_array($parts)) {
			return false;
		}

		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		if ($scheme !== 'http' && $scheme !== 'https') {
			return false;
		}

		$host = strtolower(trim((string) ($parts['host'] ?? '')));
		if ($host === '') {
			return false;
		}

		$port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
		$hostWithPort = $host . $port;

		$config = $this->wire()->config;
		$allowedHosts = ['localhost', '127.0.0.1'];
		$candidateHosts = [$config->httpHost];
		if (is_iterable($config->httpHosts)) {
			foreach ($config->httpHosts as $configuredHost) {
				$candidateHosts[] = $configuredHost;
			}
		}

		$hasConfiguredHosts = false;
		foreach ($candidateHosts as $candidateHost) {
			$clean = strtolower(trim((string) $candidateHost));
			if ($clean !== '') {
				$hasConfiguredHosts = true;
				$allowedHosts[] = $clean;
				$allowedHosts[] = (string) explode(':', $clean)[0];
			}
		}

		if (!$hasConfiguredHosts) {
			$serverHost = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
			if ($serverHost !== '') {
				$allowedHosts[] = $serverHost;
				$allowedHosts[] = (string) explode(':', $serverHost)[0];
			}
		}

		return in_array($host, $allowedHosts, true) || in_array($hostWithPort, $allowedHosts, true);
	}

	/**
	 * Flash validation errors and immediately prepare a `303 See Other` redirect back.
	 *
	 * @param array<string, mixed>|InputfieldWrapper $errors
	 */
	public function backWithErrors(
		array|InputfieldWrapper $errors,
		?string $bag = null,
		string $fallbackUrl = '/',
		bool $terminate = false
	): Response {
		$this->withErrors($errors, $bag);

		return $this->back($fallbackUrl, $terminate);
	}

	public function setSsrGateway(SsrGatewayInterface $gateway): self
	{
		if ($gateway instanceof Wire) {
			$this->wire($gateway);
		}
		$this->ssrGateway = $gateway;

		return $this;
	}

	/**
	 * Render the root mount container and `<script type="application/json" data-page="app">` payload
	 * (or the pre-rendered SSR body) inside `_inertia.php`.
	 *
	 * @param array<string, mixed>|PageResponse|null $page
	 * @param array<string, string> $attributes Additional HTML attributes for the root container
	 */
	public function app(array|PageResponse|null $page = null, ?string $id = null, array $attributes = []): string
	{
		$pageResponse = $page instanceof PageResponse ? $page : $this->activePageResponse;
		$pageObject = $this->resolvePageObjectArgument($page);

		$configuredId = trim((string) $this->rootElementId);
		$rootId = $id !== null && trim($id) !== ''
			? trim($id)
			: ($configuredId !== '' ? $configuredId : 'app');

		$sanitizer = $this->wire()->sanitizer;
		$json = json_encode($pageObject, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
		$safeJson = is_string($json) ? $json : '{}';
		$safeId = $sanitizer->entities($rootId);
		$scriptTag = sprintf(
			'<script type="application/json" data-page="%s">%s</script>',
			$safeId,
			$safeJson
		);

		$ssr = $this->resolveSsrForPageObject($pageObject, $pageResponse);
		if ($ssr !== null && $ssr->body !== '') {
			if (str_contains($ssr->body, 'type="application/json"') && str_contains($ssr->body, 'data-page=')) {
				return $ssr->body;
			}

			return $ssr->body . "\n\t" . $scriptTag;
		}

		$extraAttrs = '';
		foreach ($attributes as $attrName => $attrVal) {
			if (
				!is_string($attrName)
				|| $attrName === ''
				|| $attrName === 'id'
				|| $attrName === 'data-page'
				|| preg_match('/^[a-zA-Z_:][a-zA-Z0-9_.:-]*$/', $attrName) !== 1
				|| str_starts_with(strtolower($attrName), 'on')
			) {
				continue;
			}
			$extraAttrs .= sprintf(
				' %s="%s"',
				$sanitizer->entities($attrName),
				$sanitizer->entities((string) $attrVal)
			);
		}

		return sprintf(
			"<div id=\"%s\"%s></div>\n\t%s",
			$safeId,
			$extraAttrs,
			$scriptTag
		);
	}

	/**
	 * Assemble and resolve the Inertia Page Object for a `PageResponse`.
	 *
	 * @return array<string, mixed>
	 */
	public function ___buildPageObject(PageResponse $pageResponse): array
	{
		$this->enforceDemoModeGuard();
		$prevEntityEncode = function_exists('ProcessWire\\__') ? __(true, 'entityEncode', false) : null;

		try {
			return $this->doBuildPageObject($pageResponse);
		} finally {
			if (function_exists('ProcessWire\\__')) {
				__(true, 'entityEncode', $prevEntityEncode ?? 1);
			}
		}
	}

	/**
	 * Build the Page Object with `LanguageFunctions` entity encoding disabled so `$this->_()`
	 * and `__()` return raw UTF-8 strings (`&` instead of `&amp;`) for JSON serialization.
	 *
	 * @return array<string, mixed>
	 */
	protected function doBuildPageObject(PageResponse $pageResponse): array
	{
		$this->providers();
		$component = $pageResponse->component();
		$isPrefetch = $this->request->isPrefetch();

		$flashConsumed = false;
		$customFlash = [];
		$noticesFlash = null;

		$consumeFlashOnce = function () use ($pageResponse, $isPrefetch, &$flashConsumed, &$customFlash, &$noticesFlash): void {
			if ($flashConsumed) {
				return;
			}
			$flashConsumed = true;
			if ($isPrefetch) {
				$customFlash = $pageResponse->getFlashedData();
				$noticesFlash = null;

				return;
			}
			$sessionFlash = $this->consumeSessionFlash();
			$customFlash = array_merge($sessionFlash, $this->flashedData, $pageResponse->getFlashedData());
			$this->flashedData = [];
			$noticesFlash = $this->autoShareFlash ? $this->collectFlashNotices() : null;
		};

		// 1. Automatic baseline props (`errors`, optional `flash`, `csrf`)
		$baselineProps = [
			'errors' => $this->always(fn(): object|array => $isPrefetch
				? (object) []
				: $this->errors->resolve($this->request->errorBag())),
		];

		if ($this->autoShareFlash) {
			$baselineProps['flash'] = static function () use ($consumeFlashOnce, &$noticesFlash, &$customFlash): array {
				$consumeFlashOnce();

				return array_merge($noticesFlash ?? [
					'messages' => [],
					'warnings' => [],
					'errors' => [],
				], $customFlash);
			};
		}

		if ($this->autoShareCsrf) {
			$baselineProps['csrf'] = fn(): array => $this->resolveCsrfProp();
		}

		$wire = $this->wire();
		/** @var Page|null $currentPage */
		$currentPage = $pageResponse->getPage() ?? $wire->page;
		$pageShared = ($currentPage instanceof Page && method_exists($currentPage, 'inertiaShared'))
			? (array) $currentPage->inertiaShared()
			: [];
		$pageWithData = ($currentPage instanceof Page && method_exists($currentPage, 'inertiaWithData'))
			? (array) $currentPage->inertiaWithData()
			: [];

		$wireProcess = $wire->process;
		$currentProcess = $pageResponse->getProcess() ?? ($wireProcess instanceof Process ? $wireProcess : null);
		$processShared = ($currentProcess instanceof Process && method_exists($currentProcess, 'inertiaShared'))
			? (array) $currentProcess->inertiaShared()
			: [];
		$processWithData = ($currentProcess instanceof Process && method_exists($currentProcess, 'inertiaWithData'))
			? (array) $currentProcess->inertiaWithData()
			: [];

		$componentShared = $this->collectNestedInertiaShared($pageResponse->rawProps());
		$effectiveSharedProps = array_merge($this->sharedProps, $pageShared, $processShared, $componentShared);

		$mergedInputProps = array_merge(
			$baselineProps,
			$effectiveSharedProps,
			$pageWithData,
			$processWithData,
			$pageResponse->rawProps()
		);

		$resolvedPayload = $this->resolver->resolve($component, $mergedInputProps, $this->request);

		if (isset($resolvedPayload['componentViewData']) && is_array($resolvedPayload['componentViewData']) && $resolvedPayload['componentViewData'] !== []) {
			$pageResponse->withViewData($resolvedPayload['componentViewData']);
		}

		if (!$flashConsumed) {
			$consumeFlashOnce();
		}

		$pageObject = [
			'component' => $component,
			'props' => $resolvedPayload['props'],
			'url' => $pageResponse->getUrlOverride() ?? $this->resolveCurrentUrl($pageResponse->getPage()),
			'version' => $this->getVersion(),
		];

		$topLevelFlash = $this->buildTopLevelFlash($noticesFlash, $customFlash);
		if ($topLevelFlash !== []) {
			$pageObject['flash'] = $topLevelFlash;
		}

		$sharedTopKeys = $this->resolveSharedTopKeys($baselineProps, $effectiveSharedProps, $resolvedPayload['props']);
		if ($sharedTopKeys !== []) {
			$pageObject['sharedProps'] = $sharedTopKeys;
		}

		foreach (['deferredProps', 'mergeProps', 'deepMergeProps', 'prependProps', 'matchPropsOn', 'onceProps', 'scrollProps'] as $metaKey) {
			if (isset($resolvedPayload[$metaKey]) && $resolvedPayload[$metaKey] !== []) {
				$pageObject[$metaKey] = $resolvedPayload[$metaKey];
			}
		}

		$encryptHistory = $pageResponse->getEncryptHistoryOverride() ?? (bool) $this->encryptHistory;
		if ($encryptHistory) {
			$pageObject['encryptHistory'] = true;
		}

		$sessionClearHistory = $isPrefetch ? false : $this->consumeSessionFlag(self::SESSION_CLEAR_HISTORY_KEY);
		$clearHistory = $pageResponse->getClearHistoryOverride() ?? $sessionClearHistory;
		if ($clearHistory) {
			$pageObject['clearHistory'] = true;
		}

		$sessionPreserveFragment = $isPrefetch ? false : $this->consumeSessionFlag(self::SESSION_PRESERVE_FRAGMENT_KEY);
		$preserveFragment = $pageResponse->getPreserveFragmentOverride() ?? $sessionPreserveFragment;
		if ($preserveFragment) {
			$pageObject['preserveFragment'] = true;
		}

		$this->recordTracyDiagnostics($pageObject);

		return $pageObject;
	}

	/**
	 * Recursively extract `inertiaShared()` maps from any `MarkupInertia`, `InputfieldInertia`,
	 * or `InputfieldWrapper` objects passed in `PageResponse` props.
	 *
	 * @param array<int|string, mixed> $items
	 * @return array<string, mixed>
	 */
	private function collectNestedInertiaShared(array $items): array
	{
		$shared = [];
		foreach ($items as $item) {
			if (is_array($item)) {
				$shared = array_merge($shared, $this->collectNestedInertiaShared($item));
				continue;
			}

			if (!is_object($item)) {
				continue;
			}

			if (method_exists($item, 'inertiaShared')) {
				$objShared = $item->inertiaShared();
				if (is_array($objShared) && $objShared !== []) {
					$shared = array_merge($shared, $objShared);
				}
			}

			if ($item instanceof InputfieldWrapper) {
				$children = [];
				foreach ($item->children() as $child) {
					$children[] = $child;
				}
				if ($children !== []) {
					$shared = array_merge($shared, $this->collectNestedInertiaShared($children));
				}
			}
		}

		return $shared;
	}

	/**
	 * Render a `PageResponse` into either an `X-Inertia: true` JSON payload or the Root View HTML shell.
	 */
	public function ___renderPageResponse(PageResponse $pageResponse): string
	{
		$this->applyGlobalSettingOverrides();
		$this->activePageResponse = $pageResponse;
		$this->activeSsrResponse = null;
		$this->ssrDispatchedForCurrentRender = false;

		if ($this->checkVersionConflict()) {
			$this->response->applyHeaders();
			$this->renderedPageResponse = $pageResponse;
			$this->renderedOutput = '';

			return '';
		}

		$pageObject = $pageResponse->toPageObject();

		if ($this->request->isInertia()) {
			$this->response->markAsInertiaJson();
			$this->response->applyHeaders();
			$json = $pageResponse->toJson();
			$this->renderedPageResponse = $pageResponse;
			$this->renderedOutput = $json;

			return $json;
		}

		// Initial full-page load: render Root View (`_inertia.php`)
		$this->response->setHeader('Vary', 'X-Inertia');
		$this->response->applyHeaders();

		$html = $this->renderRootView($pageResponse, $pageObject);
		$this->renderedPageResponse = $pageResponse;
		$this->renderedOutput = $html;

		return $html;
	}

	/**
	 * Return the rendered output for the active `PageResponse`, reusing the already-rendered output
	 * when the response was coerced to `(string)` during template or `Process` execution.
	 */
	protected function resolveActiveResponseOutput(): string
	{
		if ($this->activePageResponse === null) {
			return '';
		}

		if ($this->renderedPageResponse === $this->activePageResponse && $this->renderedOutput !== null) {
			return $this->renderedOutput;
		}

		return $this->renderPageResponse($this->activePageResponse);
	}

	/**
	 * Check whether the inbound request carries a stale `X-Inertia-Version` on a GET visit.
	 * When stale, configures a `409 Conflict` + `X-Inertia-Location` response.
	 */
	protected function checkVersionConflict(): bool
	{
		if (!$this->request->isInertia() || $this->request->method() !== 'GET') {
			return false;
		}

		if ($this->response->getStatusCode() === 409) {
			return true;
		}

		$clientVersion = $this->request->version();
		$serverVersion = $this->getVersion();

		if ($clientVersion !== null && $serverVersion !== '' && !hash_equals($serverVersion, $clientVersion)) {
			$this->response->conflictLocation($this->resolveCurrentUrl());

			return true;
		}

		return false;
	}

	/**
	 * Runs before `PageRender::renderPage()` inspects `$template->noAppendTemplateFile`
	 * (and after `site/ready.php`), ensuring `setting('inertia', [...])` or `$inertia->forTemplates(...)` takes effect.
	 */
	protected function hookBeforePageRender(HookEvent $event): void
	{
		$this->applyGlobalSettingOverrides();

		$page = $event->object;
		if (!$page instanceof Page) {
			return;
		}

		if ($this->shouldHandleAdminPage($page)) {
			$this->disablePageRenderCache($event);
			$this->executeAdminProcess($event, $page);

			return;
		}

		if ($page->template instanceof Template && $page->template->name === 'admin' && $this->request->isInertia()) {
			$targetUrl = (string) $this->wire()->input->url(true);
			$this->location($targetUrl !== '' ? $targetUrl : $page->url);

			return;
		}

		if ($this->shouldHandlePage($page)) {
			$this->configurePageTemplateFiles($page);
			$this->enforceDemoModeGuard();
			$this->disablePageRenderCache($event);
		}
	}

	/**
	 * Disable ProcessWire's file page cache on Inertia-rendered `Page::render` calls.
	 */
	private function disablePageRenderCache(HookEvent $event): void
	{
		$arg0 = $event->arguments(0);
		if (!is_string($arg0)) {
			$options = is_array($arg0) ? $arg0 : [];
			if (!array_key_exists('allowCache', $options)) {
				$options['allowCache'] = false;
				$event->arguments(0, $options);
			}
		}
	}

	/**
	 * Execute an Inertia-enabled Admin `Process` module directly during `Page::render`
	 * so full-page Inertia workspaces bypass classic `wire/core/admin.php` (`AdminThemeUikit`, `JqueryCore`, `JqueryUI`)
	 * while preserving ProcessWire's native `ProcessController` RBAC permission checks and URL segment dispatch.
	 */
	protected function executeAdminProcess(HookEvent $event, Page $page): void
	{
		$wire = $this->wire();
		$this->enforceDemoModeGuard();

		// Allow classic AdminThemeUikit flyout navigation requests (`navJSON`) to run through standard admin.php
		if (strtolower((string) $wire->input->urlSegment1) === 'navjson') {
			return;
		}

		if ($this->checkVersionConflict()) {
			$this->response->applyHeaders();
			$event->replace = true;
			$event->return = '';

			return;
		}

		$wire->pages->setOutputFormatting(false);

		if (!$wire->breadcrumbs instanceof Breadcrumbs) {
			$breadcrumbs = new Breadcrumbs();
			$this->wire($breadcrumbs);
			$wire->wire('breadcrumbs', $breadcrumbs);
			foreach ($page->parents() as $parent) {
				if ($parent instanceof Page && $parent->id > 1) {
					$breadcrumbs->add(new Breadcrumb($parent->url, (string) $parent->get('title|name')));
				}
			}
		}

		$rawProcess = $page->getUnformatted('process') ?: $page->process;
		$controller = new ProcessController();
		$this->wire($controller);
		if ($rawProcess instanceof Process) {
			$controller->setProcess($rawProcess);
		} else {
			$controller->setProcessName((string) $rawProcess);
		}

		try {
			$process = $controller->getProcess();
			if (!$process instanceof Process) {
				return;
			}

			// If the Process explicitly opts out of full-page admin rendering on this route,
			// defer to classic wire/core/admin.php (or force a 409 X-Inertia-Location redirect on XHR visits).
			if (
				method_exists($process, 'inertiaFullPageAdmin')
				&& !$process->inertiaFullPageAdmin()
			) {
				if ($this->request->isInertia()) {
					$this->location($wire->input->url(true));
					$this->response->applyHeaders();
					$event->replace = true;
					$event->return = '';
				}

				return;
			}

			$prevEntityEncode = function_exists('ProcessWire\\__') ? __(true, 'entityEncode', false) : null;
			try {
				$content = $controller->execute();
			} finally {
				if (function_exists('ProcessWire\\__')) {
					__(true, 'entityEncode', $prevEntityEncode ?? 1);
				}
			}
		} catch (WirePermissionException) {
			if ($this->request->isInertia() && $wire->user->isGuest()) {
				$adminUrl = (string) ($wire->config->urls->admin ?: '/processwire/');
				$this->location(rtrim($adminUrl, '/') . '/login/');
				$this->response->applyHeaders();
				$event->replace = true;
				$event->return = '';
			}

			return;
		} catch (Wire404Exception) {
			return;
		}

		if ($content instanceof PageResponse) {
			$this->activePageResponse = $content;
		}

		if ($this->activePageResponse !== null) {
			$event->replace = true;
			$event->return = $this->resolveActiveResponseOutput();

			return;
		}

		if ($content instanceof Response) {
			$content->applyHeaders();
			$event->replace = true;
			$event->return = '';

			return;
		}

		if (is_string($content)) {
			if ($this->isJsonString($content) && !headers_sent() && PHP_SAPI !== 'cli') {
				header('Content-Type: application/json; charset=utf-8');
			}
			$event->replace = true;
			$event->return = $content;
		}
	}

	protected function isJsonString(string $value): bool
	{
		$trimmed = ltrim($value);
		if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
			return false;
		}

		return json_validate($trimmed);
	}

	protected function hookBeforeTemplateFileRender(HookEvent $event): void
	{
		/** @var Page|null $page */
		$page = $this->wire()->page;
		if ($page instanceof Page && $this->shouldHandlePage($page) && $this->checkVersionConflict()) {
			$event->replace = true;
			$event->return = '';
		}
	}

	protected function hookAfterTemplateFileRender(HookEvent $event): void
	{
		$returnValue = $event->return;
		if ($returnValue instanceof PageResponse) {
			$this->activePageResponse = $returnValue;
			// Return empty string to PageRender so PageRender does not coerce PageResponse::__toString()
			// or run WireMarkupRegions over the Inertia payload before hookAfterPageRender runs.
			$event->return = '';
		} elseif ($returnValue instanceof Response) {
			$returnValue->applyHeaders();
			$event->return = '';
		}
	}

	protected function hookAfterPageRender(HookEvent $event): void
	{
		if ($this->activePageResponse !== null) {
			$event->return = $this->resolveActiveResponseOutput();

			return;
		}

		$page = $event->object;
		if ($page instanceof Page && ($this->shouldHandlePage($page) || $this->shouldHandleAdminPage($page)) && $this->checkVersionConflict()) {
			$this->response->applyHeaders();
			$event->return = '';
		}
	}

	/**
	 * Ensure ProcessWire's `$session->redirect($url)` uses `303 See Other` after `POST`, `PUT`, `PATCH`, or `DELETE`
	 * Inertia requests (preventing ProcessWire's default `301 Moved Permanently` on form submissions),
	 * or when explicitly triggered via `$inertia->back()`.
	 */
	protected function hookSessionRedirect(HookEvent $event): void
	{
		$method = $this->request->method();
		$isInertiaWrite = $this->request->isInertia() && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

		if ($this->forceNextRedirect303 || $isInertiaWrite) {
			$this->forceNextRedirect303 = false;
			$event->arguments(1, 303);
			$this->response->setStatusCode(303);
		}
	}

	/**
	 * Automatically flag `clearHistory: true` in session when a user logs out.
	 */
	protected function hookSessionLogout(HookEvent $event): void
	{
		$this->clearHistory(true);
	}

	/**
	 * When the Inertia module configuration is saved in Admin (or programmatically via `$modules->saveConfig('Inertia', ...)`),
	 * automatically regenerate TypeScript definitions (and scaffold missing `Page` classes if enabled).
	 */
	protected function hookAfterSaveModuleConfig(HookEvent $event): void
	{
		$class = $event->arguments(0);
		$className = is_object($class) ? $class->className() : (string) $class;
		if ($className !== 'Inertia' && !str_ends_with($className, '\\Inertia')) {
			return;
		}

		$data = $event->arguments(1);
		if (is_array($data)) {
			$this->applyConfigMap(array_merge($this->getDefaultConfig(), $data));
		} elseif (is_string($data) && $data !== '') {
			$this->applyConfigMap([$data => $event->arguments(2)]);
		}

		$this->regenerateTypesSafely($this->_('config save'));
	}

	/**
	 * When `$config->debug` and `autoGenerateTypes` are enabled, automatically refresh `inertia.d.ts`
	 * (and scaffold missing `Page` classes) when a template or field is saved.
	 */
	protected function hookAfterSchemaSaved(HookEvent $event): void
	{
		$arg = $event->arguments(0);
		if ($arg instanceof Template && !$this->isTemplateEnabled($arg)) {
			return;
		}

		$this->regenerateTypesSafely($this->_('schema save'));
	}

	/**
	 * Automatically clear `OnceProp` server-side `WireCache` entries when a `Page` is saved, deleted, or trashed,
	 * honoring ProcessWire's `$page->template->cacheExpire` rules (`PageRender::clearCacheFile()`).
	 */
	protected function hookAfterPageSavedOrDeleted(HookEvent $event): void
	{
		$page = $event->arguments(0);
		if (!$page instanceof Page || $page->id <= 0) {
			return;
		}

		$this->clearOnceCacheForPage($page, (string) $event->method);
	}

	/**
	 * Clear `OnceProp` server-side `WireCache` entries according to `$page->template->cacheExpire`.
	 *
	 * @see PropResolver::clearOnceCacheForPage()
	 */
	public function clearOnceCacheForPage(Page $page, string $eventMethod = 'saved'): void
	{
		$this->resolver->clearOnceCacheForPage($page, $eventMethod);
	}

	/**
	 * Run `TypeGenerator` safely when `autoGenerateTypes` is enabled.
	 */
	private function regenerateTypesSafely(string $context): void
	{
		if (!(bool) $this->autoGenerateTypes || $this->isDemoMode()) {
			return;
		}

		try {
			$this->typeGenerator()->generate(
				templateNames: null,
				force: false,
				generatePhp: (bool) $this->autoScaffoldPageClasses,
				generateTs: true,
				generateControllers: false,
				tsOutputPath: (string) $this->typeScriptOutputPath
			);
		} catch (Throwable $e) {
			$this->wire()->log->error(sprintf($this->_('Inertia type generation after %1$s failed: %2$s'), $context, $e->getMessage()));
		}
	}

	/**
	 * @param array<string, mixed> $options
	 */
	protected function applyConfigMap(array $options): void
	{
		foreach ($this->getDefaultConfig() as $key => $default) {
			if (!array_key_exists($key, $options)) {
				continue;
			}

			if ($key === 'enabledTemplates') {
				$this->set($key, $this->normalizeTemplateList($options[$key]));
			} else {
				$this->set($key, $options[$key]);
			}
		}

		$this->syncSsrGatewayConfig();
	}

	private function syncSsrGatewayConfig(): void
	{
		if ($this->ssrGateway instanceof HttpGateway) {
			$this->ssrGateway
				->setEnabled((bool) $this->ssrEnabled)
				->setUrl((string) $this->ssrUrl)
				->setTimeout((float) $this->ssrTimeout);
		}
	}

	/**
	 * @return list<string>
	 */
	public function normalizeTemplateList(mixed $templates): array
	{
		if (is_string($templates)) {
			$templates = explode(',', $templates);
		}

		if (!is_array($templates)) {
			return [];
		}

		$normalized = [];
		foreach ($templates as $tpl) {
			if ($tpl instanceof Template) {
				$name = trim((string) $tpl->name);
			} elseif (is_scalar($tpl)) {
				$name = trim((string) $tpl);
			} else {
				continue;
			}

			if ($name !== '' && $name !== 'admin') {
				$normalized[] = $name;
			}
		}

		return array_values(array_unique($normalized));
	}

	/**
	 * Render the configured Root View PHP template (e.g. `site/templates/_inertia.php` or fallback stub).
	 *
	 * @param array<string, mixed> $pageObject
	 */
	protected function renderRootView(PageResponse $pageResponse, array $pageObject): string
	{
		$wire = $this->wire();
		$config = $wire->config;
		$files = $wire->files;
		$templatesPath = rtrim((string) $config->paths->templates, '/\\');
		$siteModulesPath = rtrim((string) $config->paths->siteModules, '/\\');
		$stubsPath = __DIR__ . '/commands/stubs/templates';
		$defaultStubFile = $stubsPath . '/_inertia.php';

		$configuredView = trim((string) $this->rootView);
		$viewName = $pageResponse->getRootView() ?? ($configuredView !== '' ? $configuredView : '_inertia.php');
		if (!str_ends_with($viewName, '.php')) {
			$viewName .= '.php';
		}

		$candidatePath = str_starts_with($viewName, '/')
			? $viewName
			: ($templatesPath !== '' ? $templatesPath . '/' . ltrim($viewName, '/') : '');

		$resolvedCandidate = $candidatePath !== '' && is_file($candidatePath) ? realpath($candidatePath) : false;
		$realTemplatesDir = $templatesPath !== '' && is_dir($templatesPath) ? realpath($templatesPath) : false;
		$realSiteModulesDir = $siteModulesPath !== '' && is_dir($siteModulesPath) ? realpath($siteModulesPath) : false;
		$realStubsDir = is_dir($stubsPath) ? realpath($stubsPath) : false;

		$isAllowedPath = false;
		if (is_string($resolvedCandidate)) {
			if (is_string($realTemplatesDir) && str_starts_with($resolvedCandidate, $realTemplatesDir . DIRECTORY_SEPARATOR)) {
				$isAllowedPath = true;
			} elseif (is_string($realSiteModulesDir) && str_starts_with($resolvedCandidate, $realSiteModulesDir . DIRECTORY_SEPARATOR)) {
				$isAllowedPath = true;
			} elseif (is_string($realStubsDir) && str_starts_with($resolvedCandidate, $realStubsDir . DIRECTORY_SEPARATOR)) {
				$isAllowedPath = true;
			}
		}

		$targetFile = $isAllowedPath && is_string($resolvedCandidate)
			? $resolvedCandidate
			: (is_string($realStubsDir) ? $realStubsDir . '/_inertia.php' : $defaultStubFile);

		/** @var Page|null $currentPage */
		$currentPage = $pageResponse->getPage() ?? $wire->page;
		$pageViewData = ($currentPage instanceof Page && method_exists($currentPage, 'inertiaViewData'))
			? (array) $currentPage->inertiaViewData($pageObject)
			: [];
		$pageWithViewData = ($currentPage instanceof Page && method_exists($currentPage, 'inertiaWithViewData'))
			? (array) $currentPage->inertiaWithViewData($pageObject)
			: [];

		$wireProcess = $wire->process;
		$currentProcess = $pageResponse->getProcess() ?? ($wireProcess instanceof Process ? $wireProcess : null);
		$processViewData = ($currentProcess instanceof Process && method_exists($currentProcess, 'inertiaViewData'))
			? (array) $currentProcess->inertiaViewData($pageObject)
			: [];
		$processWithViewData = ($currentProcess instanceof Process && method_exists($currentProcess, 'inertiaWithViewData'))
			? (array) $currentProcess->inertiaWithViewData($pageObject)
			: [];

		$vars = PropResolver::mergeViewData(
			$this->sharedViewData,
			$pageViewData,
			$pageWithViewData,
			$processViewData,
			$processWithViewData,
			$pageResponse->viewData()
		);

		foreach ($vars as $varKey => $varVal) {
			if ($varVal instanceof Closure) {
				$vars[$varKey] = $varVal($pageObject);
			}
		}

		$locale = isset($vars['locale']) && is_scalar($vars['locale']) && trim((string) $vars['locale']) !== ''
			? trim((string) $vars['locale'])
			: 'en';

		$ssr = $this->resolveSsrForPageObject($pageObject, $pageResponse);
		$ssrHead = ($ssr !== null && $ssr->headMarkup() !== '') ? $ssr->headMarkup() : '';
		$rawCustomHead = $vars['head'] ?? '';
		if (is_array($rawCustomHead)) {
			$customHead = implode("\n\t", array_values(array_filter(
				array_map(static fn(mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $rawCustomHead),
				static fn(string $item): bool => $item !== ''
			)));
		} else {
			$customHead = is_scalar($rawCustomHead) ? trim((string) $rawCustomHead) : '';
		}
		$headParts = array_values(array_filter([$ssrHead, $customHead], static fn(string $part): bool => $part !== ''));

		$vars['locale'] = $locale;
		$vars['head'] = implode("\n\t", $headParts);
		$vars['inertia'] = $this;
		$vars['pageObject'] = $pageObject;

		$allowedPaths = array_values(array_filter([
			is_string($realTemplatesDir) ? $realTemplatesDir : ($templatesPath !== '' ? $templatesPath : null),
			is_string($realSiteModulesDir) ? $realSiteModulesDir : ($siteModulesPath !== '' ? $siteModulesPath : null),
			is_string($realStubsDir) ? $realStubsDir : $stubsPath,
		]));

		if (is_file($targetFile)) {
			return (string) $files->render($targetFile, $vars, [
				'allowDotDot' => false,
				'allowedPaths' => $allowedPaths,
			]);
		}

		$safeLocale = $wire->sanitizer->entities($locale);

		return "<!DOCTYPE html>\n<html lang=\"{$safeLocale}\">\n<head>\n\t<meta charset=\"utf-8\" />\n\t<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />\n\t"
			. $vars['head']
			. "\n</head>\n<body>\n\t"
			. $this->app($pageObject)
			. "\n</body>\n</html>";
	}

	/**
	 * Compute an MD5 version hash from the active Vite `manifest.json` file if it exists on disk.
	 */
	protected function resolveViteManifestVersion(): string
	{
		$wire = $this->wire();
		$config = $wire->config;
		$viteModule = $wire->vite;

		$rootPath = $viteModule instanceof Vite && trim((string) $viteModule->rootPath) !== ''
			? (string) $viteModule->rootPath
			: (string) $config->paths->templates;

		$buildDir = $viteModule instanceof Vite && trim((string) $viteModule->buildDirectory) !== ''
			? (string) $viteModule->buildDirectory
			: 'build';

		$manifestName = $viteModule instanceof Vite && trim((string) $viteModule->manifest) !== ''
			? (string) $viteModule->manifest
			: 'manifest.json';

		if ($rootPath === '') {
			return '';
		}

		$base = rtrim($rootPath, '/\\') . '/' . trim($buildDir, '/\\') . '/';
		$primary = $base . ltrim($manifestName, '/\\');
		if (is_file($primary)) {
			return (string) md5_file($primary);
		}

		$fallback = $base . '.vite/' . ltrim($manifestName, '/\\');
		if (is_file($fallback)) {
			return (string) md5_file($fallback);
		}

		return '';
	}

	/**
	 * Resolve the current request URL including query string.
	 */
	protected function resolveCurrentUrl(?Page $targetPage = null): string
	{
		$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
		if ($requestUri !== '') {
			return $requestUri;
		}

		$wire = $this->wire();
		$url = (string) $wire->input->url(true);
		if ($url !== '') {
			return $url;
		}

		/** @var Page|null $page */
		$page = $targetPage ?? $wire->page;
		if ($page instanceof Page && $page->template instanceof Template && $page->url !== '') {
			return (string) $page->url;
		}

		return '/';
	}

	/**
	 * Collect visible ProcessWire notices into a clean flash dictionary (`messages`, `warnings`, `errors`)
	 * and consume queued session notices (`$session->removeNotices()`) so they do not persist across subsequent requests.
	 *
	 * @return array{messages: list<string>, warnings: list<string>, errors: list<string>}
	 */
	protected function collectFlashNotices(): array
	{
		$flash = [
			'messages' => [],
			'warnings' => [],
			'errors' => [],
		];

		$wire = $this->wire();
		try {
			$notices = $wire->notices;
			$visibleNotices = $notices->getVisible();
			if (is_iterable($visibleNotices)) {
				foreach ($visibleNotices as $notice) {
					if (!is_object($notice)) {
						continue;
					}
					$text = trim((string) $notice->text);
					if ($text === '') {
						continue;
					}
					$allowMarkup = isset($notice->flags) && (((int) $notice->flags & Notice::allowMarkup) !== 0);
					if (!$allowMarkup && (str_contains($text, '<') || str_contains($text, '>'))) {
						$text = $wire->sanitizer->entities($text);
					}
					$class = strtolower((string) $notice->className());
					if (str_contains($class, 'error')) {
						$flash['errors'][] = $text;
					} elseif (str_contains($class, 'warning')) {
						$flash['warnings'][] = $text;
					} else {
						$flash['messages'][] = $text;
					}
				}
			}
			$wire->session->removeNotices();
			$notices->removeAll();
		} catch (Throwable $e) {
			$wire->log->error(sprintf($this->_('Inertia flash notice collection failed: %s'), $e->getMessage()));
		}

		return $flash;
	}

	/**
	 * Resolve ProcessWire's CSRF token pair for `props.csrf`.
	 *
	 * @return array{name: string, value: string}
	 */
	protected function resolveCsrfProp(): array
	{
		$csrf = $this->wire()->session->CSRF;

		return [
			'name' => (string) $csrf->getTokenName(),
			'value' => (string) $csrf->getTokenValue(),
		];
	}

	/**
	 * Emit the `XSRF-TOKEN` cookie on web responses so Axios / Inertia fetch automatically sends `X-XSRF-TOKEN`.
	 */
	protected function emitXsrfCookie(): void
	{
		if (headers_sent() || PHP_SAPI === 'cli') {
			return;
		}

		$csrf = $this->resolveCsrfProp();
		if ($csrf['value'] === '') {
			return;
		}

		$secure = (bool) $this->wire()->config->https;

		@setcookie('XSRF-TOKEN', $csrf['value'], [
			'expires' => 0,
			'path' => '/',
			'secure' => $secure,
			'httponly' => false,
			'samesite' => 'Lax',
		]);
	}

	/**
	 * @param array<string, list<string>>|null $noticesFlash
	 * @param array<string, mixed> $customFlash
	 * @return array<string, mixed>
	 */
	private function buildTopLevelFlash(?array $noticesFlash, array $customFlash): array
	{
		$nonEmptyNotices = array_filter(
			$noticesFlash ?? [],
			static fn(array $items): bool => $items !== []
		);

		return array_merge($nonEmptyNotices, $customFlash);
	}

	/**
	 * @param array<string, mixed> $baselineProps
	 * @param array<string, mixed> $sharedProps
	 * @param array<string, mixed> $resolvedProps
	 * @return list<string>
	 */
	private function resolveSharedTopKeys(array $baselineProps, array $sharedProps, array $resolvedProps): array
	{
		$sharedTopKeys = [];
		foreach (array_merge(array_keys($baselineProps), array_keys($sharedProps)) as $sharedKey) {
			$topKey = str_contains($sharedKey, '.') ? strstr($sharedKey, '.', true) : $sharedKey;
			if (is_string($topKey) && $topKey !== '' && array_key_exists($topKey, $resolvedProps)) {
				$sharedTopKeys[$topKey] = true;
			}
		}

		return array_keys($sharedTopKeys);
	}

	protected function writeSessionFlag(string $key, bool $enabled): void
	{
		$this->errors->setSessionValue($key, $enabled ? true : null);
	}

	protected function consumeSessionFlag(string $key): bool
	{
		return (bool) $this->errors->getSessionValue($key, true);
	}

	/**
	 * @param array<string, mixed> $incoming
	 */
	protected function writeSessionFlash(array $incoming): void
	{
		if ($incoming === []) {
			return;
		}

		$existing = $this->errors->getSessionValue(self::SESSION_FLASH_KEY, false);
		$merged = is_array($existing) ? array_merge($existing, $incoming) : $incoming;
		$this->errors->setSessionValue(self::SESSION_FLASH_KEY, $merged);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function consumeSessionFlash(): array
	{
		$stored = $this->errors->getSessionValue(self::SESSION_FLASH_KEY, true);

		return is_array($stored) ? $stored : [];
	}

	/**
	 * @param array<string, mixed>|PageResponse|null $page
	 * @return array<string, mixed>
	 */
	private function resolvePageObjectArgument(array|PageResponse|null $page): array
	{
		if ($page instanceof PageResponse) {
			return $page->toPageObject();
		}

		if (is_array($page)) {
			return $page;
		}

		if ($this->activePageResponse !== null) {
			return $this->activePageResponse->toPageObject();
		}

		return [
			'component' => '',
			'props' => ['errors' => (object) []],
			'url' => $this->resolveCurrentUrl(),
			'version' => $this->getVersion(),
		];
	}

	/**
	 * @param array<string, mixed> $pageObject
	 */
	private function resolveSsrForPageObject(array $pageObject, ?PageResponse $pageResponse = null): ?SsrResponse
	{
		if ($this->ssrDispatchedForCurrentRender) {
			return $this->activeSsrResponse;
		}

		$this->applyGlobalSettingOverrides();
		$this->ssrDispatchedForCurrentRender = true;

		if ($this->shouldSkipSsr($pageObject, $pageResponse)) {
			$this->activeSsrResponse = null;

			return null;
		}

		$this->syncSsrGatewayConfig();

		$this->activeSsrResponse = $this->ssrGateway->dispatch($pageObject);

		return $this->activeSsrResponse;
	}

	/**
	 * @param array<string, mixed> $pageObject
	 */
	private function shouldSkipSsr(array $pageObject, ?PageResponse $pageResponse = null): bool
	{
		if ($this->withoutSsrGlobal || ($pageResponse !== null && $pageResponse->isWithoutSsr())) {
			return true;
		}

		if ($this->withoutSsrPatterns === []) {
			return false;
		}

		$rawUrl = (string) ($pageObject['url'] ?? '');
		$urlPath = (string) strtok($rawUrl, '?');
		$url = ltrim($urlPath, '/');
		$trimmedUrl = trim($urlPath, '/');
		$component = (string) ($pageObject['component'] ?? '');

		foreach ($this->withoutSsrPatterns as $pattern) {
			$cleanPattern = ltrim($pattern, '/');
			$trimmedPattern = trim($pattern, '/');
			$baseWildcardPattern = str_ends_with($trimmedPattern, '/*')
				? substr($trimmedPattern, 0, -2)
				: null;
			if (
				fnmatch($pattern, $rawUrl)
				|| fnmatch($pattern, $urlPath)
				|| fnmatch($cleanPattern, $url)
				|| fnmatch($trimmedPattern, $trimmedUrl)
				|| ($baseWildcardPattern !== null && $trimmedUrl === $baseWildcardPattern)
				|| ($component !== '' && fnmatch($pattern, $component))
			) {
				return true;
			}
		}

		return false;
	}

	protected function applyGlobalSettingOverrides(): void
	{
		$global = setting('inertia');
		if (is_callable($global)) {
			$global = $global();
		}

		if (!is_array($global) || $global === $this->lastAppliedGlobalSettings) {
			return;
		}

		$this->lastAppliedGlobalSettings = $global;
		$this->applyConfigMap($global);
	}

	public function isDemoMode(): bool
	{
		return $this->request->isDemoMode();
	}

	/**
	 * When ProcessWire demo mode (`$config->demo = true`) is active, purge state-mutating request globals
	 * (`$_POST`, `$_FILES`, `$input->post`, JSON body) and emit a one-time warning notice.
	 */
	protected function enforceDemoModeGuard(): void
	{
		if (!$this->request->isDemoModeBlocked()) {
			return;
		}

		$this->request->purgeMutatingGlobals();

		if (!$this->demoWarningEmitted) {
			$this->demoWarningEmitted = true;
			$this->wire()->warning($this->_('Changes cannot be saved in demo mode.'));
		}
	}

	protected function inAdmin(): bool
	{
		$wire = $this->wire();
		/** @var Page|null $page */
		$page = $wire->page;
		if ($page instanceof Page && $page->template instanceof Template && $page->template->name === 'admin') {
			return true;
		}

		$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
		$adminUrl = (string) ($wire->config->urls->admin ?: '/processwire/');

		return $adminUrl !== '' && str_starts_with($requestUri, $adminUrl);
	}

	protected function isTracySupportEnabled(): bool
	{
		return (bool) $this->tracySupport
			&& (bool) $this->wire()->config->debug
			&& class_exists(Debugger::class);
	}

	protected function registerTracyPanel(): void
	{
		try {
			$bar = Debugger::getBar();
			$panel = new InertiaTracyPanel(fn(): array => $this->tracyDebug);
			$this->wire($panel);
			$bar->addPanel($panel, 'inertia-panel');
		} catch (Throwable $e) {
			$this->wire()->log->error(sprintf($this->_('Inertia Tracy panel registration failed: %s'), $e->getMessage()));
		}
	}

	/**
	 * @param array<string, mixed> $pageObject
	 */
	protected function recordTracyDiagnostics(array $pageObject): void
	{
		$this->tracyDebug = [
			'mode' => (string) $this->mode,
			'enabledTemplates' => $this->normalizeTemplateList($this->enabledTemplates),
			'isInertiaRequest' => $this->request->isInertia(),
			'isPrefetch' => $this->request->isPrefetch(),
			'method' => $this->request->method(),
			'component' => $pageObject['component'] ?? '',
			'url' => $pageObject['url'] ?? '',
			'version' => $pageObject['version'] ?? '',
			'inboundHeaders' => $this->request->headers(),
			'outgoingHeaders' => $this->response->getHeaders(),
			'flash' => $pageObject['flash'] ?? [],
			'sharedProps' => $pageObject['sharedProps'] ?? [],
			'deferredProps' => $pageObject['deferredProps'] ?? [],
			'mergeProps' => $pageObject['mergeProps'] ?? [],
			'deepMergeProps' => $pageObject['deepMergeProps'] ?? [],
			'prependProps' => $pageObject['prependProps'] ?? [],
			'matchPropsOn' => $pageObject['matchPropsOn'] ?? [],
			'onceProps' => $pageObject['onceProps'] ?? [],
			'scrollProps' => $pageObject['scrollProps'] ?? [],
			'encryptHistory' => $pageObject['encryptHistory'] ?? false,
			'clearHistory' => $pageObject['clearHistory'] ?? false,
			'preserveFragment' => $pageObject['preserveFragment'] ?? false,
			'ssrEnabled' => (bool) $this->ssrEnabled,
			'propsKeys' => is_array($pageObject['props'] ?? null) ? array_keys($pageObject['props']) : [],
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function getModuleConfigInputfields(array $data): InputfieldWrapper
	{
		$wire = $this->wire();
		$modules = $wire->modules;
		$templates = $wire->templates;
		$defaults = $this->getDefaultConfig();
		$values = array_merge($defaults, $data);

		/** @var InputfieldWrapper $wrapper */
		$wrapper = $modules->get('InputfieldWrapper');

		/** @var InputfieldRadios $modeField */
		$modeField = $modules->get('InputfieldRadios');
		$modeField->attr('name', 'mode');
		$modeField->label = $this->_('Template Activation Mode');
		$modeField->description = $this->_('Choose whether Inertia handles only selected ProcessWire templates (Hybrid mode) or all frontend templates (Full SPA mode).');
		$modeField->addOption(TemplateMode::Selected->value, $this->_('Selected Templates Only (Hybrid Mode — coexist with classic ProcessWire templates)'));
		$modeField->addOption(TemplateMode::All->value, $this->_('All Frontend Templates (Full SPA Mode)'));
		$modeField->attr('value', (string) $values['mode']);
		$wrapper->add($modeField);

		/** @var InputfieldAsmSelect $tplSelect */
		$tplSelect = $modules->get('InputfieldAsmSelect');
		$tplSelect->attr('name', 'enabledTemplates');
		$tplSelect->label = $this->_('Inertia Templates');
		$tplSelect->description = $this->_('Select the templates that should be rendered through Inertia. You can also configure this in site/init.php or site/ready.php via $inertia->forTemplates([...]).');
		foreach ($templates as $tpl) {
			if (!$tpl instanceof Template || $tpl->name === 'admin' || ($tpl->flags & Template::flagSystem)) {
				continue;
			}
			$label = $tpl->getLabel() !== '' && $tpl->getLabel() !== $tpl->name
				? "{$tpl->name} ({$tpl->getLabel()})"
				: $tpl->name;
			if (!$tpl->filenameExists()) {
				$label .= ' — ' . $this->_('no template file yet');
			}
			$tplSelect->addOption($tpl->name, $label);
		}
		$tplSelect->setAttribute('value', $this->normalizeTemplateList($values['enabledTemplates']));
		$tplSelect->showIf = 'mode=' . TemplateMode::Selected->value;
		$wrapper->add($tplSelect);

		$wrapper->add($this->buildConfigInputfield(
			'InputfieldCheckbox',
			'disableAppendTemplateFile',
			$this->_('Disable $config->appendTemplateFile (_main.php) on Inertia Templates'),
			(bool) $values['disableAppendTemplateFile'],
			$this->_('Automatically sets $template->noAppendTemplateFile = true on Inertia templates so ProcessWire does not append _main.php before rendering _inertia.php.'),
			50
		));

		$wrapper->add($this->buildConfigInputfield(
			'InputfieldCheckbox',
			'disablePrependTemplateFile',
			$this->_('Disable $config->prependTemplateFile (_init.php) on Inertia Templates'),
			(bool) $values['disablePrependTemplateFile'],
			$this->_('Leave unchecked if your Inertia templates rely on shared variables initialized in _init.php.'),
			50
		));

		$wrapper->add($this->buildConfigInputfield(
			'InputfieldText',
			'rootView',
			$this->_('Root View Template'),
			(string) $values['rootView'],
			$this->_('Filename of the Root View template in site/templates/ used on initial full-page Inertia loads.'),
			50
		));

		$wrapper->add($this->buildConfigInputfield(
			'InputfieldText',
			'rootElementId',
			$this->_('Root Mount Element ID'),
			(string) $values['rootElementId'],
			$this->_('DOM element ID where the Inertia client application mounts (default: app).'),
			50
		));

		$wrapper->add($this->buildConfigInputfield(
			'InputfieldCheckbox',
			'syncJsonToPost',
			$this->_('Synchronize JSON Request Body to $input->post'),
			(bool) $values['syncJsonToPost'],
			$this->_('Automatically populate ProcessWire $input->post from application/json payloads sent by Inertia useForm().'),
			50
		));

		$wrapper->add($this->buildConfigInputfield(
			'InputfieldCheckbox',
			'autoShareCsrf',
			$this->_('Auto-Share CSRF Token & XSRF-TOKEN Cookie'),
			(bool) $values['autoShareCsrf'],
			$this->_('Expose ProcessWire CSRF token in props.csrf and emit XSRF-TOKEN cookie for automatic Axios/Fetch header injection.'),
			50
		));

		$wrapper->add($this->buildConfigInputfield(
			'InputfieldCheckbox',
			'autoShareFlash',
			$this->_('Auto-Share ProcessWire Flash Notices'),
			(bool) $values['autoShareFlash'],
			$this->_('Share ProcessWire $notices (messages, warnings, errors) under props.flash.'),
			50
		));

		$wrapper->add($this->buildConfigInputfield(
			'InputfieldCheckbox',
			'encryptHistory',
			$this->_('Encrypt Browser History by Default (Inertia v3)'),
			(bool) $values['encryptHistory'],
			$this->_('Mandate browser history encryption across all Inertia responses unless overridden per page.'),
			50
		));

		$wrapper->add($this->buildConfigInputfield(
			'InputfieldCheckbox',
			'ssrEnabled',
			$this->_('Enable Server-Side Rendering (SSR Gateway)'),
			(bool) $values['ssrEnabled'],
			$this->_('Dispatch initial Page Objects to a local Node/Bun SSR server, falling back to CSR if unreachable.'),
			25
		));

		$wrapper->add($this->buildConfigInputfield(
			'InputfieldText',
			'ssrUrl',
			$this->_('SSR Server URL'),
			(string) $values['ssrUrl'],
			null,
			50
		));

		$wrapper->add($this->buildConfigInputfield(
			'InputfieldFloat',
			'ssrTimeout',
			$this->_('SSR Timeout (Seconds)'),
			(float) $values['ssrTimeout'],
			null,
			25
		));

		$wrapper->add($this->buildConfigInputfield(
			'InputfieldCheckbox',
			'tracySupport',
			$this->_('Enable TracyDebugger Panel'),
			(bool) $values['tracySupport'],
			$this->_('Register the Inertia v3 protocol panel in TracyDebugger when $config->debug is true.'),
			33
		));

		$wrapper->add($this->buildConfigInputfield(
			'InputfieldCheckbox',
			'autoGenerateTypes',
			$this->_('Auto-Generate TypeScript Definitions (inertia.d.ts)'),
			(bool) $values['autoGenerateTypes'],
			$this->_('Automatically regenerate TypeScript interfaces when saving module settings or (in debug mode) when saving templates/fields.'),
			33
		));

		$wrapper->add($this->buildConfigInputfield(
			'InputfieldCheckbox',
			'autoScaffoldPageClasses',
			$this->_('Auto-Scaffold Missing Custom Page Classes'),
			(bool) $values['autoScaffoldPageClasses'],
			$this->_('Create missing site/classes/[PascalCase]Page.php classes with @property PHPDoc annotations (never overwrites existing files).'),
			34
		));

		$wrapper->add($this->buildConfigInputfield(
			'InputfieldText',
			'typeScriptOutputPath',
			$this->_('TypeScript Definitions Output Path'),
			(string) $values['typeScriptOutputPath'],
			$this->_('Path relative to site/templates/ (or absolute path) where inertia.d.ts is written (default: assets/js/types/inertia.d.ts).')
		));

		return $wrapper;
	}

	private function buildConfigInputfield(
		string $type,
		string $name,
		string $label,
		bool|string|float $value,
		?string $description = null,
		int $columnWidth = 100
	): Inputfield {
		/** @var Inputfield $field */
		$field = $this->wire()->modules->get($type);
		$field->attr('name', $name);
		$field->label = $label;
		if ($description !== null && $description !== '') {
			$field->description = $description;
		}
		if (is_bool($value)) {
			$field->attr('checked', $value ? 'checked' : '');
		} else {
			$field->attr('value', (string) $value);
		}
		if ($columnWidth < 100) {
			$field->columnWidth = $columnWidth;
		}

		return $field;
	}
}
