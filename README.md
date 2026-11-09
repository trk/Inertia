# ProcessWire Inertia (`Totoglu\Inertia`)

Inertia.js v3 server-side adapter for ProcessWire `>= 3.0.271` and PHP `>= 8.4`.

Build Vue 3, React, or Svelte applications on top of ProcessWire's native routing, Custom Page Classes (`site/classes/`), templates, fields, and permissions. Requires only ProcessWire core and `Vite` (`trk/processwire-vite`).

---

## Requirements & Installation

- **PHP:** `>= 8.4`
- **ProcessWire:** `>= 3.0.271`
- **Required Module:** `Vite` (`trk/processwire-vite`)

```bash
composer require trk/processwire-inertia
php vendor/bin/wire module:install Inertia --json
```

To scaffold the Root View (`site/templates/_inertia.php`), Vite entry (`site/templates/assets/js/inertia.ts`), starter component, Custom Page Class, and TypeScript definitions (`inertia.d.ts`):

```bash
php vendor/bin/wire inertia:install --stack=vue --template=home --json
```

---

## 1. Template Lifecycle & Activation Modes

In a classic ProcessWire site (`site/config.php`), requests run `_init.php` → `{template}.php` → `_main.php` (with Markup Regions).

Inertia replaces server-side layout merging (`_main.php`) with two response modes handled automatically during `Page::render`:
- **Initial Full-Page Visit:** Renders the minimal HTML shell (`site/templates/_inertia.php`) containing `<head>` tags, `vite()` assets, and `$inertia->app($pageObject)` (`<div id="app"></div>` + `<script type="application/json" data-page="app">{...}</script>`).
- **Subsequent XHR Visit (`X-Inertia: true`):** Skips HTML rendering and returns the JSON **Page Object** (`{ component, props, url, version, ... }`) with `X-Inertia: true` and `Vary: X-Inertia` headers.

For every Inertia-enabled template, the module sets `$template->noAppendTemplateFile = true` at runtime so `_main.php` is skipped without mutating `$config->useMarkupRegions`.

### Hybrid Mode (Mix Classic & Inertia Templates)
Keep your existing `site/config.php` untouched for classic templates and enable Inertia only on selected templates:

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

// In site/init.php or site/ready.php (or configure in Modules -> Site -> Inertia):
inertia()->forTemplates(['home', 'articles', 'article']);
```

### Full SPA Mode (All Frontend Templates)
When every frontend template renders through Inertia:

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

// In site/config.php:
$config->useMarkupRegions = false;
$config->appendTemplateFile = '';

// In site/init.php or site/ready.php:
inertia()->forAllTemplates();
```

---

## 2. Custom Page Class Architecture (`InteractWithInertia`)

Enabling `$config->usePageClasses = true` in `site/config.php` lets you place domain queries, default props (`inertiaWithData()`), shared props (`inertiaShared()`), and Root View metadata (`inertiaViewData()`) directly on your Custom Page Classes in `site/classes/`, keeping `site/templates/{template}.php` controllers to a single line.

### Step 1: Base `DefaultPage` (`site/classes/DefaultPage.php`)

Create a base page class using `Totoglu\Inertia\InteractWithInertia` to centralize `<html lang>`, `<title>`, `<meta>` tags, and the base `'page' => $this` prop:

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

use Totoglu\Inertia\InteractWithInertia;

/**
 * @property string $title
 */
class DefaultPage extends Page
{
	use InteractWithInertia;

	public function locale(): string
	{
		$wire = $this->wire();
		$languages = $wire->languages;
		$user = $wire->user;

		if ($languages instanceof Languages && $user instanceof User && $user->language instanceof Language) {
			$code = trim((string) $user->language->name);
			if ($code !== '' && $code !== 'default') {
				return $code;
			}
		}

		return 'en';
	}

	public function browserTitle(): string
	{
		return (string) $this->title;
	}

	public function metaDescription(): string
	{
		return (string) $this->get('summary');
	}

	/**
	 * Default component props merged automatically before explicit inertiaRender() props.
	 *
	 * @return array<string, mixed>
	 */
	public function inertiaWithData(): array
	{
		return [
			'page' => $this,
			'browserTitle' => $this->browserTitle(),
		];
	}

