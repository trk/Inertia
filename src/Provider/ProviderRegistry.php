<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Provider;

use ProcessWire\Field;
use ProcessWire\Page;
use ProcessWire\Pagefile;
use ProcessWire\Pageimage;
use ProcessWire\Template;
use ProcessWire\User;
use ProcessWire\Wire;
use ReflectionClass;
use Totoglu\Inertia\Contracts\InertiaFieldtypeInterface;
use Totoglu\Inertia\Provider\Field\MediaFieldDataProvider;
use Totoglu\Inertia\Provider\Field\OptionsFieldDataProvider;
use Totoglu\Inertia\Provider\Field\PageReferenceFieldDataProvider;
use Totoglu\Inertia\Provider\Field\RepeaterFieldDataProvider;
use Totoglu\Inertia\Provider\Field\ScalarFieldDataProvider;

/**
 * Registry for `FieldDataProvider`, `TemplateDataProvider`, and `UserDataProvider` instances.
 *
 * Later registrations override earlier ones for the same `supports()` or `templates()` keys
 * (built-in defaults -> `Inertia::registerProviders` hook -> `site/inertia/*Provider.php`).
 */
final class ProviderRegistry extends Wire
{
	/** @var array<string, FieldDataProvider> */
	private array $fieldProviders = [];

	/** @var array<string, TemplateDataProvider> */
	private array $templateProviders = [];

	private UserDataProvider $userProvider;

	private FieldDataProvider $fallbackFieldProvider;

	private TemplateDataProvider $fallbackTemplateProvider;

	/**
	 * Track active Page IDs during `providePage()` serialization to prevent infinite recursion on circular Page graphs.
	 *
	 * @var array<int, true>
	 */
	private array $activePageIds = [];

	public function __construct()
	{
		parent::__construct();
		$this->resetToDefaults();
	}

	public function wired(): void
	{
		parent::wired();
		$this->wire($this->fallbackFieldProvider);
		$this->wire($this->fallbackTemplateProvider);
		$this->wire($this->userProvider);

		foreach ($this->fieldProviders as $provider) {
			$this->wire($provider);
		}

		foreach ($this->templateProviders as $provider) {
			$this->wire($provider);
		}
	}

	/**
	 * Reset registry to built-in core providers.
	 */
	public function resetToDefaults(): self
	{
		$this->fieldProviders = [];
		$this->templateProviders = [];

		$this->fallbackFieldProvider = new ScalarFieldDataProvider();
		$this->fallbackTemplateProvider = new TemplateDataProvider();
		$this->userProvider = new UserDataProvider();

		$this->register($this->fallbackFieldProvider);
		$this->register(new MediaFieldDataProvider());
		$this->register(new PageReferenceFieldDataProvider());
		$this->register(new RepeaterFieldDataProvider());
		$this->register(new OptionsFieldDataProvider());
		$this->register($this->fallbackTemplateProvider);
		$this->register($this->userProvider);

		return $this;
	}

	/**
	 * Register a `FieldDataProvider`, `TemplateDataProvider`, or `UserDataProvider` instance,
	 * overriding any existing provider registered for the same keys.
	 */
	public function register(
		FieldDataProvider|TemplateDataProvider|UserDataProvider $provider
	): self {
		$this->wire($provider);

		if ($provider instanceof FieldDataProvider) {
			$this->registerFieldProvider($provider);
		} elseif ($provider instanceof TemplateDataProvider) {
			$this->registerTemplateProvider($provider);
		} else {
			$this->registerUserProvider($provider);
		}

		return $this;
	}

	private function registerFieldProvider(FieldDataProvider $provider): void
	{
		$provider->setRegistry($this);

		foreach ($provider->supports() as $supportedKey) {
			$clean = trim($supportedKey);
			if ($clean === '') {
				continue;
			}
			unset($this->fieldProviders[$clean]);
			$this->fieldProviders[$clean] = $provider;
			if ($clean === '*') {
				$this->fallbackFieldProvider = $provider;
			}
		}
	}

	private function registerTemplateProvider(TemplateDataProvider $provider): void
	{
		foreach ($provider->templates() as $tplName) {
			$clean = trim($tplName);
			if ($clean === '') {
				continue;
			}
			$this->templateProviders[$clean] = $provider;
			if ($clean === '*') {
				$this->fallbackTemplateProvider = $provider;
			}
		}
	}

