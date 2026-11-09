# 0001. Standalone Inertia v3 Protocol Lifecycle and `InteractWithInertia` Host Binding

Status: accepted

## Context

ProcessWire defaults to a server-rendered template lifecycle (`_init.php` → `{template}.php` → `_main.php` with Markup Regions) and native `$input->post` form handling. Integrating Inertia.js v3 requires resolving four architectural constraints:

1. **Standalone Boundary:** `ProcessWire\Inertia` (`Totoglu\Inertia`) must depend only on ProcessWire core and `Vite` (`trk/processwire-vite`), with zero coupling to external modules.
2. **Coexistence with Classic ProcessWire Templates:** The module must not mutate `$config->useMarkupRegions` at runtime. Users target specific templates (`mode = 'selected'`, `enabledTemplates`, or `$inertia->forTemplates([...])`) or all frontend templates (`mode = 'all'`, `$inertia->forAllTemplates()`), and the module sets ProcessWire's native `$template->noAppendTemplateFile = true` on enabled templates to skip `_main.php`.
3. **Custom Page Class & Admin Module Context Binding:** Putting prop assembly, selector queries, and `<head>` metadata inside `site/templates/{template}.php` scatters domain logic across procedural files. Custom Page Classes (`site/classes/DefaultPage.php` and `site/classes/[PascalCase]Page.php`) and admin `Process` modules (`ProcessInertia`) need a first-class way to contribute default props (`inertiaWithData()`), shared props (`inertiaShared()`), and Root View variables (`inertiaViewData()`, `inertiaWithViewData()`, `inertiaRootView()`) directly to the active `PageResponse`.
4. **Inertia v3 Mount & SSR Merge:** Initial full-page loads mount via `<div id="app"></div>` paired with `<script type="application/json" data-page="app">{...}</script>` inside `_inertia.php`, merging Root View `$head` strings/arrays with SSR Gateway output (`Totoglu\Inertia\Ssr\HttpGateway`).

## Decision

1. **Template Targeting & `Page::render` Interception:**
   - In `ready()` and `hookBeforePageRender()`, if `$inertia->shouldHandlePage($page)` is `true` (or when `$inertia->render()` is called), `Inertia` sets `$page->template->noAppendTemplateFile = true`.
   - In `hookAfterPageRender()`:
     - Requests with `X-Inertia: true` emit `Content-Type: application/json`, `X-Inertia: true`, and `Vary: X-Inertia` and return the JSON `Page Object`.
     - Initial browser loads render the Root View (`_inertia.php`).

2. **`InteractWithInertia` Host Binding (`Page`, `Process`, `Markup`, `Inputfield`):**
   - `Totoglu\Inertia\InteractWithInertia` provides `$this->inertiaRender($component, $props)`, which creates a `PageResponse` and binds `$response->forPage($this)` (when `$this instanceof Page`) or `$response->forProcess($this)` (when `$this instanceof Process`).
   - During `Inertia::doBuildPageObject()`, props merge in strict precedence:
     `baselineProps` (`errors`, `flash`, `csrf`) → `$inertia->sharedProps` → `$page->inertiaShared()` → `$process->inertiaShared()` → nested `InertiaSerializable::inertiaShared()` → `$page->inertiaWithData()` → `$process->inertiaWithData()` → `$pageResponse->rawProps()`.
   - During `Inertia::renderRootView()`, Root View variables merge via `PropResolver::mergeViewData()`:
     `$inertia->sharedViewData` → `$page->inertiaViewData($pageObject)` → `$page->inertiaWithViewData($pageObject)` → `$process->inertiaViewData($pageObject)` → `$process->inertiaWithViewData($pageObject)` → `$pageResponse->viewData()`.
   - `ProcessInertia`, `MarkupInertia`, and `InputfieldInertia` extend native ProcessWire module classes (`Process`, `ModuleJS`, `Inputfield`) with `InteractWithInertia` (and `InertiaSerializable` on `MarkupInertia` / `InputfieldInertia`) so admin modules, UI islands, and form fields work identically in both classic and full-page Inertia contexts.

3. **Inertia v3 Prop Engine (`PropResolver`):**
   - Evaluates `OptionalProp`, `DeferredProp` (groups, merge chaining, `->once()`), `MergeProp` (`Append`, `Prepend`, `Deep`, nested path targeting, `matchOn`, `->once()`), `OnceProp` (`onceProps` metadata, `->as()`, `->fresh()`, `->until()`, `$inertia->shareOnce()`, plus automatic `$page->template->cache_time` inheritance and `$template->cacheExpire` `WireCache` invalidation), `ScrollProp` (`scrollProps` metadata + `PaginatedArray` normalization), and `AlwaysProp`.
   - Emits top-level `flash` and `sharedProps` (for Instant Visits) and preserves session-backed flash/errors during `Purpose: prefetch` requests.

4. **JSON Request Bridge, CSRF, `303` / `409` Redirects, and Demo Mode:**
   - Parses `application/json` payloads on `POST`, `PUT`, `PATCH`, and `DELETE` into `$input->post` (`syncJsonToPost`) and validates ProcessWire `$session->CSRF` tokens via form fields, `X-CSRF-TOKEN`, and `X-XSRF-TOKEN`.
   - Downgrades post-mutation `Session::redirect` status codes to `303 See Other`, supports `409 Conflict` (`X-Inertia-Redirect` and `X-Inertia-Location`) redirects, and blocks state-mutating requests when `$config->demo = true`.
