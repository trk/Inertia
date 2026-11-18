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

	private ?UserDataProvider $userProvider = null;

	private ?FieldDataProvider $fallbackFieldProvider = null;

	private ?TemplateDataProvider $fallbackTemplateProvider = null;

	private bool $defaultsInitialized = false;

	/**
	 * Track active Page IDs during `providePage()` serialization to prevent infinite recursion on circular Page graphs.
	 *
	 * @var array<int, true>
	 */
	private array $activePageIds = [];

	private ?Page $activeContextPage = null;

	public function wired(): void
	{
		parent::wired();
		if ($this->fallbackFieldProvider !== null) {
			$this->wire($this->fallbackFieldProvider);
		}
		if ($this->fallbackTemplateProvider !== null) {
			$this->wire($this->fallbackTemplateProvider);
		}
		if ($this->userProvider !== null) {
			$this->wire($this->userProvider);
		}

		foreach ($this->fieldProviders as $provider) {
			$this->wire($provider);
		}

		foreach ($this->templateProviders as $provider) {
			$this->wire($provider);
		}
	}

	private function ensureDefaults(): void
	{
		if (!$this->defaultsInitialized) {
			$this->resetToDefaults();
		}
	}

	/**
	 * Reset registry to built-in core providers.
	 */
	public function resetToDefaults(): self
	{
		$this->defaultsInitialized = true;
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
		$this->ensureDefaults();
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
		$this->ensureDefaults();
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

			if ($typeObj instanceof InertiaFieldtypeInterface) {
				return $this->createFieldtypeBridgeProvider($typeObj, $shortClass);
			}
		}

		return $this->fieldProviders['*'] ?? ($this->fallbackFieldProvider ??= new ScalarFieldDataProvider());
	}

	/**
	 * Wrap an `InertiaFieldtypeInterface` implementation inside a `FieldDataProvider` adapter.
	 */
	private function createFieldtypeBridgeProvider(InertiaFieldtypeInterface $fieldtype, string $shortClass): FieldDataProvider
	{
		$provider = new class($fieldtype, $shortClass) extends FieldDataProvider {
			public function __construct(
				private readonly InertiaFieldtypeInterface $fieldtype,
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
				if ($field instanceof Field) {
					return $this->fieldtype->toInertiaFieldValue($value, $field, $page);
				}

				return $value;
			}

			public function phpType(Field $field): string
			{
				return $this->fieldtype->toInertiaPhpType($field);
			}

			public function tsType(Field $field): string
			{
				return $this->fieldtype->toInertiaTsType($field);
			}

			public function tsDefinitions(?Field $field = null): array
			{
				return $this->fieldtype->toInertiaTsDefinitions($field);
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
		if ($fieldName === '' || !TemplateDataProvider::isFieldViewableOnPage($field, $page)) {
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
		$this->ensureDefaults();
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
		$this->ensureDefaults();
		$name = $template instanceof Template ? trim((string) $template->name) : trim((string) $template);
		if ($name !== '' && isset($this->templateProviders[$name])) {
			return $this->templateProviders[$name];
		}

		return $this->templateProviders['*'] ?? ($this->fallbackTemplateProvider ??= new TemplateDataProvider());
	}

	/**
	 * Return the active root `Page` context during `providePage()` serialization, or fall back to `$this->wire()->page`.
	 */
	public function activeContextPage(): ?Page
	{
		if ($this->activeContextPage !== null) {
			return $this->activeContextPage;
		}

		$wirePage = $this->wire()->page;

		return $wirePage instanceof Page ? $wirePage : null;
	}

	/**
	 * Transform a ProcessWire `Page` into its Inertia prop array via the resolved `TemplateDataProvider`.
	 * Automatically guards against circular `Page` reference graphs by falling back to `TemplateDataProvider::basePageData($page)`.
	 *
	 * @return array<string, mixed>
	 */
	public function providePage(Page $page, ?Page $contextPage = null): array
	{
		if ($page instanceof User) {
			return $this->provideUser($page);
		}

		$currentPage = $contextPage ?? $this->activeContextPage();
		$effectiveContext = $currentPage instanceof Page ? $currentPage : null;
		if (!TemplateDataProvider::isAccessiblePage($page, true, $effectiveContext)) {
			return [];
		}

		$id = (int) $page->id;
		if ($id > 0 && isset($this->activePageIds[$id])) {
			return TemplateDataProvider::basePageData($page);
		}

		if ($id > 0) {
			$this->activePageIds[$id] = true;
		}

		$previousContext = $this->activeContextPage;
		$this->activeContextPage = $effectiveContext;

		try {
			$provider = $this->resolveTemplateProvider($page->template);

			return $provider->provide($page, $this);
		} finally {
			$this->activeContextPage = $previousContext;
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
		return $this->getUserProvider()->provide($user, $this);
	}

	/**
	 * Transform a standalone ProcessWire `Pageimage` via the active `FieldtypeImage` provider.
	 */
	public function provideImage(Pageimage $image, ?Page $contextPage = null): mixed
	{
		return $this->provideMedia($image, 'FieldtypeImage', $contextPage);
	}

	/**
	 * Transform a standalone ProcessWire `Pagefile` via the active `FieldtypeFile` provider.
	 */
	public function provideFile(Pagefile $file, ?Page $contextPage = null): mixed
	{
		return $this->provideMedia($file, 'FieldtypeFile', $contextPage);
	}

	private function provideMedia(Pagefile $file, string $fieldtypeClass, ?Page $contextPage = null): mixed
	{
		$this->ensureDefaults();
		$effectiveContext = $contextPage ?? $this->activeContextPage();
		if (!MediaFieldDataProvider::isAccessiblePagefile($file, $effectiveContext)) {
			return null;
		}

		$provider = $this->fieldProviders[$fieldtypeClass] ?? ($this->fallbackFieldProvider ??= new ScalarFieldDataProvider());
		$targetPage = $effectiveContext ?? ($file->page instanceof Page ? $file->page : null);

		return $provider->transform($file, null, $targetPage);
	}

	/**
	 * Resolve the active TypeScript definitions for `InertiaImage` (and its sub-interfaces) and `InertiaFile`
	 * from registered media providers.
	 *
	 * @return array<string, string>
	 */
	public function mediaTsDefinitions(): array
	{
		$this->ensureDefaults();
		$defaults = MediaFieldDataProvider::defaultTsDefinitions();
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
		$this->ensureDefaults();
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
		$this->ensureDefaults();

		if ($this->userProvider === null) {
			$userProvider = new UserDataProvider();
			$this->wire($userProvider);
			$this->userProvider = $userProvider;
		}

		return $this->userProvider;
	}

	/**
	 * Auto-discover and register custom `*Provider.php` classes from `site/inertia/`.
	 */
	public function discoverFromDirectory(string $directory): self
	{
		$this->ensureDefaults();
		$cleanDir = rtrim($directory, '/\\');
		if ($cleanDir === '' || is_link($cleanDir) || !is_dir($cleanDir)) {
			return $this;
		}

		$realDir = realpath($cleanDir);
		if (!is_string($realDir)) {
			return $this;
		}

		$files = glob($realDir . '/*Provider.php') ?: [];
		sort($files);

		foreach ($files as $file) {
			if (is_link($file) || !is_file($file) || !is_readable($file)) {
				continue;
			}

			$realFile = realpath($file);
			if (!is_string($realFile) || !str_starts_with($realFile, $realDir . DIRECTORY_SEPARATOR)) {
				continue;
			}

			foreach ($this->resolveClassesFromFile($realFile) as $className) {
				if (preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $className) !== 1) {
					continue;
				}

				if (!class_exists($className, false)) {
					require_once $realFile;
				}

				if (!class_exists($className, false)) {
					continue;
				}

				$ref = new ReflectionClass($className);
				if (
					!$ref->isInstantiable()
					|| $ref->getFileName() !== $realFile
					|| (
						!$ref->isSubclassOf(FieldDataProvider::class)
						&& !$ref->isSubclassOf(TemplateDataProvider::class)
						&& !$ref->isSubclassOf(UserDataProvider::class)
					)
				) {
					continue;
				}

				$ctor = $ref->getConstructor();
				if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
					continue;
				}

				/** @var FieldDataProvider|TemplateDataProvider|UserDataProvider $instance */
				$instance = $ref->newInstance();
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
	 * Extract all fully-qualified concrete class names from a PHP source file using PHP's native lexer.
	 *
	 * @return list<string>
	 */
	private function resolveClassesFromFile(string $filePath): array
	{
		$source = (string) file_get_contents($filePath);
		if ($source === '') {
			return [];
		}

		$tokens = \PhpToken::tokenize($source);
		$count = count($tokens);
		$namespace = '';
		$classes = [];

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];

			if ($token->id === T_NAMESPACE) {
				$firstMeaningful = null;
				for ($k = $i + 1; $k < $count; $k++) {
					if (!$tokens[$k]->isIgnorable()) {
						$firstMeaningful = $tokens[$k];
						break;
					}
				}
				if ($firstMeaningful !== null && $firstMeaningful->id === T_NS_SEPARATOR) {
					continue;
				}

				$nsParts = [];
				for ($j = $i + 1; $j < $count; $j++) {
					$next = $tokens[$j];
					if ($next->isIgnorable()) {
						continue;
					}
					if ($next->id === T_NAME_QUALIFIED || $next->id === T_STRING || $next->id === T_NS_SEPARATOR) {
						$nsParts[] = $next->text;
					} else {
						break;
					}
				}
				$namespace = trim(implode('', $nsParts), '\\');
				continue;
			}

			if ($token->id === T_CLASS) {
				$skipClass = false;
				for ($p = $i - 1; $p >= 0; $p--) {
					if ($tokens[$p]->isIgnorable()) {
						continue;
					}
					$prevId = $tokens[$p]->id;
					if ($prevId === T_READONLY || $prevId === T_FINAL) {
						continue;
					}
					if ($prevId === T_ABSTRACT || $prevId === T_DOUBLE_COLON || $prevId === T_NEW) {
						$skipClass = true;
					}
					break;
				}
				if ($skipClass) {
					continue;
				}

				for ($j = $i + 1; $j < $count; $j++) {
					if ($tokens[$j]->isIgnorable()) {
						continue;
					}
					if ($tokens[$j]->id === T_STRING) {
						$className = $tokens[$j]->text;
						$classes[] = $namespace !== '' ? $namespace . '\\' . $className : $className;
					}
					break;
				}
			}
		}

		return $classes;
	}
}