	private function registerUserProvider(UserDataProvider $provider): void
	{
		$this->userProvider = $provider;
	}

	/**
	 * Resolve the active `FieldDataProvider` for a ProcessWire `Field`.
	 */
	public function resolveFieldProvider(Field $field): FieldDataProvider
	{
		$fieldName = trim((string) $field->name);
		if ($fieldName !== '' && isset($this->fieldProviders['field:' . $fieldName])) {
			return $this->fieldProviders['field:' . $fieldName];
		}

		$typeObj = $field->type;
		if ($typeObj !== null) {
			$shortClass = $typeObj->className();
			if (isset($this->fieldProviders[$shortClass])) {
				return $this->fieldProviders[$shortClass];
			}

			$fqcn = $typeObj::class;
			if (isset($this->fieldProviders[$fqcn])) {
				return $this->fieldProviders[$fqcn];
			}

			if ($typeObj instanceof InertiaFieldtypeInterface || method_exists($typeObj, 'toInertiaFieldValue')) {
				return $this->createFieldtypeBridgeProvider($typeObj, $shortClass);
			}
		}

		return $this->fieldProviders['*'] ?? $this->fallbackFieldProvider;
	}

	/**
	 * Wrap an `InertiaFieldtypeInterface` (or duck-typed `Fieldtype`) inside a `FieldDataProvider` adapter.
	 */
	private function createFieldtypeBridgeProvider(object $fieldtype, string $shortClass): FieldDataProvider
	{
		$provider = new class($fieldtype, $shortClass) extends FieldDataProvider {
			public function __construct(
				private readonly object $fieldtype,
				private readonly string $shortClass
			) {
				parent::__construct();
			}

			public function supports(): array
			{
				return [$this->shortClass];
			}

			public function transform(mixed $value, ?Field $field = null, ?Page $page = null): mixed
			{
				if ($field instanceof Field && method_exists($this->fieldtype, 'toInertiaFieldValue')) {
					return $this->fieldtype->toInertiaFieldValue($value, $field, $page);
				}

				return $value;
			}

			public function phpType(Field $field): string
			{
				if (method_exists($this->fieldtype, 'toInertiaPhpType')) {
					return (string) $this->fieldtype->toInertiaPhpType($field);
				}

				return 'mixed';
			}

			public function tsType(Field $field): string
			{
				if (method_exists($this->fieldtype, 'toInertiaTsType')) {
					return (string) $this->fieldtype->toInertiaTsType($field);
				}

				return 'unknown';
			}

			public function tsDefinitions(?Field $field = null): array
			{
				if (method_exists($this->fieldtype, 'toInertiaTsDefinitions')) {
					$defs = $this->fieldtype->toInertiaTsDefinitions($field);

					return is_array($defs) ? $defs : [];
				}

				return [];
			}
		};

		$this->wire($provider);
		$provider->setRegistry($this);

		return $this->fieldProviders[$shortClass] = $provider;
	}

	/**
	 * Transform a single `Field` on `$page` using its registered `FieldDataProvider`.
	 */
	public function transformField(Field $field, Page $page): mixed
	{
		$fieldName = trim((string) $field->name);
		if ($fieldName === '') {
			return null;
		}

		$provider = $this->resolveFieldProvider($field);
		$rawValue = $page->get($fieldName);

		return $provider->transform($rawValue, $field, $page);
	}