	/**
	 * Server-only PHP variables ($locale, $head) passed to site/templates/_inertia.php.
	 *
	 * @param array<string, mixed> $pageObject
	 * @return array<string, mixed>
	 */
	public function inertiaViewData(array $pageObject = []): array
	{
		$sanitizer = $this->wire()->sanitizer;
		$props = isset($pageObject['props']) && is_array($pageObject['props'])
			? $pageObject['props']
			: [];

		$resolvedTitle = isset($props['browserTitle']) && is_string($props['browserTitle']) && $props['browserTitle'] !== ''
			? $props['browserTitle']
			: $this->browserTitle();

		$safeTitle = $sanitizer->entities($resolvedTitle);
		$safeDesc = $sanitizer->entities($this->metaDescription());
		$safeUrl = $sanitizer->entities((string) $this->httpUrl);

		return [
			'locale' => $this->locale(),
			'head' => [
				"<title>{$safeTitle}</title>",
				"<meta name=\"description\" content=\"{$safeDesc}\">",
				"<meta property=\"og:title\" content=\"{$safeTitle}\">",
				"<meta property=\"og:url\" content=\"{$safeUrl}\">",
			],
		];
	}
}
```

### Step 2: Template Page Class (`site/classes/ArticlePage.php`)

Extend `DefaultPage` for each template to encapsulate ProcessWire selector queries and template-specific props:

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * Custom Page Class for the `article` template.
 *
 * @property string $title
 * @property string $summary
 * @property string $body
 * @property Pageimages $images
 */
class ArticlePage extends DefaultPage
{
	public function browserTitle(): string
	{
		return "{$this->title} — Journal";
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function relatedArticles(int $limit = 4): array
	{
		$providers = $this->inertia()->providers();
		$items = [];

		foreach ($this->wire()->pages->find("template=article, id!={$this->id}, sort=-created, limit={$limit}") as $item) {
			if ($item instanceof Page) {
				$items[] = $providers->providePage($item);
			}
		}

		return $items;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function inertiaWithData(): array
	{
		return [
			...parent::inertiaWithData(),
			'relatedArticles' => $this->inertia()->defer(fn(): array => $this->relatedArticles()),
		];
	}
}
```

### Step 3: Thin Template Controller (`site/templates/article.php`)

In the template file, call `$page->inertiaRender()` to bind `$page` and render the frontend component:

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var ArticlePage $page */
return $page->inertiaRender('Articles/Show');
```

Any extra props passed to `$page->inertiaRender('Articles/Show', ['extra' => 123])` (or pre-attached via `$page->with('extra', 123)`) are merged on top of `inertiaWithData()`.

---

## 3. Root View (`site/templates/_inertia.php`)

`_inertia.php` renders only on initial full-page loads. It receives `$inertia`, `$pageObject`, `$locale`, `$head` (automatically combining SSR `<head>` output with `inertiaViewData()['head']`), and any custom variables registered via `viewData()`:

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

if (!defined('PROCESSWIRE')) {
	die();
}

/**
 * @var Inertia $inertia
 * @var Sanitizer $sanitizer
 * @var array<string, mixed> $pageObject
 * @var string $locale
 * @var string $head
 */
?>
<!DOCTYPE html>
<html lang="<?= $sanitizer->entities((string) ($locale ?? 'en')) ?>" class="h-full">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?= $head ?? '' ?>
	<?php
	if (function_exists(__NAMESPACE__ . '\vite')) {
		echo vite([
			'@assets/css/app.css',
			'@assets/js/inertia.ts',
		]);
	}
	?>
</head>
<body class="min-h-full antialiased">
	<?= $inertia->app($pageObject) ?>
</body>
</html>
```

---

## 4. Shared State & Inertia v3 Prop Types

### Global Shared Props (`site/templates/_init.php` or `site/ready.php`)

Use `$inertia->shareOnce()` for cross-navigation cached datasets (navigation trees, directory counts, filter presets) and `$inertia->share()` for request-scoped state:

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var ProcessWire $wire */
$inertia = inertia();
$pages = $wire->pages;
$user = $wire->user;
$page = $wire->page;

