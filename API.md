# Inertia (`ProcessWire\Inertia` & `Totoglu\Inertia`)

Inertia.js v3 server-side protocol and lifecycle adapter for ProcessWire.

## Value type

- **Module Class**: `ProcessWire\Inertia` (`Inertia.module.php`) — `ConfigurableModule` registered at `$this->wire()->inertia` (`Inertia::JSON_HTML_SAFE_FLAGS` standardizes HTML-safe JSON encoding across `app()`, `MarkupInertia`, and `InputfieldInertia`).
- **Context Trait**: `Totoglu\Inertia\InteractWithInertia` (`src/InteractWithInertia.php`) — trait for Custom Page Classes (`site/classes/DefaultPage.php`, `site/classes/*Page.php`), `Process` modules, `Markup` modules, and `Inputfield` modules.
- **Extendable Base Classes**:
  - `Totoglu\Inertia\ProcessInertia` (`src/ProcessInertia.php`) — abstract `Process` base class with `InteractWithInertia`, full-page admin Inertia lifecycle integration (`inertiaFullPageAdmin(): true`), and automatic mutating CSRF enforcement (`shouldValidateCsrf(): true`).
  - `Totoglu\Inertia\MarkupInertia` (`src/MarkupInertia.php`) — abstract `ModuleJS` base class implementing `InertiaSerializable` and `InteractWithInertia` for server-configured Inertia islands and direct prop composition.
  - `Totoglu\Inertia\InputfieldInertia` (`src/InputfieldInertia.php`) — abstract `Inputfield` base class implementing `InertiaSerializable` and `InteractWithInertia` for classic ProcessWire forms and full-page Inertia forms.
- **Contracts & Fieldtype Trait**:
  - `Totoglu\Inertia\Contracts\InertiaSerializable` (`src/Contracts/InertiaSerializable.php`) — contract (`toInertiaProp(): mixed`) for custom prop serialization.
  - `Totoglu\Inertia\Contracts\InertiaFieldtypeInterface` (`src/Contracts/InertiaFieldtypeInterface.php`) & `Totoglu\Inertia\InteractWithInertiaFieldtype` (`src/InteractWithInertiaFieldtype.php`) — contract and default trait for custom `Fieldtype` modules (`toInertiaFieldValue()`, `toInertiaPhpType()`, `toInertiaTsType()`, `toInertiaTsDefinitions()`).
  - `Totoglu\Inertia\Contracts\SsrGatewayInterface` (`src/Contracts/SsrGatewayInterface.php`) — contract (`dispatch(array $pageObject): ?SsrResponse`) implemented by `Totoglu\Inertia\Ssr\HttpGateway`.
- **Provider Pipeline**: `Totoglu\Inertia\Provider\ProviderRegistry` (`$inertia->providers()`), `TemplateDataProvider`, `FieldDataProvider`, `UserDataProvider`, and Tier-1 field providers (`ScalarFieldDataProvider`, `MediaFieldDataProvider`, `PageReferenceFieldDataProvider`, `RepeaterFieldDataProvider`, `OptionsFieldDataProvider`).
- **Response Builder & Prop Wrappers**: `Totoglu\Inertia\PageResponse` (`src/PageResponse.php`), `PropInterface`, `DeferredProp`, `MergeProp`, `OnceProp`, `ScrollProp`, `OptionalProp`, `AlwaysProp`, `ConfiguresMergeStrategy`, `ConfiguresOnceStrategy`, and `MergeStrategy`.
- **Support Services & Enums**: `Totoglu\Inertia\Support\PropResolver` (`$inertia->resolver`), `Totoglu\Inertia\Support\ErrorBag` (`$inertia->errors`), `Totoglu\Inertia\Support\TypeGenerator` (`$inertia->typeGenerator()`), `Totoglu\Inertia\Support\RootViewRenderer`, `Totoglu\Inertia\Support\AdminProcessExecutor`, `Totoglu\Inertia\Debug\InertiaTracyPanel`, and `Totoglu\Inertia\Support\TemplateMode`.
- **Request & Response Services**: `Totoglu\Inertia\Request` (`$inertia->request`) and `Totoglu\Inertia\Response` (`$inertia->response`).
- **Global Helper**: `inertia(string|\UnitEnum|null $component = null, array $props = []): \ProcessWire\Inertia|\Totoglu\Inertia\PageResponse` (`functions.php`).

## Getting and setting values

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

use Totoglu\Inertia\InteractWithInertia;

// 1. Base Custom Page Class (site/classes/DefaultPage.php):
class DefaultPage extends Page
{
	use InteractWithInertia;

	public function inertiaWithData(): array
	{
		return [
			'page' => $this,
			'browserTitle' => (string) $this->title,
		];
	}

	public function inertiaViewData(array $pageObject = []): array
	{
		$safeTitle = $this->wire()->sanitizer->entities((string) $this->title);

		return [
			'locale' => 'en',
			'head' => ["<title>{$safeTitle}</title>"],
		];
	}
}

// 2. Template Custom Page Class (site/classes/ArticlePage.php):
class ArticlePage extends DefaultPage
{
	public function inertiaWithData(): array
	{
		return [
			...parent::inertiaWithData(),
			'related' => $this->inertia()->defer(
				fn(): PageArray => $this->wire()->pages->find("template=article, id!={$this->id}, sort=-created, limit=4")
			),
		];
	}
}

