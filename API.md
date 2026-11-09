# Inertia (`ProcessWire\Inertia` & `Totoglu\Inertia`)

Inertia.js v3 server-side protocol and lifecycle adapter for ProcessWire.

## Value type

- **Module Class**: `ProcessWire\Inertia` (`Inertia.module.php`) — `ConfigurableModule` registered at `$this->wire()->inertia`.
- **Context Trait**: `Totoglu\Inertia\InteractWithInertia` (`src/InteractWithInertia.php`) — trait for Custom Page Classes (`site/classes/DefaultPage.php`, `site/classes/*Page.php`), `Process` modules, `Markup` modules, and `Inputfield` modules.
- **Extendable Base Classes**:
  - `Totoglu\Inertia\ProcessInertia` (`src/ProcessInertia.php`) — abstract `Process` base class with `InteractWithInertia` and full-page admin Inertia lifecycle integration (`inertiaFullPageAdmin(): true`).
  - `Totoglu\Inertia\MarkupInertia` (`src/MarkupInertia.php`) — abstract `ModuleJS` base class implementing `InertiaSerializable` and `InteractWithInertia` for server-configured Inertia islands and direct prop composition.
  - `Totoglu\Inertia\InputfieldInertia` (`src/InputfieldInertia.php`) — abstract `Inputfield` base class implementing `InertiaSerializable` and `InteractWithInertia` for classic ProcessWire forms and full-page Inertia forms.
- **Contracts & Fieldtype Trait**:
  - `Totoglu\Inertia\Contracts\InertiaSerializable` (`src/Contracts/InertiaSerializable.php`) — contract (`toInertiaProp(): array`) for custom prop serialization.
  - `Totoglu\Inertia\Contracts\InertiaFieldtypeInterface` (`src/Contracts/InertiaFieldtypeInterface.php`) & `Totoglu\Inertia\InteractWithInertiaFieldtype` (`src/InteractWithInertiaFieldtype.php`) — contract and default trait for custom `Fieldtype` modules (`toInertiaFieldValue()`, `toInertiaPhpType()`, `toInertiaTsType()`, `toInertiaTsDefinitions()`).
- **Provider Pipeline**: `Totoglu\Inertia\Provider\ProviderRegistry` (`$inertia->providers()`), `TemplateDataProvider`, `FieldDataProvider`, and `UserDataProvider`.
- **Response Builder**: `Totoglu\Inertia\PageResponse` (`src/PageResponse.php`).
- **Prop Resolver**: `Totoglu\Inertia\Support\PropResolver` (`$inertia->resolver`).
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
				fn(): PageArray => $this->wire()->pages->find("template=article, id!={$this->id}, limit=4")
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
| `forTemplates` | `public function forTemplates(string\|array $templates, bool $append = true): self` | Registers template names handled by Inertia (`mode = 'selected'`) and configures `$template->noAppendTemplateFile = true`. |
| `forAllTemplates` | `public function forAllTemplates(bool $enabled = true): self` | Enables Inertia across all non-admin frontend templates (`mode = 'all'`). |
| `isTemplateEnabled` | `public function isTemplateEnabled(string\|Template $template): bool` | Returns `true` if the template is configured to run under Inertia. |
| `shouldHandlePage` | `public function shouldHandlePage(Page $page): bool` | Returns `true` if the given `Page` uses an Inertia-enabled template. |
| `render` | `public function render(string\|UnitEnum $component, array $props = []): PageResponse` | Creates and registers an active `PageResponse` for the current request. |
| `share` | `public function share(string\|array $key, mixed $value = null): self` | Shares global props across all Inertia responses (supports dot-notation and lazy closures). |
| `shared` | `public function shared(string\|array $key, mixed $value = null): self` | Fluent alias for `share()`. |
| `getShared` | `public function getShared(?string $key = null, mixed $default = null): mixed` | Retrieves a shared prop by key or all shared props. |
| `flushShared` | `public function flushShared(): self` | Clears all registered shared props. |
| `viewData` | `public function viewData(string\|array $key, mixed $value = null): self` | Shares server-only PHP variables (`$locale`, `$head`, `$scripts`, `$styles`, etc., including `Closure($pageObject)` resolvers) with the Root View (`_inertia.php`). |
| `withViewData` | `public function withViewData(string\|array $key, mixed $value = null): self` | Fluent alias for `viewData()`. |
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
| `getActiveResponse` | `public function getActiveResponse(): ?PageResponse` | Returns the active `PageResponse` for the current request, if any. |
| `setSsrGateway` | `public function setSsrGateway(SsrGatewayInterface $gateway): self` | Overrides the SSR Gateway implementation (`Totoglu\Inertia\Ssr\HttpGateway` by default). |
| `clearOnceCacheForPage` | `public function clearOnceCacheForPage(Page $page, string $eventMethod = 'saved'): void` | Invalidates `OnceProp` `WireCache` entries according to `$page->template->cacheExpire`. |
| `isDemoMode` | `public function isDemoMode(): bool` | Returns `true` when `$config->demo = true` is active. |
| `typeGenerator` | `public function typeGenerator(): TypeGenerator` | Returns a `TypeGenerator` bound to this module. |
| `version` | `public function version(string\|Closure\|null $version): self` | Overrides the asset version string or resolver (defaults to Vite `manifest.json` hash). |
| `getVersion` | `public function getVersion(): string` | Resolves and returns the active asset version string. |

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
   `$inertia->sharedViewData` → `$page->inertiaViewData($pageObject)` → `$page->inertiaWithViewData($pageObject)` → `$process->inertiaViewData($pageObject)` → `$process->inertiaWithViewData($pageObject)` → `$pageResponse->viewData()`. List keys `scripts` and `styles` merge additively with deduplication; `head` accepts `string` or `list<string>` and merges with SSR `<head>` output.

