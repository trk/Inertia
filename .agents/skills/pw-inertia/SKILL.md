---
name: pw-inertia
description: "Use when working with ProcessWire Inertia (Totoglu\\Inertia), structuring Custom Page Classes with InteractWithInertia (DefaultPage + [PascalCase]Page + $page->inertiaRender()), rendering Inertia.js v3 SPA components, extending ProcessInertia, MarkupInertia, or InputfieldInertia, implementing InertiaFieldtypeInterface or InertiaSerializable, configuring Hybrid or Full SPA template targeting ($inertia->forTemplates()), creating custom DataProviders in site/inertia/*Provider.php or hooking Inertia::registerProviders, using DeferredProp, MergeProp, OnceProp, ScrollProp, OptionalProp, or AlwaysProp, running wire inertia:types or wire inertia:install, or configuring SSR Gateway and _inertia.php."
category: frontend
risk: safe
source: processwire-boost
date_added: "2026-11-07"
metadata:
  version: 10
  inertia-version: 3
  processwire-version: ">=3.0.271"
  php-version: ">=8.4"
  triggers:
    - inertia
    - inertia v3
    - Totoglu\Inertia
    - ProcessWire\Inertia
    - InteractWithInertia
    - DefaultPage
    - ProcessInertia
    - MarkupInertia
    - InputfieldInertia
    - InertiaFieldtypeInterface
    - PageResponse
    - DeferredProp
    - MergeProp
    - OnceProp
    - ScrollProp
    - OptionalProp
    - AlwaysProp
    - TemplateDataProvider
    - FieldDataProvider
    - UserDataProvider
    - ProviderRegistry
    - TypeGenerator
    - _inertia.php
    - inertia:install
    - inertia:types
---

# ProcessWire Inertia (`Totoglu\Inertia`)

Standalone Inertia.js v3 server-side protocol adapter for ProcessWire (`site/modules/Inertia/`).