// 3. Template Controller (site/templates/article.php):
/** @var ArticlePage $page */
return $page->inertiaRender('Articles/Show');
```

## `ProcessWire\Inertia` Methods

| Method | Signature | Description |
|---|---|---|
| `instance` | `public static function instance(?Wire $context = null): self` | Resolves the shared `ProcessWire\Inertia` module instance from the active `Wire` context. |
| `getDefaultConfig` | `public function getDefaultConfig(): array` | Returns the canonical default module configuration map. |
| `forTemplates` | `public function forTemplates(string\|array $templates, bool $append = true): self` | Registers template names handled by Inertia (`mode = 'selected'`) and configures `$template->noAppendTemplateFile = true`. |
| `forAllTemplates` | `public function forAllTemplates(bool $enabled = true): self` | Enables Inertia across all non-admin, non-system frontend templates (`mode = 'all'`). |
| `normalizeTemplateList` | `public function normalizeTemplateList(mixed $templates): array` | Normalizes comma-separated or array template inputs while rejecting `'admin'`, numeric IDs, and `Template::flagSystem` templates. |
| `isTemplateEnabled` | `public function isTemplateEnabled(string\|Template $template): bool` | Returns `true` if the non-system frontend template is configured to run under Inertia. |
| `shouldHandlePage` | `public function shouldHandlePage(Page $page): bool` | Returns `true` if the given `Page` uses an Inertia-enabled template. |
| `isProcessInertiaEnabled` | `public function isProcessInertiaEnabled(Process\|string\|null $process): bool` | Returns `true` if the given site `Process` module uses `InteractWithInertia` (`inertiaRender()`). |
| `shouldHandleAdminPage` | `public function shouldHandleAdminPage(?Page $page): bool` | Returns `true` if the given `template=admin` `Page` is assigned to an Inertia-enabled `Process` module. |
| `configurePageTemplateFiles` | `public function configurePageTemplateFiles(Page $page): void` | Configures `$template->noAppendTemplateFile` / `$template->noPrependTemplateFile` on Inertia-handled pages. |
| `ensureXsrfCookie` | `public function ensureXsrfCookie(): void` | Emits the `XSRF-TOKEN` cookie when `autoShareCsrf` is enabled (invoked automatically on Inertia responses and mounted islands). |
| `resolveXsrfCookieOptions` | `public static function resolveXsrfCookieOptions(Config $config): array` | Builds the normalized `WireInputDataCookie` options array (`domain`, `secure`, `samesite`, `httponly: false`) for `XSRF-TOKEN`. |
| `render` | `public function render(string\|UnitEnum $component, array $props = []): PageResponse` | Creates and registers an active `PageResponse` for the current request. |
| `getActiveResponse` | `public function getActiveResponse(): ?PageResponse` | Returns the active `PageResponse` for the current request, if any. |
| `checkVersionConflict` | `public function checkVersionConflict(): bool` | Checks inbound `X-Inertia-Version` on `GET` Inertia visits and prepares a `409 Conflict` (`X-Inertia-Location`) reload when stale. |
| `enforceDemoModeGuard` | `public function enforceDemoModeGuard(): bool` | Purges mutating request state (`$input->post`, `$_FILES`, JSON body) and emits a one-time warning when `$config->demo` is enabled. |
| `isTrustedAuthority` | `public static function isTrustedAuthority(Config $config, string $host, ?int $port = null): bool` | Validates `$host` and `$port` against `$config->httpHost` / `$config->httpHosts` (failing closed to loopback when unconfigured). |
| `resolveAdminUrl` | `public static function resolveAdminUrl(Config $config): string` | Resolves the configured ProcessWire admin URL path (`$config->urls->admin`). |
| `formatNoticeText` | `public static function formatNoticeText(string $rawText, int $flags, Sanitizer $sanitizer): string` | Formats a ProcessWire `Notice` text string safely for JSON output, entity-encoding angle brackets unless `Notice::allowMarkup` is set. |
| `withoutEntityEncoding` | `public static function withoutEntityEncoding(callable $callback): mixed` | Runs `$callback` with ProcessWire `LanguageFunctions` entity encoding temporarily disabled and restores the prior setting in `finally`. |
| `resetState` | `public function resetState(): self` | Resets request state, shared props, session flags, and template overrides (primarily for isolated testing). |
| `share` | `public function share(string\|array $key, mixed $value = null): self` | Shares global props across all Inertia responses (supports dot-notation and lazy closures). |
| `getShared` | `public function getShared(?string $key = null, mixed $default = null): mixed` | Retrieves a shared prop by key or all shared props. |
| `flushShared` | `public function flushShared(): self` | Clears all registered shared props. |
| `viewData` | `public function viewData(string\|array $key, mixed $value = null): self` | Shares server-only PHP variables (`$locale`, `$head`, `$scripts`, `$styles`, etc., including `Closure($pageObject)` resolvers) with the Root View (`_inertia.php`). |
| `getViewData` | `public function getViewData(?string $key = null, mixed $default = null): mixed` | Retrieves a shared Root View variable by key or all shared Root View variables. |
| `flushViewData` | `public function flushViewData(): self` | Clears all registered shared Root View variables. |
| `setRootView` | `public function setRootView(string $view): self` | Overrides the default Root View template filename (default `_inertia.php`). |
| `optional` | `public function optional(callable $callback): OptionalProp` | Creates an `OptionalProp` evaluated only when requested via `X-Inertia-Partial-Data` (supports `->once()`). |
| `defer` | `public function defer(callable $callback, string $group = 'default'): DeferredProp` | Creates a `DeferredProp` fetched asynchronously after initial page load (supports `->merge()` and `->once()`). |
| `merge` | `public function merge(mixed $value): MergeProp` | Creates a `MergeProp` (`MergeStrategy::Append`, supporting nested `->append('data', matchOn: 'id')` and `->once()`). |
| `prepend` | `public function prepend(mixed $value): MergeProp` | Creates a `MergeProp` (`MergeStrategy::Prepend`, supporting nested `->prepend('items')` and `->once()`). |
| `deepMerge` | `public function deepMerge(mixed $value): MergeProp` | Creates a `MergeProp` (`MergeStrategy::Deep`, supporting `->once()`). |
| `once` | `public function once(callable $callback): OnceProp` | Creates an `OnceProp` remembered across client navigations (`onceProps` + `X-Inertia-Except-Once-Props`). |
| `shareOnce` | `public function shareOnce(string $key, callable $callback): OnceProp` | Registers a shared `OnceProp` globally and returns the `OnceProp` for `->as()`, `->until()`, or `->fresh()`. |
| `scroll` | `public function scroll(mixed $value, string $wrapper = 'data', string $pageName = 'page'): ScrollProp` | Creates a `ScrollProp` for `<InfiniteScroll>` (`scrollProps` + `<prop>.data` merge). |
| `always` | `public function always(mixed $value): AlwaysProp` | Creates an `AlwaysProp` included on every visit regardless of partial reload filters. |
| `withErrors` | `public function withErrors(array\|InputfieldWrapper $errors, ?string $bag = null): self` | Flashes validation errors to `$session` for `props.errors`. |
| `flash` | `public function flash(string\|array $key, mixed $value = null): self` | Flashes custom key-value data into top-level `page.flash` (and `props.flash`). |
| `back` | `public function back(string $fallbackUrl = '/', bool $terminate = false): Response` | Prepares a `303 See Other` redirect back to `Referer` (or `$fallbackUrl`). |
| `backWithErrors` | `public function backWithErrors(array\|InputfieldWrapper $errors, ?string $bag = null, string $fallbackUrl = '/', bool $terminate = false): Response` | Flashes validation errors and prepares a `303 See Other` redirect back. |
| `redirect` | `public function redirect(string $url, bool $terminate = false): Response` | Triggers an internal SPA redirect (`409 Conflict` + `X-Inertia-Redirect` on XHR visits, or `303 See Other` on normal visits). |
| `location` | `public function location(string $url, bool $terminate = false): Response` | Triggers a `409 Conflict` + `X-Inertia-Location` hard redirect. |
| `encryptHistory` | `public function encryptHistory(bool $encrypt = true): self` | Sets `encryptHistory: true` globally. |
| `clearHistory` | `public function clearHistory(bool $clear = true): self` | Instructs the client router to wipe encrypted browser history (`clearHistory: true`). |
| `preserveFragment` | `public function preserveFragment(bool $preserve = true): self` | Preserves URL hash fragments across redirects (`preserveFragment: true`). |
| `withoutSsr` | `public function withoutSsr(bool\|string\|array $patterns = true): self` | Disables SSR globally or for matching URL/component wildcard patterns. |
| `app` | `public function app(array\|PageResponse\|null $page = null, ?string $id = null, array $attributes = []): string` | Outputs `<div id="app"></div>` paired with `<script type="application/json" data-page="app">...</script>` (or pre-rendered SSR `<body>`). |
| `addTransformer` | `public function addTransformer(string $className, callable $callback): self` | Registers a custom class-targeted object-to-prop transformer. |
| `providers` | `public function providers(): ProviderRegistry` | Returns the booted `ProviderRegistry` instance. |
| `registerProvider` | `public function registerProvider(FieldDataProvider\|TemplateDataProvider\|UserDataProvider $provider): self` | Registers a field, template, or user provider with Replace-Don't-Layer precedence. |
| `*registerProviders` | `public function ___registerProviders(): void` | Hookable method fired when `ProviderRegistry` boots. |
| `*buildPageObject` | `public function ___buildPageObject(PageResponse $pageResponse): array` | Hookable method compiling a `PageResponse` into the Inertia v3 Page Object array. |
| `*renderPageResponse` | `public function ___renderPageResponse(PageResponse $pageResponse): string` | Hookable method rendering a `PageResponse` into JSON (`X-Inertia: true`) or the Root View HTML shell. |
| `*uninstall` | `public function ___uninstall(): void` | Hookable lifecycle method purging `WireCache` (`inertia-once`) and `Session` (`inertia`) namespaces on module uninstall. |
| `setSsrGateway` | `public function setSsrGateway(SsrGatewayInterface $gateway): self` | Overrides the SSR Gateway implementation (`Totoglu\Inertia\Ssr\HttpGateway` by default). |
| `typeGenerator` | `public function typeGenerator(): TypeGenerator` | Returns a `TypeGenerator` bound to this module. |
| `version` | `public function version(string\|Closure\|null $version): self` | Overrides the asset version string or resolver (defaults to Vite `hot` or `manifest.json` hash). |
| `getVersion` | `public function getVersion(): string` | Resolves and returns the active asset version string. |

Public service properties on `ProcessWire\Inertia`: `$request` (`Totoglu\Inertia\Request`), `$response` (`Totoglu\Inertia\Response`), `$errors` (`Totoglu\Inertia\Support\ErrorBag`), and `$resolver` (`Totoglu\Inertia\Support\PropResolver`). Public constants: `Inertia::JSON_HTML_SAFE_FLAGS`, `Inertia::SESSION_NAMESPACE`, `Inertia::SESSION_FLASH_KEY`, `Inertia::SESSION_CLEAR_HISTORY_KEY`, `Inertia::SESSION_PRESERVE_FRAGMENT_KEY`.

## `Totoglu\Inertia\InteractWithInertia` Trait Methods

Used by `site/classes/DefaultPage.php`, `site/classes/*Page.php`, `ProcessInertia`, `MarkupInertia`, and `InputfieldInertia`:

| Method | Signature | Description |
|---|---|---|
| `inertia` | `public function inertia(): Inertia` | Returns the wired `ProcessWire\Inertia` module instance from the current `Wire` context. |
| `with` | `public function with(string\|array $key, mixed $value = null): self` | Attaches or merges explicit component props onto the host instance. |
| `getInertiaProps` | `public function getInertiaProps(): array` | Returns merged component props (`array_replace($this->inertiaWithData(), $this->inertiaProps)`). |
| `inertiaRender` | `public function inertiaRender(string\|UnitEnum $component, array $props = []): PageResponse` | Renders an Inertia component, merging `$this->inertiaProps` + `$props` and binding `$this` via `forPage($this)` (`Page`) or `forProcess($this)` (`Process`). |
| `inertiaShared` | `public function inertiaShared(): array` | Returns host-scoped shared props merged into `props` and `sharedProps`. |
| `inertiaWithData` | `public function inertiaWithData(): array` | Returns default component props for this `Page` or `Process`, merged automatically before explicit `inertiaRender()` props during `buildPageObject()`. |
| `inertiaViewData` | `public function inertiaViewData(array $pageObject = []): array` | Returns Root View (`_inertia.php`) PHP variables (`['locale' => 'en', 'head' => [...], 'scripts' => [...], 'styles' => [...]]`). |
| `inertiaWithViewData` | `public function inertiaWithViewData(array $pageObject = []): array` | Returns additional Root View PHP variables merged on top of `inertiaViewData()` via `PropResolver::mergeViewData()`. |
| `inertiaRootView` | `public function inertiaRootView(): ?string` | Optional Root View template override for this `Page` or `Process` (`null` uses the module's configured Root View). |
| `inertiaFullPageAdmin` | `public function inertiaFullPageAdmin(): bool` | Controls whether an admin `Process` response replaces the ProcessWire admin layout (`Page::render`) with the Inertia Root View (`true` by default). |

### Prop & View Data Merge Precedence

1. **Component Props (`Inertia::doBuildPageObject`):**
   `baselineProps` (`errors`, `flash`, `csrf`) → `$inertia->sharedProps` → `$page->inertiaShared()` → `$process->inertiaShared()` → nested `InertiaSerializable::inertiaShared()` → `$page->inertiaWithData()` → `$process->inertiaWithData()` → `$pageResponse->rawProps()`.
2. **Root View Variables (`Inertia::renderRootView` via `PropResolver::mergeViewData`):**
   `$inertia->sharedViewData` → `$page->inertiaViewData($pageObject)` → `$page->inertiaWithViewData($pageObject)` → `$process->inertiaViewData($pageObject)` → `$process->inertiaWithViewData($pageObject)` → `$pageResponse->getViewData()`. List keys `scripts` and `styles` merge additively with deduplication; `head` accepts `string` or `list<string>` and merges with SSR `<head>` output.

## `Totoglu\Inertia\Provider\ProviderRegistry` & DataProviders

| Class / Method | Signature | Description |
|---|---|---|
| `ProviderRegistry::register` | `public function register(FieldDataProvider\|TemplateDataProvider\|UserDataProvider $provider): self` | Registers a custom provider in the registry with Replace-Don't-Layer precedence. |
| `ProviderRegistry::resetToDefaults` | `public function resetToDefaults(): self` | Clears custom providers and restores Tier-1 built-in defaults. |
| `ProviderRegistry::activeContextPage` | `public function activeContextPage(): ?Page` | Returns the active root `Page` context during `providePage()` serialization, or falls back to `$wire->page`. |
| `ProviderRegistry::providePage` | `public function providePage(Page $page, ?Page $contextPage = null): array` | Delegates `User` instances to `provideUser()`, enforces `TemplateDataProvider::isAccessiblePage()`, and returns the serialized `Page` array (`id`, `name`, `title`, `url`, `httpUrl`, `template`, `created`, `modified`, field values, and `extraData()`). |
| `ProviderRegistry::provideUser` | `public function provideUser(User $user): array` | Serializes `$user` via the active `UserDataProvider`. |
| `ProviderRegistry::provideImage` | `public function provideImage(Pageimage $image, ?Page $contextPage = null): mixed` | Serializes `$image` via the active `FieldtypeImage` provider. |
| `ProviderRegistry::provideFile` | `public function provideFile(Pagefile $file, ?Page $contextPage = null): mixed` | Serializes `$file` via the active `FieldtypeFile` provider. |
| `ProviderRegistry::transformField` | `public function transformField(Field $field, Page $page): mixed` | Enforces `TemplateDataProvider::isFieldViewableOnPage()` and transforms `$page`'s value for `$field`. |
| `ProviderRegistry::resolveTemplateProvider` | `public function resolveTemplateProvider(Template\|string\|null $template): TemplateDataProvider` | Returns the specific `TemplateDataProvider` for `$template` or the wildcard `['*']` fallback. |
| `ProviderRegistry::resolveFieldProvider` | `public function resolveFieldProvider(Field $field): FieldDataProvider` | Resolves by `field:<name>` override, exact/FQCN `Fieldtype` class name, `InertiaFieldtypeInterface` bridge adapter, or wildcard fallback to `ScalarFieldDataProvider`. |
| `ProviderRegistry::resolveObjectProvider` | `public function resolveObjectProvider(object $value): ?FieldDataProvider` | Resolves a registered `FieldDataProvider` matching a standalone domain object class. |
| `ProviderRegistry::getUserProvider` | `public function getUserProvider(): UserDataProvider` | Returns the active `UserDataProvider` instance. |
| `ProviderRegistry::buildTemplateTsInterface` | `public function buildTemplateTsInterface(Template $template, string $interfaceName, array $leadingLines = [], array $skipFieldNames = []): array` | Builds the `export interface ... extends InertiaPageBase` block and auxiliary definitions for `$template`. |
| `ProviderRegistry::mediaTsDefinitions` | `public function mediaTsDefinitions(): array` | Returns TypeScript definitions (`InertiaImageFocus`, `InertiaImage`, `InertiaFile`) from the active media provider. |
| `ProviderRegistry::globalTsDefinitions` | `public function globalTsDefinitions(): array` | Returns global auxiliary TypeScript definitions across registered providers. |
| `ProviderRegistry::discoverFromDirectory` | `public function discoverFromDirectory(string $directory): self` | Discovers confined, non-symlinked concrete `*Provider.php` classes in `site/inertia/`. |
| `ProviderRegistry::studly` | `public static function studly(string $value): string` | Converts a template or field identifier into a valid PascalCase PHP/TypeScript identifier. |
| `ProviderRegistry::formatTsKey` | `public static function formatTsKey(string $key): string` | Formats a property key safely for TypeScript interface declarations. |
| `TemplateDataProvider::templates` | `public function templates(): array` | Target template names (e.g. `['article']` or `['*']`; `TemplateDataProvider::UI_FIELDSET_TYPES` lists skipped layout markers). |
| `TemplateDataProvider::provide` | `public function provide(Page $page, ProviderRegistry $registry): array` | Serializes base page data, viewable field values, and `extraData($page)`. |
| `TemplateDataProvider::eligibleFields` | `public function eligibleFields(?Template $template, array $skipFieldNames = []): array` | Returns non-UI-fieldset, non-excluded `Field` instances on `$template`. |
| `TemplateDataProvider::excludedFields` | `public function excludedFields(): array` | Field names to omit from serialization and `.d.ts` generation. |
| `TemplateDataProvider::extraData` | `protected function extraData(Page $page): array` | Computed key-value pairs appended to the serialized `Page` array. |
| `TemplateDataProvider::extraTsTypes` | `public function extraTsTypes(Template $template): array` | TypeScript property types (`['summary' => 'string']`) matching `extraData()`. |
| `TemplateDataProvider::tsDefinitions` | `public function tsDefinitions(Template $template): array` | Auxiliary TypeScript interface blocks contributed by this template provider. |
| `TemplateDataProvider::basePageData` | `public static function basePageData(Page $page, ?ProviderRegistry $registry = null): array` | Returns canonical base `Page` properties (`id`, `name`, `title`, `url`, `httpUrl`, `template`, `created`, `modified`). |
| `TemplateDataProvider::isAccessiblePage` | `public static function isAccessiblePage(Page $page, bool $allowCurrentPage = false, ?Page $currentPage = null, int $depth = 0): bool` | Enforces `NullPage`, trash, unpublished, `viewable(false)`, and `RepeaterPage` owner access checks. |
| `TemplateDataProvider::isFieldViewableOnPage` | `public static function isFieldViewableOnPage(Field $field, Page $page): bool` | Enforces ProcessWire field-level RBAC (`$field->useRoles` / `$field->viewable($page)`). |
| `TemplateDataProvider::isUiFieldset` | `public static function isUiFieldset(Field $field): bool` | Returns `true` if `$field` is a UI layout fieldset/tab marker rather than a data field. |
| `FieldDataProvider::setRegistry` | `public function setRegistry(ProviderRegistry $registry): void` | Binds the parent `ProviderRegistry` to this field provider. |
| `FieldDataProvider::supports` | `public function supports(): array` | Supported `Fieldtype` class names, `field:<name>` keys, or value class names. |
| `FieldDataProvider::transform` | `public function transform(mixed $value, ?Field $field = null, ?Page $page = null): mixed` | Transforms a runtime field/object value into a JSON-safe structure. |
| `FieldDataProvider::phpType` | `public function phpType(Field $field): string` | PHPDoc `@property` type for `site/classes/*Page.php`. |
| `FieldDataProvider::tsType` | `public function tsType(Field $field): string` | TypeScript property type for `inertia.d.ts`. |
| `FieldDataProvider::tsDefinitions` | `public function tsDefinitions(?Field $field = null): array` | Auxiliary TypeScript interfaces (`array<string, string>`) emitted into `inertia.d.ts`. |
| `UserDataProvider::provide` | `public function provide(User $user, ProviderRegistry $registry): array` | Serializes safe `User` fields while redacting PII/roles for non-authorized viewers and stripping credentials. |
| `UserDataProvider::canViewPrivateUserData` | `public static function canViewPrivateUserData(User $targetUser, ?User $viewer): bool` | Returns `true` when `$viewer` is `$targetUser`, a superuser, or holds `user-admin` for a non-superuser account. |
| `UserDataProvider::extraTsTypes` | `public function extraTsTypes(): array` | Declares TypeScript property types for `UserDataProvider::extraData()`. |
| `UserDataProvider::tsDefinition` | `public function tsDefinition(ProviderRegistry $registry): string` | Emits the `export interface InertiaUser` declaration. |
| `UserDataProvider::tsDefinitions` | `public function tsDefinitions(ProviderRegistry $registry): array` | Collects auxiliary TypeScript interfaces required by custom fields on the `user` template (`UserDataProvider::SYSTEM_USER_FIELDS` excludes internal credentials). |
| `ScalarFieldDataProvider` helpers | `resolveSelectedTextformatters()`, `formatFieldWithTextformatters()`, `decodeEntityEncodedText()`, `isMarkupField()`, `isKeyValueField()`, `isLinkField()`, `isIconField()` | Inspects `ScalarFieldDataProvider::ENTITY_ENCODER_FORMATTERS` and `INERTIA_IS_MARKUP_FIELD` / `INERTIA_IS_KEYVALUE_FIELD` / `INERTIA_IS_LINK_FIELD` / `INERTIA_IS_ICON_FIELD` Inputfield opt-in flags. |
| `MediaFieldDataProvider::formatPageimage` | `public static function formatPageimage(Pageimage $image): array` | Serializes core `Pageimage` metadata (`name`, `url`, `httpUrl`, `description`, `ext`, `filesize`, `width`, `height`, `focus: {top, left, zoom}`). |
| `MediaFieldDataProvider::formatPagefile` | `public static function formatPagefile(Pagefile $file): array` | Serializes core `Pagefile` metadata (`name`, `url`, `httpUrl`, `description`, `ext`, `filesize`). |
| `MediaFieldDataProvider::serializeImageValue` | `public static function serializeImageValue(mixed $value, bool $single = true, ?callable $formatter = null, ?Page $contextPage = null): ?array` | Serializes single (`$single = true`) or multi (`$single = false`) `Pageimage` / `Pageimages` values. |
| `MediaFieldDataProvider::serializeFileValue` | `public static function serializeFileValue(mixed $value, bool $single = true, ?Page $contextPage = null): ?array` | Serializes single (`$single = true`) or multi (`$single = false`) `Pagefile` / `Pagefiles` values. |
| `MediaFieldDataProvider::defaultTsDefinitions` | `public static function defaultTsDefinitions(): array` | Returns canonical `InertiaImageFocus`, `InertiaImage`, and `InertiaFile` TypeScript declarations. |
| `MediaFieldDataProvider::isAccessiblePagefile` | `public static function isAccessiblePagefile(Pagefile $file, ?Page $contextPage = null): bool` | Enforces temporary/hidden flags, owner `Page`/`User` RBAC, and `$ownerPage->viewable($file)` checks before serializing media. |
| `HttpGateway::normalizeUrl` / `setUrl` / `setEnabled` / `setTimeout` | `public static function normalizeUrl(string $url): string` | Normalizes SSR URLs (`/render` default path) and configures endpoint state (`HttpGateway::MAX_SSR_RESPONSE_BYTES` caps SSR payloads). |
| `HttpGateway::isSafeSsrUrl` | `public function isSafeSsrUrl(string $url): bool` | Validates that an SSR endpoint targets strict loopback (`127.0.0.1`, `::1`, `localhost`) or `$config->inertiaSsrAllowedHosts` and fails closed on empty DNS. |
| `HttpGateway::dispatch` | `public function dispatch(array $pageObject): ?SsrResponse` | Dispatches `$pageObject` to the SSR server with `CURLOPT_PROTOCOLS` and `CURLOPT_RESOLVE` DNS pinning, returning `?SsrResponse`. |
| `SsrResponse::headMarkup` | `public function headMarkup(): string` | Joins pre-rendered SSR `<head>` tags while neutralizing `</head>` breakout sequences. |

## Extendable Base Classes & Contracts (`Totoglu\Inertia`)

### 1. `Totoglu\Inertia\ProcessInertia`
Abstract `Process` base class (`extends ProcessWire\Process`, uses `InteractWithInertia`). Returning `$this->inertiaRender('Component', $props)` from `___execute*()` intercepts `ProcessController::execute` and `Page::render` to serve either `X-Inertia: true` JSON or the full-page admin Root View. Exposes `shouldValidateCsrf(): bool` (`true` by default) to enforce automatic CSRF validation on mutating admin requests.

### 2. `Totoglu\Inertia\MarkupInertia`
Abstract `ModuleJS` base class (`extends ProcessWire\ModuleJS implements InertiaSerializable`, uses `InteractWithInertia`):
- `inertiaComponent(): string` — Frontend component identifier (defaults to class name without `Markup` prefix).
- `renderReady(): bool` — Enqueues `scripts`/`styles` from `inertiaViewData()` and publishes `inertiaShared()` to `$config->js('InertiaShared', ...)` on classic pages.
- `___render(array $props = []): string` — Calls `renderReady()`, enqueues island assets, and outputs `<div class="pw-inertia-island" data-inertia-island="..." data-props="..."></div>`.
- `toInertiaProp(): array` — Returns `['component' => $this->inertiaComponent(), 'props' => ...]` when passed as a prop into a parent `PageResponse`.

### 3. `Totoglu\Inertia\InputfieldInertia`
Abstract `Inputfield` base class (`extends ProcessWire\Inputfield implements InertiaSerializable`, uses `InteractWithInertia`):
- `inertiaComponent(): string` — Frontend component identifier (defaults to class name without `Inputfield` prefix).
- `renderReady(?Inputfield $parent = null, $renderValueMode = false): bool` — Enqueues island assets and shared state before classic form rendering.
- `___render(): string` / `___renderValue(): string` — Outputs `<input type="hidden" data-inertia-input="{name}">` paired with `<div class="pw-inertia-inputfield-island" ...>`.
- `attr('value', $value)` / `val($value)` — Replaces `'value'` directly without merging onto prior array values.
- `___processInput(WireInputData $input): self` — Decodes bounded JSON or scalar submissions via `$this->attr('value', $sanitized)` and tracks changes (no-op in demo mode).
- `toInertiaProp(): array` — Serializes Inputfield schema and value when embedded inside an `InputfieldWrapper` prop tree.

### 4. `Totoglu\Inertia\Contracts\InertiaFieldtypeInterface` & `InteractWithInertiaFieldtype`
Implemented by custom `Fieldtype` modules:
- `toInertiaFieldValue(mixed $value, Field $field, ?Page $page = null): mixed`
- `toInertiaPhpType(Field $field): string`
- `toInertiaTsType(Field $field): string`
- `toInertiaTsDefinitions(?Field $field = null): array`

## `Totoglu\Inertia\PageResponse` Methods

| Method | Signature | Description |
|---|---|---|
| `component` | `public function component(): string` | Returns the target frontend component identifier. |
| `with` | `public function with(string\|array $key, mixed $value = null): self` | Adds or merges component-scoped props into the response. |
| `rawProps` | `public function rawProps(): array` | Returns the un-evaluated component props registered on this response. |
| `forPage` | `public function forPage(?Page $page): self` | Binds a ProcessWire `Page` context to the response (called automatically by `$page->inertiaRender()`). |
| `getPage` | `public function getPage(): ?Page` | Returns the bound `Page` instance, if any. |
| `forProcess` | `public function forProcess(?Process $process): self` | Binds a ProcessWire `Process` context to the response (called automatically by `$process->inertiaRender()`). |
| `getProcess` | `public function getProcess(): ?Process` | Returns the bound `Process` instance, if any. |
| `flash` | `public function flash(string\|array $key, mixed $value = null): self` | Flashes custom key-value data onto this response's `page.flash` object. |
| `getFlashedData` | `public function getFlashedData(): array` | Returns response-scoped flashed data registered via `flash()`. |
| `withViewData` | `public function withViewData(string\|array $key, mixed $value = null): self` | Passes server-only PHP variables to `_inertia.php`, merging `scripts` and `styles` lists additively. |
| `getViewData` | `public function getViewData(?string $key = null, mixed $default = null): mixed` | Retrieves a response Root View variable by key or all response Root View variables. |
| `rootView` | `public function rootView(string $view): self` | Overrides the Root View template filename or confined path for this response. |
| `getRootView` | `public function getRootView(): ?string` | Returns the response-specific Root View override, if any. |
| `url` | `public function url(string $url): self` | Overrides the `url` field of the resolved Page Object. |
| `getUrlOverride` | `public function getUrlOverride(): ?string` | Returns the response-specific URL override, if any. |
| `encryptHistory` | `public function encryptHistory(bool $encrypt = true): self` | Sets `encryptHistory` on this response. |
| `getEncryptHistoryOverride` | `public function getEncryptHistoryOverride(): ?bool` | Returns the response-specific `encryptHistory` override, if any. |
| `clearHistory` | `public function clearHistory(bool $clear = true): self` | Sets `clearHistory` on this response. |
| `getClearHistoryOverride` | `public function getClearHistoryOverride(): ?bool` | Returns the response-specific `clearHistory` override, if any. |
| `preserveFragment` | `public function preserveFragment(bool $preserve = true): self` | Sets `preserveFragment` on this response. |
| `getPreserveFragmentOverride` | `public function getPreserveFragmentOverride(): ?bool` | Returns the response-specific `preserveFragment` override, if any. |
| `withoutSsr` | `public function withoutSsr(bool $withoutSsr = true): self` | Bypasses the SSR Gateway for this response. |
| `isWithoutSsr` | `public function isWithoutSsr(): bool` | Returns `true` when SSR is disabled for this response. |
| `toPageObject` | `public function toPageObject(): array` | Resolves and returns the Inertia v3 Page Object array. |
| `jsonSerialize` | `public function jsonSerialize(): array` | Implements `JsonSerializable` by delegating to `toPageObject()`. |
| `toJson` | `public function toJson(): string` | Encodes the resolved Page Object as a JSON string. |
| `toOutput` | `public function toOutput(): string` | Renders either the JSON Page Object (`X-Inertia: true`) or the HTML Root View (`_inertia.php`). |

## `Totoglu\Inertia\Request`, `Response`, `Props\*` & `Support\*` Services

| Class / Method | Signature | Description |
|---|---|---|
| `Request::isInertia` / `isPrefetch` | `public function isInertia(): bool` / `isPrefetch(): bool` | Detects `X-Inertia: true` and `Purpose` / `Sec-Purpose: prefetch` visits. |
| `Request::isPartial` / `partialComponent` / `partialOnly` / `partialExcept` | `public function isPartial(?string $component = null): bool` | Inspects `X-Inertia-Partial-Component`, `X-Inertia-Partial-Data`, and `X-Inertia-Partial-Except` headers. |
| `Request::resetProps` / `exceptOnceProps` / `infiniteScrollMergeIntent` / `errorBag` / `version` | `public function resetProps(): array` (etc.) | Reads `X-Inertia-Reset`, `X-Inertia-Except-Once-Props`, `X-Inertia-Infinite-Scroll-Merge-Intent`, `X-Inertia-Error-Bag`, and `X-Inertia-Version`. |
| `Request::method` / `rawMethod` / `isMethod` / `isPost` / `isMutatingMethod` | `public function method(): string` | Resolves effective HTTP method via `WireInput`, supporting `_method` spoofing and `$config->demo` downgrading to `'GET'`. |
| `Request::input` / `get` / `post` / `all` / `getArray` / `postArray` / `json` / `pageNumber` / `uri` | `public function input(?string $key = null, mixed $default = null, array\|string\|int\|callable\|null $sanitizer = null): mixed` | Reads bounded request input (`Request::MAX_JSON_BYTES`, `Request::MAX_FORM_BYTES`, `Request::MAX_JSON_DEPTH`, dot-notation, `$sanitizer` chains, `isJson()`, `isUrlEncodedForm()`, `wireInput()`). |
| `Request::syncInboundInput` / `syncJsonToPost` / `syncCsrfToPost` / `validateCsrf` | `public function validateCsrf(bool $throwException = false): bool` | Bridges JSON / non-`POST` form payloads and `X-CSRF-TOKEN` / `X-XSRF-TOKEN` headers into `$input->post` and verifies `$session->CSRF`. |
| `Request::isDemoMode` / `isDemoModeBlocked` / `purgeMutatingGlobals` | `public function isDemoModeBlocked(): bool` | Detects and purges state-mutating request globals when `$config->demo = true`. |
| `Request::header` / `hasHeader` / `headers` / `fake` / `resetFake` | `public function header(string $name, ?string $default = null): ?string` | Reads canonical HTTP headers safely (blocking non-HTTP `$_SERVER` env keys) and supports test doubles via `fake()`. |
| `Request::isSafeInputKey` / `sanitizeInputTree` / `scalarString` | `public static function isSafeInputKey(string $key): bool` | Rejects prototype-pollution/superglobal keys (`Request::BLOCKED_INPUT_KEYS`) and normalizes scalar values. |
| `Response::setHeader` / `removeHeader` / `getHeader` / `getHeaders` | `public function setHeader(string $name, string $value, bool $emitNow = true): self` | Sanitizes CRLF/navigation URLs, clears conflicting redirect headers, and buffers/emits SAPI headers. |
| `Response::setStatusCode` / `getStatusCode` / `vary` | `public function vary(string $header = 'X-Inertia', bool $emitNow = true): self` | Manages HTTP status codes and deduplicated `Vary` header tokens. |
| `Response::markAsInertiaJson` / `conflictLocation` / `conflictRedirect` / `sanitizeNavigationUrl` / `applyHeaders` / `reset` | `public function conflictLocation(string $url, bool $emitNow = true): self` | Configures `X-Inertia: true` JSON or `409 Conflict` (`X-Inertia-Location` / `X-Inertia-Redirect`) responses with strict URL allowlist validation. |
| `PropInterface` (`DeferredProp`, `MergeProp`, `OnceProp`, `ScrollProp`, `OptionalProp`, `AlwaysProp`) | `public function __invoke(?Request $request = null): mixed` | Evaluates the underlying prop callback or value, optionally injecting the active `Request` instance. |
| `ConfiguresMergeStrategy` (`MergeProp`, `DeferredProp`) | `append()`, `prepend()`, `deepMerge()`, `matchOn()`, `shouldMerge()`, `mergeStrategy()`, `appendPaths()`, `prependPaths()`, `matchesOn()` | Configures root or nested array merge strategies (`MergeStrategy::Append\|Prepend\|Deep`) and deduplication keys. |
| `ConfiguresOnceStrategy` (`OnceProp`, `DeferredProp`, `MergeProp`, `OptionalProp`) | `once()`, `isOnce()`, `as()`, `key()`, `fresh()`, `isFresh()`, `until()`, `ttlSeconds()`, `expiresAt()` | Configures client/server `OnceProp` caching keys, `DateTimeInterface\|DateInterval\|int` TTLs, and forced refresh. |
| `ScrollProp` | `wrapper()`, `matchOn()`, `matchesOn()`, `withMeta()`, `metadata()` | Configures infinite-scroll wrapper keys, deduplication keys, and pagination cursor metadata. |
| `PropResolver` | `resolve()`, `resolveValue()`, `invokeCallback()`, `addTransformer()`, `clearTransformers()`, `mergeViewData()`, `resolveTemplateCacheTtl()`, `clearOnceCacheForPage()` | Evaluates prop trees, filters partial reloads, caches `OnceProp` payloads in `WireCache` (`PropResolver::ONCE_CACHE_NAMESPACE`), and serializes ProcessWire objects. |
| `ErrorBag` | `flash()`, `resolve()`, `clear()`, `getSessionValue()`, `setSessionValue()` | Stores and consumes flashed validation errors (`ErrorBag::SESSION_NAMESPACE` / `ErrorBag::SESSION_KEY`). |
| `TypeGenerator` | `generate()`, `generatePageClass()`, `buildPageClassCode()`, `buildTypeScriptDefinitions()`, `validateTargetTemplateName()`, `isValidCustomOutputPath()`, `writeScaffoldFile()`, `writeFileSafely()` | Generates `site/classes/*Page.php`, starter controllers, and `inertia.d.ts` with system-template, symlink, and `realpath()` confinement. |
| `RootViewRenderer` / `AdminProcessExecutor` / `InertiaTracyPanel` | `render()`, `execute()`, `getTab()`, `getPanel()` | Internal support services rendering `_inertia.php`, executing full-page admin `Process` workspaces, and rendering the `TracyDebugger` bar panel. |

## CLI Commands (`vendor/bin/wire`)

| Command | Signature | Description |
|---|---|---|
| `inertia:install` | `php vendor/bin/wire inertia:install [--stack=vue\|react\|svelte] [--template=inertia] [--dry-run] [--force] [--json]` | Publishes `_inertia.php`, `assets/js/inertia.ts`, starter component, Custom Page Class, template controller, and `inertia.d.ts` (rejecting system and numeric template names). |
| `inertia:types` | `php vendor/bin/wire inertia:types [--template=...] [--output=...] [--ts-only] [--php-only] [--controllers] [--dry-run] [--force] [--json]` | Generates `site/classes/*Page.php` and `site/templates/assets/js/types/inertia.d.ts` from enabled templates and registered DataProviders. |