## `Totoglu\Inertia\Provider\ProviderRegistry` & DataProviders

| Class / Method | Signature | Description |
|---|---|---|
| `ProviderRegistry::providePage` | `public function providePage(Page $page): array` | Resolves the matching `TemplateDataProvider` for `$page->template` and returns the serialized `Page` array (`id`, `name`, `title`, `url`, `httpUrl`, `template`, `created`, `modified`, field values, and `extraData()`). |
| `ProviderRegistry::provideUser` | `public function provideUser(User $user): array` | Serializes `$user` via the active `UserDataProvider`. |
| `ProviderRegistry::resolveTemplateProvider` | `public function resolveTemplateProvider(string\|Template $template): TemplateDataProvider` | Returns the specific `TemplateDataProvider` for `$template` or the wildcard `['*']` fallback. |
| `ProviderRegistry::resolveFieldProvider` | `public function resolveFieldProvider(Field\|object\|string $target): FieldDataProvider` | Resolves by `field:<name>` override, `InertiaFieldtypeInterface` implementation (`FieldtypeFieldDataProvider`), exact `Fieldtype` / object class name, or `is_a()` inheritance fallback to `ScalarFieldDataProvider`. |
| `TemplateDataProvider::templates` | `public function templates(): array` | Target template names (e.g. `['article']` or `['*']`). |
| `TemplateDataProvider::excludedFields` | `public function excludedFields(): array` | Field names to omit from serialization and `.d.ts` generation. |
| `TemplateDataProvider::extraData` | `protected function extraData(Page $page): array` | Computed key-value pairs appended to the serialized `Page` array. |
| `TemplateDataProvider::extraTsTypes` | `public function extraTsTypes(Template $template): array` | TypeScript property types (`['summary' => 'string']`) matching `extraData()`. |
| `FieldDataProvider::supports` | `public function supports(): array` | Supported `Fieldtype` class names, `field:<name>` keys, or value class names. |
| `FieldDataProvider::transform` | `public function transform(mixed $value, ?Field $field = null, ?Page $page = null): mixed` | Transforms a runtime field/object value into a JSON-safe structure. |
| `FieldDataProvider::phpType` | `public function phpType(Field $field): string` | PHPDoc `@property` type for `site/classes/*Page.php`. |
| `FieldDataProvider::tsType` | `public function tsType(Field $field): string` | TypeScript property type for `inertia.d.ts`. |
| `FieldDataProvider::tsDefinitions` | `public function tsDefinitions(?Field $field = null): array` | Auxiliary TypeScript interfaces (`array<string, string>`) emitted into `inertia.d.ts`. |
| `MediaFieldDataProvider::formatPageimage` | `public static function formatPageimage(Pageimage $image): array` | Serializes `Pageimage` (`name`, `url`, `httpUrl`, `description`, `ext`, `filesize`, `width`, `height`, `focus: {top, left, zoom}`), automatically appending `srcset`, `sizes`, `sources`, `lqip`, `dominantColor`, `palette`, `colorTheme`, and `imageHash` when `InterventionImage` is installed. |
| `MediaFieldDataProvider::isInterventionImageInstalled` | `public static function isInterventionImageInstalled(?ProcessWire $wire = null): bool` | Returns `true` when the `InterventionImage` module is installed in ProcessWire. |

## Extendable Base Classes & Contracts (`Totoglu\Inertia`)

