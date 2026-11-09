# ProcessWire Inertia Glossary (`Totoglu\Inertia`)

Domain vocabulary for `ProcessWire\Inertia` (`site/modules/Inertia`).

## Protocol & Lifecycle

**Page Object**:
The Inertia v3 JSON envelope (`component`, `props`, `url`, `version`, `flash`, `sharedProps`, `deferredProps`, `mergeProps`, `deepMergeProps`, `prependProps`, `matchPropsOn`, `onceProps`, `scrollProps`, `encryptHistory`, `clearHistory`, `preserveFragment`) exchanged between ProcessWire and the client router.
_Avoid_: View model, API response, state payload

**Root View**:
The PHP shell template (`site/templates/_inertia.php`) rendered only on an initial full-page browser load to output `<head>`, Vite asset tags, and `$inertia->app($pageObject)` (`<div id="app"></div>` + `<script type="application/json" data-page="app">...</script>`).
_Avoid_: Layout file, master template, `_main.php`

**PageResponse**:
The fluent response builder created by `$page->inertiaRender('Component', $props)`, `$inertia->render('Component', $props)`, or `inertia('Component', $props)` representing the active Inertia response for the current request.
_Avoid_: View, Controller response, JSON response

**Asset Version**:
The deterministic hash string (derived from Vite `manifest.json` or a custom callback) compared against `X-Inertia-Version` to trigger a `409 Conflict` (`X-Inertia-Location`) reload when frontend assets change.
_Avoid_: Cache buster, build number

## Custom Page Class Context & Host Modules

**InteractWithInertia**:
The trait (`Totoglu\Inertia\InteractWithInertia`) attached to Custom Page Classes (`site/classes/*Page.php`), `ProcessInertia`, `MarkupInertia`, and `InputfieldInertia` that binds host-scoped props (`inertiaShared()`, `inertiaWithData()`, `with()`) and Root View variables (`inertiaViewData()`, `inertiaWithViewData()`, `inertiaRootView()`) to `PageResponse` via `$this->inertiaRender()`.
_Avoid_: Page controller, view presenter

**DefaultPage**:
The project-level base Custom Page Class (`site/classes/DefaultPage.php extends Page`) using `InteractWithInertia` that centralizes `inertiaWithData()` (`['page' => $this, 'browserTitle' => ...]`) and `inertiaViewData()` (`['locale' => ..., 'head' => [...]]`) across all template-specific `[PascalCase]Page` subclasses.
_Avoid_: Base controller, layout class

**ProcessInertia**:
The abstract admin module base class (`Totoglu\Inertia\ProcessInertia extends ProcessWire\Process`) using `InteractWithInertia` to serve full-page (`inertiaFullPageAdmin(): true`) or embedded Inertia admin screens.
_Avoid_: Admin controller

**MarkupInertia**:
The abstract markup module base class (`Totoglu\Inertia\MarkupInertia extends ProcessWire\ModuleJS implements InertiaSerializable`) using `InteractWithInertia` to render `<div class="pw-inertia-island">` containers on classic pages or serialize as `{ component, props }` inside a parent `PageResponse`.
_Avoid_: Partial view, widget helper

**InputfieldInertia**:
The abstract inputfield base class (`Totoglu\Inertia\InputfieldInertia extends ProcessWire\Inputfield implements InertiaSerializable`) using `InteractWithInertia` to render synchronized `<input type="hidden" data-inertia-input="true">` island mounts in classic forms and serialize into `InputfieldWrapper` prop trees on full-page Inertia responses.
_Avoid_: Form widget

## Props & Evaluation

**Shared Prop**:
Data registered via `$inertia->share()`, `$inertia->shareOnce()`, or `InteractWithInertia::inertiaShared()` that is merged into `props` and announced in top-level `sharedProps` for Inertia v3 Instant Visits.
_Avoid_: Global variable, view composer

**OptionalProp**:
A lazy property (`$inertia->optional(fn() => ...)`) excluded from standard visits and evaluated only when explicitly requested in `X-Inertia-Partial-Data`.
_Avoid_: Nullable prop, hidden field