$inertia->shareOnce('navigation', function () use ($pages): array {
	$items = [];
	foreach ($pages->get('/')->children() as $item) {
		$items[] = [
			'title' => (string) $item->title,
			'url' => (string) $item->url,
		];
	}

	return $items;
})->as('site_navigation')->until(3600);

$inertia->share([
	'auth.user' => fn(): ?User => $user->isLoggedin() ? $user : null,
	'canEdit' => fn(): bool => $page instanceof Page && $page->id > 0 && $page->editable(),
]);
```

### Lazy, Deferred, Merge, Once & Infinite Scroll Props

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var Page $page */
/** @var Pages $pages */
$inertia = inertia();

return $inertia->render('Articles/Index', [
	'page' => $page,
	// Evaluated asynchronously in a background request after initial render:
	'stats' => $inertia->defer(fn(): array => [
		'total' => $pages->count('template=article'),
	], 'sidebar')->once(until: 1800),
	// Infinite scroll pagination with ProcessWire PaginatedArray:
	'articles' => $inertia->scroll(
		fn(): PageArray => $pages->find('template=article, limit=12, sort=-created')
	)->matchOn('id'),
	// Append/prepend/deepMerge slices across partial reloads:
	'activity' => $inertia->merge($activitySlice)->append('items', matchOn: 'id'),
	// Evaluated only when explicitly requested via partial reload `only`:
	'auditLog' => $inertia->optional(fn(): array => []),
	// Included on every visit even when excluded by partial reload filters:
	'permissions' => $inertia->always(['editable' => $page->editable()]),
]);
```

When `->until()` is omitted on an `OnceProp`, `Inertia` inherits `$page->template->cache_time` and invalidates `WireCache` automatically on `Pages::saved`, `Pages::trashed`, and `Pages::deleted` according to `$template->cacheExpire`.

---

## 5. 3-Tier DataProvider Pipeline (`site/inertia/`)

Serialization of `Page`, `Field`, and `User` objects at runtime and static generation of `site/classes/*Page.php` + `inertia.d.ts` are managed by `Totoglu\Inertia\Provider\ProviderRegistry`:

1. **Tier 1 (Built-in Core Providers):** `ScalarFieldDataProvider`, `MediaFieldDataProvider` (serializing `Pageimage` with `focus: { top, left, zoom }` and automatically enriching with `srcset`, `sizes`, `sources`, `lqip`, `dominantColor`, `palette`, `colorTheme`, and `imageHash` when [`trk/processwire-intervention-image`](https://github.com/trk/InterventionImage) is installed), `PageReferenceFieldDataProvider`, `RepeaterFieldDataProvider`, `OptionsFieldDataProvider`, `FieldtypeFieldDataProvider` (for `Fieldtype` modules implementing `InertiaFieldtypeInterface`), `TemplateDataProvider`, and `UserDataProvider`.
2. **Tier 2 (Module Hook):** Third-party modules hook `Inertia::registerProviders` and call `$inertia->registerProvider(...)`.
3. **Tier 3 (Project Auto-Discovery in `site/inertia/*Provider.php`):** Files in `site/inertia/` under `namespace Totoglu\Inertia\Provider;` are discovered automatically and override lower tiers.

### Example: Custom `TemplateDataProvider` (`site/inertia/ArticleTemplateDataProvider.php`)

```php
<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Provider;

use ProcessWire\Page;
use ProcessWire\Template;

final class ArticleTemplateDataProvider extends TemplateDataProvider
{
	/**
	 * @return list<string>
	 */
	public function templates(): array
	{
		return ['article'];
	}

	/**
	 * @return list<string>
	 */
	public function excludedFields(): array
	{
		return ['internal_notes'];
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function extraData(Page $page): array
	{
		$words = str_word_count(strip_tags((string) $page->get('body')));

		return [
			'readingTime' => max(1, (int) ceil($words / 200)),
		];
	}

	/**
	 * @return array<string, string>
	 */
	public function extraTsTypes(Template $template): array
	{
		return [
			'readingTime' => 'number',
		];
	}
}
```

### Example: Custom `FieldDataProvider` (`site/inertia/MapMarkerFieldDataProvider.php`)

```php
<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Provider;

use ProcessWire\Field;
use ProcessWire\MapMarker;
use ProcessWire\Page;

final class MapMarkerFieldDataProvider extends FieldDataProvider
{
	/**
	 * @return list<string>
	 */
	public function supports(): array
	{
		return ['FieldtypeMapMarker', MapMarker::class];
	}

	/**
	 * @return array{address: string, lat: float|null, lng: float|null, zoom: int}|null
	 */
	public function transform(mixed $value, ?Field $field = null, ?Page $page = null): ?array
	{
		if (!is_object($value)) {
			return null;
		}

		$lat = isset($value->lat) ? (float) $value->lat : 0.0;
		$lng = isset($value->lng) ? (float) $value->lng : 0.0;

		return [
			'address' => isset($value->address) ? trim((string) $value->address) : '',
			'lat' => abs($lat) > 0.0001 ? round($lat, 6) : null,
			'lng' => abs($lng) > 0.0001 ? round($lng, 6) : null,
			'zoom' => isset($value->zoom) ? (int) $value->zoom : 13,
		];
	}

	public function phpType(Field $field): string
	{
		return 'MapMarker|null';
	}

	public function tsType(Field $field): string
	{
		return 'InertiaMapMarker | null';
	}

	/**
	 * @return array<string, string>
	 */
	public function tsDefinitions(?Field $field = null): array
	{
		return [
			'InertiaMapMarker' => <<<'TS'
export interface InertiaMapMarker {
	address: string;
	lat: number | null;
	lng: number | null;
	zoom: number;
}
TS,
		];
	}
}
```

---

## 6. Extendable Module Base Classes & Fieldtype Contract

- **`Totoglu\Inertia\ProcessInertia`:** Abstract `Process` base class using `InteractWithInertia` for building full-page or embedded Inertia admin modules (`return (string) $this->inertiaRender('Admin/Dashboard', [...]);`).
- **`Totoglu\Inertia\MarkupInertia`:** Abstract `ModuleJS` base class implementing `InertiaSerializable` and `InteractWithInertia`. Renders `<div class="pw-inertia-island" data-inertia-island="..." data-props="..."></div>` in classic templates or serializes as `{ component, props }` when passed as a prop into a parent Inertia response.
- **`Totoglu\Inertia\InputfieldInertia`:** Abstract `Inputfield` base class implementing `InertiaSerializable` and `InteractWithInertia`. Renders a synchronized hidden `<input data-inertia-input="true">` and island mount node inside classic ProcessWire forms (`ProcessPageEdit`, `ProcessModule`), and serializes cleanly when an `InputfieldWrapper` is passed into a full-page Inertia response.
- **`Totoglu\Inertia\Contracts\InertiaFieldtypeInterface` & `InteractWithInertiaFieldtype`:** Implement on custom `Fieldtype` modules to define `toInertiaFieldValue()`, `toInertiaPhpType()`, `toInertiaTsType()`, and `toInertiaTsDefinitions()` directly on the Fieldtype class.

---

## 7. Forms, Validation, Flash & Redirects

`Inertia` automatically populates `$input->post` from `application/json` request bodies (`syncJsonToPost`), validates ProcessWire CSRF / `X-XSRF-TOKEN` headers, flashes validation errors to `props.errors`, exposes top-level `page.flash` alongside `props.flash`, and downgrades post-mutation redirects to `303 See Other`:

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

$wire->addHook('/contact/submit', function (HookEvent $e): mixed {
	$inertia = inertia();
	$inertia->request->validateCsrf(true);

	$email = $e->wire()->sanitizer->email((string) $inertia->request->input('email'));
	if ($email === '') {
		return $inertia->backWithErrors([
			'email' => __('Please enter a valid email address.', __FILE__),
		], bag: null, fallbackUrl: '/contact/', terminate: true);
	}

	$e->wire()->session->message(__('Your message has been sent!', __FILE__));
	$inertia->flash('toast', __('Contact form submitted', __FILE__));
	$inertia->preserveFragment();
	$e->wire()->session->redirect('/contact/');

	return null;
});
```

---

## 8. Configuration & CLI Commands

| Setting | Type | Default | Description |
|---|---|---|---|
| `mode` | `string` | `'selected'` | `'selected'` (Hybrid Mode) or `'all'` (Full SPA Mode). |
| `enabledTemplates` | `array<string>` | `[]` | Template names handled by Inertia when `mode === 'selected'`. |
| `disableAppendTemplateFile` | `bool` | `true` | Sets `$template->noAppendTemplateFile = true` on Inertia templates to skip `_main.php`. |
| `disablePrependTemplateFile` | `bool` | `false` | Sets `$template->noPrependTemplateFile = true` on Inertia templates when enabled. |
| `rootView` | `string` | `'_inertia.php'` | Root View template filename inside `site/templates/`. |
| `rootElementId` | `string` | `'app'` | Root mount container ID (`<div id="app">`). |
| `syncJsonToPost` | `bool` | `true` | Populates `$input->post` from `application/json` bodies on `POST/PUT/PATCH/DELETE`. |
| `autoShareFlash` | `bool` | `true` | Shares ProcessWire `$notices` under `props.flash` and top-level `page.flash`. |
| `autoShareCsrf` | `bool` | `true` | Shares ProcessWire CSRF token under `props.csrf` and emits the `XSRF-TOKEN` cookie. |
| `encryptHistory` | `bool` | `false` | Enables Inertia v3 browser history encryption (`encryptHistory: true`) by default. |
| `ssrEnabled` | `bool` | `false` | Dispatches initial visits to the SSR Gateway (`ssrUrl`). |
| `ssrUrl` | `string` | `'http://127.0.0.1:13714/render'` | HTTP endpoint of the Node/Bun SSR server. |
| `ssrTimeout` | `float` | `1.5` | SSR HTTP connection timeout in seconds before falling back to CSR. |
| `tracySupport` | `bool` | `true` | Registers `InertiaTracyPanel` in TracyDebugger when `$config->debug` is true. |
| `autoGenerateTypes` | `bool` | `true` | Regenerates `inertia.d.ts` when module settings or templates/fields are saved. |
| `autoScaffoldPageClasses` | `bool` | `true` | Scaffolds missing `site/classes/[PascalCase]Page.php` classes (never overwrites existing files). |
| `typeScriptOutputPath` | `string` | `'assets/js/types/inertia.d.ts'` | Output path for `inertia.d.ts` relative to `site/templates/`. |

### CLI Commands (`vendor/bin/wire`)

| Command | Options | Description |
|---|---|---|
| `php vendor/bin/wire inertia:install` | `--stack=vue\|react\|svelte`, `--template=<name>`, `--force`, `--json` | Publishes `_inertia.php`, `assets/js/inertia.ts`, starter component, Custom Page Class, template controller, and `inertia.d.ts`. |
| `php vendor/bin/wire inertia:types` | `--template=<name>`, `--output=<path>`, `--ts-only`, `--php-only`, `--controllers`, `--force`, `--json` | Generates `site/classes/*Page.php` and `site/templates/assets/js/types/inertia.d.ts` from enabled templates and registered DataProviders. |

---

## 9. Multi-Language Support & Security

- **Translations:** Ships with chronological ProcessWire `LanguagePorter` CSV packs in `languages/{en,tr,de,es,fr,ru}.csv`. Import via **Setup → Languages** or `$language->porter->importCsv()`.
- **CSRF Verification:** Validates `$session->CSRF` tokens via form fields, `X-CSRF-TOKEN`, and `X-XSRF-TOKEN` headers (`$inertia->request->validateCsrf(true)`).
- **Demo Mode (`$config->demo = true`):** Blocks state-changing requests (`POST`, `PUT`, `PATCH`, `DELETE`), purges `$_POST`, `$_FILES`, `$input->post`, and JSON bodies, downgrades `$inertia->request->method()` to `'GET'`, and disables disk type generation.
- **Safe User Serialization:** `UserDataProvider` strips `pass`, salts, tokens, and system fields before serializing `User` instances.
- **Root View Path Confinement:** Custom `rootView` paths are confined inside `$config->paths->templates` or `$config->paths->siteModules` via `realpath()` boundary checks.