For full method signatures and class tables, read [`site/modules/Inertia/API.md`](file:///Users/iskender/Code/php-prod/processwire/site/modules/Inertia/API.md). For domain terms, read [`site/modules/Inertia/GLOSSARY.md`](file:///Users/iskender/Code/php-prod/processwire/site/modules/Inertia/GLOSSARY.md).

## 1. Execution Workflow & Completion Criteria

1. **Inspect active schema & modules:**
   - Run `pw_schema_read` or `php vendor/bin/wire template:info <template> --json` to verify templates and fields.
   - Verify `Inertia` and `Vite` are installed via `pw_module_list` or `php vendor/bin/wire module:list --json`.
   - _Criterion:_ Target template names, field types, and `$config->usePageClasses` state are confirmed.
2. **Structure Custom Page Classes (`site/classes/`) with `InteractWithInertia`:**
   - Create or update `site/classes/DefaultPage.php` (`extends Page`, `use InteractWithInertia;`) to centralize `inertiaWithData()` (`['page' => $this, 'browserTitle' => $this->browserTitle()]`) and `inertiaViewData(array $pageObject = [])` (`['locale' => $this->locale(), 'head' => [...]]`).
   - Create or update `site/classes/[PascalCase]Page.php` (`extends DefaultPage`) for each template, placing ProcessWire selector queries, helper methods, and template props inside `inertiaWithData(): array` (`[...parent::inertiaWithData(), ...]`).
   - _Criterion:_ Every Inertia-enabled template has a corresponding `[PascalCase]Page` class encapsulating its domain queries and props.
3. **Register Tier 3 DataProviders (`site/inertia/*Provider.php`) when customizing serialization:**
   - Place `*TemplateDataProvider.php` or `*FieldDataProvider.php` in `site/inertia/` under `namespace Totoglu\Inertia\Provider;` and run `php vendor/bin/wire inertia:types --json`.
   - _Criterion:_ `site/templates/assets/js/types/inertia.d.ts` reflects all template fields and `extraTsTypes()`.
4. **Write thin template controllers (`site/templates/{template}.php`):**
   - Return `$page->inertiaRender('Component/Name')` (or pass request-specific overrides in the second argument).
   - _Criterion:_ Template files contain no HTML layout markup and delegate prop assembly to the Custom Page Class.
5. **Verify protocol & build:**
   - Run `php vendor/bin/wire test site/modules/Inertia/tests/Unit/InertiaTest.php` and `npm --prefix site/templates run build`.
   - _Criterion:_ All Pest assertions pass and the Vite bundle compiles cleanly.

## 2. Custom Page Class Pattern (`DefaultPage` + `[PascalCase]Page` + Thin Controller)

### Base `site/classes/DefaultPage.php`

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

### Template Page Class (`site/classes/ArticlePage.php`)

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

### Thin Template Controller (`site/templates/article.php`)

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var ArticlePage $page */
return $page->inertiaRender('Articles/Show');
```

## 3. Shared State (`site/templates/_init.php`) & Prop Wrappers

Register cross-page `OnceProp` datasets and request-scoped shared props in `site/templates/_init.php` (or `site/ready.php`):

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var ProcessWire $wire */
$inertia = inertia();
$pages = $wire->pages;
$user = $wire->user;
$page = $wire->page;

$inertia->shareOnce('catalog', function () use ($pages): array {
	return [
		'totalArticles' => (int) $pages->count('template=article'),
	];
})->as('site_catalog')->until(3600);

$inertia->share([
	'auth.user' => fn(): ?User => $user->isLoggedin() ? $user : null,
	'adminContext' => fn(): array => [
		'isLoggedIn' => (bool) $user->isLoggedin(),
		'canEdit' => $page instanceof Page && $page->id > 0 && $page->editable(),
		'editUrl' => $page instanceof Page && $page->id > 0 && $page->editable() ? (string) $page->editUrl() : null,
	],
]);
```

- **`DeferredProp`:** `$inertia->defer(fn() => ..., 'group')->once(until: 1800)`
- **`ScrollProp`:** `$inertia->scroll(fn(): PageArray => $pages->find('template=article, limit=12'))->matchOn('id')`
- **`MergeProp`:** `$inertia->merge($slice)->append('items', matchOn: 'id')` / `$inertia->prepend(...)` / `$inertia->deepMerge(...)`
- **`OnceProp`:** `$inertia->once(fn() => ...)->as('key')->until(3600)` (omitting `->until()` inherits `$page->template->cache_time` and invalidates `WireCache` via `$template->cacheExpire`)
- **`OptionalProp` & `AlwaysProp`:** `$inertia->optional(fn() => ...)` and `$inertia->always($value)`

## 4. 3-Tier DataProvider Pipeline (`site/inertia/*Provider.php`)

Runtime serialization (`$inertia->providers()->providePage($page)`) and static type generation (`php vendor/bin/wire inertia:types`) resolve providers with "Replace, Don't Layer" precedence:
1. **Tier 1 (Core):** `FieldtypeFieldDataProvider` (`InertiaFieldtypeInterface`), `ScalarFieldDataProvider`, `MediaFieldDataProvider` (always includes `focus: { top, left, zoom }` on `Pageimage`, and automatically appends `srcset`, `sizes`, `sources`, `lqip`, `dominantColor`, `palette`, `colorTheme`, `imageHash` + `InertiaImageFocus`, `InertiaImageSource`, `InertiaImageColorTheme` TypeScript interfaces when `InterventionImage` is installed), `PageReferenceFieldDataProvider`, `RepeaterFieldDataProvider`, `OptionsFieldDataProvider`, `TemplateDataProvider`, `UserDataProvider`.
2. **Tier 2 (Module Hook):** `Inertia::registerProviders` calling `$inertia->registerProvider(...)`.
3. **Tier 3 (Project Auto-Discovery):** `site/inertia/*Provider.php` under `namespace Totoglu\Inertia\Provider;`.

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
	 * @return array<string, mixed>
	 */
	protected function extraData(Page $page): array
	{
		return [
			'readingTime' => max(1, (int) ceil(str_word_count(strip_tags((string) $page->get('body'))) / 200)),
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

## 5. Extendable Module Base Classes & Fieldtype Contract

- **`Totoglu\Inertia\ProcessInertia`:** Extend for Inertia-powered admin `Process` modules (`return (string) $this->inertiaRender('Admin/View', [...]);`).
- **`Totoglu\Inertia\MarkupInertia`:** Extend for server-configured UI islands (`<div class="pw-inertia-island">`) or direct prop composition (`toInertiaProp()`).
- **`Totoglu\Inertia\InputfieldInertia`:** Extend for interactive admin/frontend Inputfields (`<input type="hidden" data-inertia-input="true">` + `toInertiaProp()`).
- **`Totoglu\Inertia\Contracts\InertiaFieldtypeInterface` & `InteractWithInertiaFieldtype`:** Implement on custom `Fieldtype` modules (`toInertiaFieldValue()`, `toInertiaPhpType()`, `toInertiaTsType()`, `toInertiaTsDefinitions()`).

## 6. Forms, Validation, Flash, Redirects & Guardrails

- **CSRF & JSON Sync:** `application/json` bodies populate `$input->post` automatically; validate CSRF via `$inertia->request->validateCsrf(true)`.
- **Errors, Flash & Redirects:** Use `$inertia->backWithErrors($errors, $bag, $fallbackUrl, true)` for validation failures, `$inertia->flash($key, $value)` for top-level `page.flash`, `$inertia->redirect($url)` for internal `409 X-Inertia-Redirect` / `303 See Other` redirects, and `$inertia->location($url)` for external `409 X-Inertia-Location` redirects.
- **Demo Mode (`$config->demo = true`):** Automatically blocks `POST`, `PUT`, `PATCH`, and `DELETE` requests, purges request globals, and downgrades `$inertia->request->method()` to `'GET'`.

| Mistake to Avoid | Required Pattern |
|---|---|
| Putting complex selector queries and prop arrays directly inside `site/templates/{template}.php` | Encapsulate queries and props in `site/classes/[PascalCase]Page::inertiaWithData()` and keep `{template}.php` as `return $page->inertiaRender('Component');` |
| Mutating `$config->useMarkupRegions = false` at runtime in Hybrid mode | Use `$inertia->forTemplates([...])`; `Inertia` sets `$template->noAppendTemplateFile = true` per enabled template |
| Adding `toInertiaProp()` arrays to every `site/classes/*Page.php` class | Let `TemplateDataProvider` serialize `$this` (`'page' => $this`) and use `site/inertia/*TemplateDataProvider.php` for `extraData()` + `extraTsTypes()` |
| Rendering legacy `<div id="app" data-page="...">` | Call `<?= $inertia->app($pageObject) ?>` in `_inertia.php` (`<div id="app"></div>` + `<script type="application/json" data-page="app">`) |
| Accessing `$this->pages` or `$this->sanitizer` inside Custom Page Classes | Use `$this->wire()->pages` and `$this->wire()->sanitizer` |
