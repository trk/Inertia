---
description: "Use when working with Totoglu\\Inertia (ProcessWire\\Inertia), structuring Custom Page Classes with InteractWithInertia (DefaultPage + [PascalCase]Page + $page->inertiaRender()), rendering Inertia.js v3 SPA components, extending ProcessInertia, MarkupInertia, or InputfieldInertia, implementing InertiaFieldtypeInterface or InertiaSerializable, creating DataProviders in site/inertia/*Provider.php or hooking Inertia::registerProviders, using DeferredProp, MergeProp, OnceProp, ScrollProp, OptionalProp, or AlwaysProp, or running wire inertia:types and wire inertia:install."
---

# AI Agent Guidelines for `ProcessWire\Inertia` (`Totoglu\Inertia`)

## Context Pointers

- **Method signatures, `InteractWithInertia` API, `PageResponse` methods, and `PropResolver` serialization rules:** read [`API.md`](API.md).
- **Domain terms (`Page Object`, `Root View`, `DefaultPage`, `DataProvider`, prop wrappers):** read [`GLOSSARY.md`](GLOSSARY.md).
- **Step-by-step implementation workflow, code templates, and anti-patterns:** read [`.agents/skills/pw-inertia/SKILL.md`](.agents/skills/pw-inertia/SKILL.md).
- **Architectural decisions (protocol lifecycle, SSR, and 3-tier DataProvider precedence):** read [`docs/adr/0001-inertia-v3-protocol-and-hybrid-lifecycle.md`](docs/adr/0001-inertia-v3-protocol-and-hybrid-lifecycle.md) and [`docs/adr/0002-data-provider-architecture.md`](docs/adr/0002-data-provider-architecture.md).

## Core Architectural Rules

1. **Standalone Module Boundary:**
   - `site/modules/Inertia/` depends only on ProcessWire core and `ProcessWire\Vite`. Keep zero references to project-specific templates or external third-party modules inside `site/modules/Inertia/`.
   - External modules and projects integrate through `InteractWithInertia`, `ProcessInertia`, `MarkupInertia`, `InputfieldInertia`, `InertiaFieldtypeInterface`, `InertiaSerializable`, `ProviderRegistry` (`Inertia::registerProviders` or `site/inertia/*Provider.php`), or `$inertia->addTransformer()`.

2. **Custom Page Class-First Architecture (`site/classes/` + `InteractWithInertia`):**
   - Enable `$config->usePageClasses = true` and place `use Totoglu\Inertia\InteractWithInertia;` on a base `site/classes/DefaultPage.php` (`extends Page`) that defines:
     - `inertiaWithData(): array` returning base component props (e.g., `['page' => $this, 'browserTitle' => $this->browserTitle()]`).
     - `inertiaViewData(array $pageObject = []): array` returning Root View (`_inertia.php`) PHP variables (`['locale' => $this->locale(), 'head' => [...]]`).
   - Template-specific classes (`site/classes/[PascalCase]Page.php extends DefaultPage`) encapsulate ProcessWire selector queries, domain methods, and template props by overriding `inertiaWithData(): array` with `[...parent::inertiaWithData(), ...]` (and optionally `inertiaShared(): array`, `inertiaWithViewData()`, or `inertiaRootView()`).
   - Keep `site/templates/{template}.php` controllers thin by returning `$page->inertiaRender('Component/Name')` (which binds `$response->forPage($page)` automatically). Pass request-specific overrides as the second argument `$page->inertiaRender('Component/Name', $props)` or via `$page->with($key, $value)`.

3. **3-Tier DataProvider Pipeline (`Totoglu\Inertia\Provider\ProviderRegistry`):**
   - Runtime serialization of `Page`, `Field`, and `User` objects and static generation of `site/classes/*Page.php` + `inertia.d.ts` run through `ProviderRegistry` using "Replace, Don't Layer" precedence:
     - **Tier 1 (Core):** `FieldtypeFieldDataProvider` (delegates to `Fieldtype` modules implementing `InertiaFieldtypeInterface`), `ScalarFieldDataProvider`, `MediaFieldDataProvider`, `PageReferenceFieldDataProvider`, `RepeaterFieldDataProvider`, `OptionsFieldDataProvider`, `TemplateDataProvider`, `UserDataProvider`.
     - **Tier 2 (Module Hooks):** `Inertia::registerProviders` hook calling `$inertia->registerProvider(...)`.
     - **Tier 3 (Project Auto-Discovery):** `site/inertia/*Provider.php` under `namespace Totoglu\Inertia\Provider;`.
   - Keep Custom Page Classes free of `toInertiaProp()` DTO arrays; use `TemplateDataProvider::extraData(Page $page)` and `extraTsTypes(Template $template)` in `site/inertia/*TemplateDataProvider.php` when extending serialized `Page` payloads.

4. **Template Targeting & Layout Control:**
   - Configure Inertia templates via module settings, `setting('inertia', [...])`, `$inertia->forTemplates([...])`, or `$inertia->forAllTemplates()`.
   - Leave `$config->useMarkupRegions` untouched at runtime; `Inertia` sets `$page->template->noAppendTemplateFile = true` on enabled templates before `PageRender` runs.

5. **Extendable Module Base Classes:**
   - **`ProcessInertia` (`Totoglu\Inertia\ProcessInertia`):** Admin `Process` base class using `InteractWithInertia` with full-page admin mode (`inertiaFullPageAdmin(): true`).
   - **`MarkupInertia` (`Totoglu\Inertia\MarkupInertia`):** `ModuleJS` base class implementing `InertiaSerializable` and `InteractWithInertia` for `<div class="pw-inertia-island">` rendering and prop composition.
   - **`InputfieldInertia` (`Totoglu\Inertia\InputfieldInertia`):** `Inputfield` base class implementing `InertiaSerializable` and `InteractWithInertia` for classic admin forms (`data-inertia-input="true"`) and full-page Inertia `InputfieldWrapper` prop trees.

6. **Translations & Verification:**
   - Chronological ProcessWire `LanguagePorter` CSV packs live in `languages/{en,tr,de,es,fr,ru}.csv` (`default,en,...` for `en.csv` and `en,{locale},...` for others).
   - Verify changes with `php vendor/bin/wire test site/modules/Inertia/tests/Unit/InertiaTest.php`.
