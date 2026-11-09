# 0002. 3-Tier DataProvider Pipeline (`Totoglu\Inertia\Provider`) and `InertiaFieldtypeInterface`

Status: accepted

## Context

Passing ProcessWire `Page`, `Field`, or `User` instances into Inertia props (`'page' => $this` inside `DefaultPage::inertiaWithData()`, or `'user' => $user`) and generating TypeScript definitions (`inertia.d.ts`) + Custom Page Classes (`site/classes/*Page.php`) requires a decoupled serialization and type-inference architecture:

1. **Zero Core Coupling to Project Templates or Third-Party Fieldtypes:** Hardcoding template-specific fields or third-party `Fieldtype` classes inside `PropResolver` or `TypeGenerator` couples the module to external code.
2. **Keeping Custom Page Classes Clean Domain Models:** `site/classes/*Page.php` classes should encapsulate domain behavior (`inertiaWithData()`, `inertiaViewData()`, selector queries) and `@property` PHPDoc annotations rather than generated `toInertiaProp()` field-mapping boilerplate.

## Decision

We unify runtime serialization and static PHP/TypeScript code generation under `Totoglu\Inertia\Provider\ProviderRegistry`:

1. **Dual Runtime + Type-Generation Providers:**
   - **`FieldDataProvider` (`Totoglu\Inertia\Provider\FieldDataProvider`):** Declares supported `Fieldtype` names, `field:<name>` overrides, or value class names (`supports()`), transforms runtime values (`transform(mixed $value, ?Field $field = null, ?Page $page = null)`), and reports PHPDoc types (`phpType(Field $field)`) and TypeScript types/interfaces (`tsType(Field $field)`, `tsDefinitions(?Field $field = null)`).
   - **`TemplateDataProvider` (`Totoglu\Inertia\Provider\TemplateDataProvider`):** Declares target template names (`templates()`, with `['*']` as default fallback), serializes base page properties alongside `fieldgroup` fields via registered `FieldDataProvider` instances, appends computed `extraData(Page $page)`, and declares matching TypeScript property types via `extraTsTypes(Template $template)`.
   - **`UserDataProvider` (`Totoglu\Inertia\Provider\UserDataProvider`):** Serializes `ProcessWire\User` instances (`id`, `name`, `email`, `roles`, `isLoggedin`, `isSuperuser`, plus non-system custom fields on the `user` template) while stripping credentials and system fields, and emits the `InertiaUser` TypeScript interface.

2. **Native Fieldtype Contract (`InertiaFieldtypeInterface` & `InteractWithInertiaFieldtype`):**
   - Custom `Fieldtype` modules can implement `Totoglu\Inertia\Contracts\InertiaFieldtypeInterface` (using `Totoglu\Inertia\InteractWithInertiaFieldtype`) to define `toInertiaFieldValue()`, `toInertiaPhpType()`, `toInertiaTsType()`, and `toInertiaTsDefinitions()` directly on the `Fieldtype`.
   - The built-in `FieldtypeFieldDataProvider` automatically bridges any `Fieldtype` implementing `InertiaFieldtypeInterface` into `ProviderRegistry`.

3. **Three-Tier Registration with "Replace, Don't Layer" Precedence:**
   - **Tier 1 (Built-in Core Providers in `src/Provider/`):** `FieldtypeFieldDataProvider`, `ScalarFieldDataProvider` (applying configured `Textformatter` modules and decoding entity-encoded plain text), `MediaFieldDataProvider` (serializing `Pageimage` with `focus` and automatically enriching with `InterventionImage` responsive `srcset`, `sizes`, `sources`, `lqip`, `dominantColor`, `palette`, `colorTheme`, and `imageHash` metadata + TypeScript interfaces `InertiaImageFocus`, `InertiaImageSource`, `InertiaImageColorTheme` when `InterventionImage` is installed), `PageReferenceFieldDataProvider` (shallow `InertiaPageBase` serialization with cycle guards), `RepeaterFieldDataProvider` (serializing `FieldtypeRepeater` / `FieldtypeRepeaterMatrix` items and emitting `[PascalFieldName]RepeaterItem` interfaces), `OptionsFieldDataProvider`, `TemplateDataProvider`, and `UserDataProvider`.
   - **Tier 2 (Module Hook `Inertia::___registerProviders`):** External modules hook `Inertia::registerProviders` and call `$inertia->registerProvider(...)`.
   - **Tier 3 (Project Auto-Discovery in `site/inertia/*Provider.php`):** Any `*Provider.php` class in `site/inertia/` under `namespace Totoglu\Inertia\Provider;` is discovered and registered last, replacing lower-tier providers for the same fieldtype, template, or user slot.