**DeferredProp**:
An asynchronous property (`$inertia->defer(fn() => ..., $group)`) omitted from the initial visit payload and listed under `deferredProps` for background client fetching after initial render.
_Avoid_: Async job, queued prop

**MergeProp**:
A property wrapper (`$inertia->merge()`, `deepMerge()`, `prepend()`) instructing the client router to append, prepend, or deeply merge incoming array slices (at the root or nested paths via `->append('data', matchOn: 'id')`) into existing client state.
_Avoid_: Paginated array

**OnceProp**:
A property wrapper (`$inertia->once()`, `$inertia->shareOnce()`, or `->once()` chained on `DeferredProp`, `MergeProp`, and `OptionalProp`) evaluated once and remembered across client navigations (`onceProps` + `X-Inertia-Except-Once-Props`), backed server-side by `WireCache` with automatic `$page->template->cacheExpire` invalidation when `->until()` is omitted.
_Avoid_: Static cache

**ScrollProp**:
An infinite scroll property wrapper (`$inertia->scroll(...)`) that normalizes ProcessWire `PaginatedArray` / `PageArray` into `<prop>.data` merge targets and emits `scrollProps` pagination metadata (`pageName`, `previousPage`, `nextPage`, `currentPage`, `reset`).
_Avoid_: Paginator, cursor array

**AlwaysProp**:
A property wrapper (`$inertia->always()`) evaluated and included on every response, bypassing `X-Inertia-Partial-Data` and `X-Inertia-Partial-Except` filters.
_Avoid_: Required prop

**ErrorBag**:
The validation error map flashed into `$session` via `$inertia->withErrors()` and resolved as an `AlwaysProp` under `props.errors` (scoped by `X-Inertia-Error-Bag` when present).
_Avoid_: Notice list, form exceptions

## Data Providers & Serialization

**DataProvider**:
An adapter registered in `ProviderRegistry` across three tiers (Tier 1 built-in, Tier 2 `Inertia::registerProviders` hook, Tier 3 `site/inertia/*Provider.php` auto-discovery) using Replace-Don't-Layer precedence for both runtime prop serialization and static PHP/TypeScript type generation.
_Avoid_: View presenter, helper function

**TemplateDataProvider**:
A template-scoped provider (`Totoglu\Inertia\Provider\TemplateDataProvider`) bound to template names (or `['*']`) that serializes a `Page` by combining base attributes, `FieldDataProvider`-transformed fields, and `extraData(Page $page)`.
_Avoid_: Page serializer

**FieldDataProvider**:
A field-scoped provider (`Totoglu\Inertia\Provider\FieldDataProvider`) bound to `Fieldtype` names, `field:<name>` keys, or value classes that defines runtime `transform()`, PHPDoc `phpType()`, and TypeScript `tsType()` / `tsDefinitions()`.
_Avoid_: Field formatter

**UserDataProvider**:
The provider (`Totoglu\Inertia\Provider\UserDataProvider`) that serializes `ProcessWire\User` into a safe representation (`id`, `name`, `email`, `roles`, `isLoggedin`, `isSuperuser`, plus non-system custom fields) while stripping credentials and system fields.
_Avoid_: Auth model

**InertiaFieldtypeInterface**:
The contract (`Totoglu\Inertia\Contracts\InertiaFieldtypeInterface` + `Totoglu\Inertia\InteractWithInertiaFieldtype`) implemented directly by custom `Fieldtype` modules so `FieldtypeFieldDataProvider` delegates runtime serialization and static type generation to the `Fieldtype`.
_Avoid_: Custom field helper

**InertiaSerializable**:
The contract (`Totoglu\Inertia\Contracts\InertiaSerializable`) for objects (`MarkupInertia`, `InputfieldInertia`, or custom domain objects) that define an explicit `toInertiaProp(): array` payload.
_Avoid_: JsonSerializable, Arrayable

**SSR Gateway**:
The HTTP bridge (`Totoglu\Inertia\Ssr\HttpGateway`) that sends the resolved `Page Object` to a Node/Bun SSR server (`/render`) to pre-render initial `<head>` and `<body>` markup, falling back to CSR if unreachable.
_Avoid_: Node renderer