	/**
	 * Resolve a registered `FieldDataProvider` that supports a standalone domain object.
	 */
	public function resolveObjectProvider(object $value): ?FieldDataProvider
	{
		$fqcn = $value::class;
		if (isset($this->fieldProviders[$fqcn])) {
			return $this->fieldProviders[$fqcn];
		}

		foreach (array_reverse($this->fieldProviders, true) as $supportedKey => $provider) {
			if ($supportedKey === '*' || str_starts_with($supportedKey, 'field:') || str_starts_with($supportedKey, 'Fieldtype')) {
				continue;
			}
			if (is_a($value, $supportedKey)) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Resolve the active `TemplateDataProvider` for a template name or `Template` object.
	 */
	public function resolveTemplateProvider(Template|string|null $template): TemplateDataProvider
	{
		$name = $template instanceof Template ? trim((string) $template->name) : trim((string) $template);
		if ($name !== '' && isset($this->templateProviders[$name])) {
			return $this->templateProviders[$name];
		}

		return $this->templateProviders['*'] ?? $this->fallbackTemplateProvider;
	}

	/**
	 * Transform a ProcessWire `Page` into its Inertia prop array via the resolved `TemplateDataProvider`.
	 * Automatically guards against circular `Page` reference graphs by falling back to `TemplateDataProvider::basePageData($page)`.
	 *
	 * @return array<string, mixed>
	 */
	public function providePage(Page $page): array
	{
		$id = (int) $page->id;
		if ($id > 0 && isset($this->activePageIds[$id])) {
			return TemplateDataProvider::basePageData($page);
		}

		if ($id > 0) {
			$this->activePageIds[$id] = true;
		}

		try {
			$provider = $this->resolveTemplateProvider($page->template);

			return $provider->provide($page, $this);
		} finally {
			if ($id > 0) {
				unset($this->activePageIds[$id]);
			}
		}
	}

	/**
	 * Transform a ProcessWire `User` into its Inertia prop array via the active `UserDataProvider`.
	 *
	 * @return array<string, mixed>
	 */
	public function provideUser(User $user): array
	{
		return $this->userProvider->provide($user, $this);
	}

	/**
	 * Transform a standalone ProcessWire `Pageimage` via the active `FieldtypeImage` provider.
	 */
	public function provideImage(Pageimage $image): mixed
	{
		return $this->provideMedia($image, 'FieldtypeImage');
	}

	/**
	 * Transform a standalone ProcessWire `Pagefile` via the active `FieldtypeFile` provider.
	 */
	public function provideFile(Pagefile $file): mixed
	{
		return $this->provideMedia($file, 'FieldtypeFile');
	}

	private function provideMedia(Pagefile $file, string $fieldtypeClass): mixed
	{
		if (!MediaFieldDataProvider::isAccessiblePagefile($file)) {
			return null;
		}

		$provider = $this->fieldProviders[$fieldtypeClass] ?? $this->fallbackFieldProvider;

		return $provider->transform($file, null, $file->page instanceof Page ? $file->page : null);
	}

	/**
	 * Resolve the active TypeScript definitions for `InertiaImage` (and its sub-interfaces) and `InertiaFile`
	 * from registered media providers.
	 *
	 * @return array<string, string>
	 */
	public function mediaTsDefinitions(): array
	{
		$defaults = MediaFieldDataProvider::defaultTsDefinitions($this->wire() !== null ? MediaFieldDataProvider::isInterventionImageInstalled($this->wire()) : null);
		$targets = [
			'FieldtypeImage' => 'InertiaImage',
			'FieldtypeFile' => 'InertiaFile',
		];

		foreach ($targets as $fieldtypeClass => $interfaceName) {
			$provider = $this->fieldProviders[$fieldtypeClass] ?? null;
			if ($provider === null) {
				continue;
			}

			$defs = $provider->tsDefinitions(null);
			if (isset($defs[$interfaceName])) {
				$defaults[$interfaceName] = $defs[$interfaceName];
			}
		}

		return $defaults;
	}

	/**
	 * Collect global field-independent TypeScript definitions (`tsDefinitions(null)`) from all registered `FieldDataProvider`s.
	 *
	 * @return array<string, string>
	 */
	public function globalTsDefinitions(): array
	{
		$definitions = [];
		$seen = [];
		$mediaKeys = $this->mediaTsDefinitions();

		foreach ($this->fieldProviders as $provider) {
			$id = spl_object_id($provider);
			if (isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;

			foreach ($provider->tsDefinitions(null) as $name => $def) {
				if (isset($mediaKeys[$name])) {
					continue;
				}
				$definitions[$name] = $def;
			}
		}

		return $definitions;
	}

	public function getUserProvider(): UserDataProvider
	{
		return $this->userProvider;
	}

	/**
	 * Auto-discover and register custom `*Provider.php` classes from `site/inertia/`.
	 */
	public function discoverFromDirectory(string $directory): self
	{
		$cleanDir = rtrim($directory, '/\\');
		if ($cleanDir === '' || !is_dir($cleanDir)) {
			return $this;
		}

		$realDir = realpath($cleanDir);
		if (!is_string($realDir)) {
			return $this;
		}

		$files = glob($realDir . '/*Provider.php') ?: [];
		sort($files);

		foreach ($files as $file) {
			$realFile = is_file($file) && is_readable($file) ? realpath($file) : false;
			if (!is_string($realFile) || !str_starts_with($realFile, $realDir . DIRECTORY_SEPARATOR)) {
				continue;
			}

			$className = $this->resolveClassFromFile($realFile);
			if ($className === null) {
				continue;
			}

			if (!class_exists($className, false)) {
				require_once $realFile;
			}

			if (!class_exists($className)) {
				continue;
			}

			$ref = new ReflectionClass($className);
			if ($ref->isAbstract()) {
				continue;
			}

			$ctor = $ref->getConstructor();
			if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
				continue;
			}

			$instance = $ref->newInstance();
			if (
				$instance instanceof FieldDataProvider
				|| $instance instanceof TemplateDataProvider
				|| $instance instanceof UserDataProvider
			) {
				$this->register($instance);
			}
		}

		return $this;
	}

	/**
	 * Build a TypeScript `export interface {$interfaceName} extends InertiaPageBase` definition
	 * and collect all auxiliary TypeScript definitions for a ProcessWire `Template`.
	 *
	 * @param list<string> $leadingLines
	 * @param list<string> $skipFieldNames
	 * @return array{interface: string, definitions: array<string, string>}
	 */
	public function buildTemplateTsInterface(
		Template $template,
		string $interfaceName,
		array $leadingLines = [],
		array $skipFieldNames = []
	): array {
		$templateProvider = $this->resolveTemplateProvider($template);
		$definitions = [];
		$lines = $leadingLines;

		foreach ($templateProvider->eligibleFields($template, $skipFieldNames) as $fieldName => $field) {
			$fieldProvider = $this->resolveFieldProvider($field);
			foreach ($fieldProvider->tsDefinitions($field) as $auxName => $auxDef) {
				$definitions[$auxName] = $auxDef;
			}

			$safeKey = self::formatTsKey($fieldName);
			$tsType = $fieldProvider->tsType($field);
			$lines[] = "\t{$safeKey}: {$tsType};";
		}

		foreach ($templateProvider->tsDefinitions($template) as $auxName => $auxDef) {
			$definitions[$auxName] = $auxDef;
		}

		foreach ($templateProvider->extraTsTypes($template) as $extraKey => $extraTsType) {
			$safeKey = self::formatTsKey($extraKey);
			$lines[] = "\t{$safeKey}: {$extraTsType};";
		}

		$body = $lines !== [] ? "\n" . implode("\n", $lines) . "\n" : "\n";

		return [
			'interface' => <<<TS
export interface {$interfaceName} extends InertiaPageBase {{$body}}
TS,
			'definitions' => $definitions,
		];
	}

	/**
	 * Convert a kebab-case or snake_case string to StudlyCase / PascalCase.
	 */
	public static function studly(string $value): string
	{
		$words = preg_split('/[^a-zA-Z0-9]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($words) || $words === []) {
			return 'Custom';
		}

		$result = implode('', array_map(static fn(string $w): string => ucfirst($w), $words));

		return preg_match('/^[0-9]/', $result) === 1 ? 'T' . $result : $result;
	}

	/**
	 * Quote a TypeScript interface property name if it is not a valid bare identifier.
	 */
	public static function formatTsKey(string $key): string
	{
		return preg_match('/^[a-zA-Z_$][a-zA-Z0-9_$]*$/', $key) === 1
			? $key
			: "'" . addcslashes($key, "'\\") . "'";
	}

	/**
	 * Extract the fully-qualified class name from a PHP source file.
	 */
	private function resolveClassFromFile(string $filePath): ?string
	{
		$source = (string) file_get_contents($filePath);
		if ($source === '') {
			return null;
		}

		$namespace = '';
		if (preg_match('/^\s*namespace\s+([a-zA-Z0-9_\\\\]+)\s*;/m', $source, $nsMatch) === 1) {
			$namespace = trim($nsMatch[1]);
		}

		if (preg_match('/^\s*(?:final\s+|readonly\s+)*class\s+([a-zA-Z0-9_]+)/m', $source, $clsMatch) !== 1) {
			return null;
		}

		$className = trim($clsMatch[1]);

		return $namespace !== '' ? $namespace . '\\' . $className : $className;
	}
}