### 1. `Totoglu\Inertia\ProcessInertia`
Abstract `Process` base class (`extends ProcessWire\Process`, uses `InteractWithInertia`). Returning `$this->inertiaRender('Component', $props)` from `___execute*()` intercepts `ProcessController::execute` and `Page::render` to serve either `X-Inertia: true` JSON or the full-page admin Root View.

### 2. `Totoglu\Inertia\MarkupInertia`
Abstract `ModuleJS` base class (`extends ProcessWire\ModuleJS implements InertiaSerializable`, uses `InteractWithInertia`):
- `inertiaComponent(): string` — Frontend component identifier (defaults to class name without `Markup` prefix).
- `___render(array $props = []): string` — Calls `renderReady()`, enqueues island assets, and outputs `<div class="pw-inertia-island" data-inertia-island="..." data-props="..."></div>`.
- `toInertiaProp(): array` — Returns `['component' => $this->inertiaComponent(), 'props' => ...]` when passed as a prop into a parent `PageResponse`.

### 3. `Totoglu\Inertia\InputfieldInertia`
Abstract `Inputfield` base class (`extends ProcessWire\Inputfield implements InertiaSerializable`, uses `InteractWithInertia`):
- `___render(): string` / `___renderValue(): string` — Outputs `<input type="hidden" data-inertia-input="true">` paired with `<div class="pw-inertia-inputfield-island" ...>`.
- `___processInput(WireInputData $input): self` — Decodes JSON or scalar submissions into `$this->val(...)` and tracks changes.
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
| `with` | `public function with(string\|array $key, mixed $value = null): self` | Adds or merges component-scoped props into the response. |
| `forPage` | `public function forPage(?Page $page): self` | Binds a ProcessWire `Page` context to the response (called automatically by `$page->inertiaRender()`). |
| `getPage` | `public function getPage(): ?Page` | Returns the bound `Page` instance, if any. |
| `forProcess` | `public function forProcess(?Process $process): self` | Binds a ProcessWire `Process` context to the response (called automatically by `$process->inertiaRender()`). |
| `getProcess` | `public function getProcess(): ?Process` | Returns the bound `Process` instance, if any. |
| `flash` | `public function flash(string\|array $key, mixed $value = null): self` | Flashes custom key-value data onto this response's `page.flash` object. |
| `withViewData` | `public function withViewData(string\|array $key, mixed $value = null): self` | Passes server-only PHP variables to `_inertia.php`, merging `scripts` and `styles` lists additively. |
| `viewData` | `public function viewData(string\|array\|null $key = null, mixed $value = null): array\|self` | Returns all response Root View variables when called with no arguments, or delegates to `withViewData()`. |
| `getViewData` | `public function getViewData(?string $key = null, mixed $default = null): mixed` | Retrieves a response Root View variable by key or all response Root View variables. |
| `rootView` | `public function rootView(string $view): self` | Overrides the Root View template filename or confined path for this response. |
| `url` | `public function url(string $url): self` | Overrides the `url` field of the resolved Page Object. |
| `encryptHistory` | `public function encryptHistory(bool $encrypt = true): self` | Sets `encryptHistory` on this response. |
| `clearHistory` | `public function clearHistory(bool $clear = true): self` | Sets `clearHistory` on this response. |
| `preserveFragment` | `public function preserveFragment(bool $preserve = true): self` | Sets `preserveFragment` on this response. |
| `withoutSsr` | `public function withoutSsr(bool $withoutSsr = true): self` | Bypasses the SSR Gateway for this response. |
| `toPageObject` | `public function toPageObject(): array` | Resolves and returns the Inertia v3 Page Object array. |
| `toJson` | `public function toJson(): string` | Encodes the resolved Page Object as a JSON string. |
| `toOutput` | `public function toOutput(): string` | Renders either the JSON Page Object (`X-Inertia: true`) or the HTML Root View (`_inertia.php`). |

## CLI Commands (`vendor/bin/wire`)

| Command | Signature | Description |
|---|---|---|
| `inertia:install` | `php vendor/bin/wire inertia:install [--stack=vue\|react\|svelte] [--template=inertia] [--force] [--json]` | Publishes `_inertia.php`, `assets/js/inertia.ts`, starter component, Custom Page Class, template controller, and `inertia.d.ts`. |
| `inertia:types` | `php vendor/bin/wire inertia:types [--template=...] [--output=...] [--ts-only] [--php-only] [--controllers] [--force] [--json]` | Generates `site/classes/*Page.php` and `site/templates/assets/js/types/inertia.d.ts` from enabled templates and registered DataProviders. |
