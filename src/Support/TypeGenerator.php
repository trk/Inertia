<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Support;

use ProcessWire\Inertia;
use ProcessWire\Template;
use ProcessWire\Wire;
use Totoglu\Inertia\Provider\ProviderRegistry;

/**
 * Generates PHP Custom Page Classes (`site/classes/[PascalCase]Page.php`), starter template controllers,
 * and TypeScript definitions (`inertia.d.ts`) from `ProviderRegistry` schema metadata.
 */
final class TypeGenerator extends Wire
{
	public function __construct(
		private readonly Inertia $inertia
	) {
		parent::__construct();
		$this->inertia->wire($this);
	}

	/**
	 * Run the generator for the specified templates (or all currently enabled Inertia templates).
	 *
	 * @param list<string>|null $templateNames Explicit template names, or `null` to inspect enabled Inertia templates
	 * @param bool $force Overwrite existing PHP Custom Page Classes and template controller files when `true`
	 * @param bool $generatePhp Whether to scaffold PHP Custom Page Classes
	 * @param bool $generateTs Whether to generate the TypeScript `.d.ts` definitions file
	 * @param bool $generateControllers Whether to scaffold missing `site/templates/{template}.php` controllers
	 * @param string|null $tsOutputPath Optional custom output path for the `.d.ts` file
	 * @return array{
	 *     templates: list<string>,
	 *     phpClasses: list<array{template: string, class: string, path: string, status: string}>,
	 *     templateControllers: list<array{template: string, path: string, status: string}>,
	 *     typescript: array{path: string, written: bool, bytes: int, interfaces: list<string>}|null
	 * }
	 */
	public function generate(
		?array $templateNames = null,
		bool $force = false,
		bool $generatePhp = true,
		bool $generateTs = true,
		bool $generateControllers = false,
		?string $tsOutputPath = null
	): array {
		$templates = $this->resolveTargetTemplates($templateNames);
		$resolvedNames = array_values(array_map(static fn(Template $t): string => (string) $t->name, $templates));

		$phpClasses = [];
		$templateControllers = [];

		if ($generatePhp) {
			foreach ($templates as $template) {
				$phpClasses[] = $this->generatePageClass($template, $force);
			}
		}

		if ($generateControllers) {
			foreach ($templates as $template) {
				$templateControllers[] = $this->generateTemplateController($template, $force);
			}
		}

		$tsResult = null;
		if ($generateTs) {
			$tsResult = $this->writeTypeScriptDefinitions($templates, $tsOutputPath);
		}

		return [
			'templates' => $resolvedNames,
			'phpClasses' => $phpClasses,
			'templateControllers' => $templateControllers,
			'typescript' => $tsResult,
		];
	}

	/**
	 * Resolve the list of ProcessWire `Template` objects to inspect.
	 *
	 * @param list<string>|null $templateNames
	 * @return list<Template>
	 */
	private function resolveTargetTemplates(?array $templateNames = null): array
	{
		$templatesApi = $this->wire()->templates;

		$result = [];
		$seen = [];

		if ($templateNames !== null && $templateNames !== []) {
			foreach ($templateNames as $rawName) {
				$name = trim($rawName);
				if ($name === '' || $name === 'admin' || isset($seen[$name])) {
					continue;
				}
				$tpl = $templatesApi->get($name);
				if ($tpl instanceof Template && !($tpl->flags & Template::flagSystem)) {
					$seen[$name] = true;
					$result[] = $tpl;
				}
			}

			return $result;
		}

		foreach ($templatesApi as $tpl) {
			if (!$tpl instanceof Template || ($tpl->flags & Template::flagSystem)) {
				continue;
			}
			$name = (string) $tpl->name;
			if (!isset($seen[$name]) && $this->inertia->isTemplateEnabled($tpl)) {
				$seen[$name] = true;
				$result[] = $tpl;
			}
		}

		return $result;
	}

	/**
	 * Generate a clean, strongly-typed PHP Custom Page Class (`site/classes/[PascalCase]Page.php`) for a template.
	 * Never overwrites an existing file unless `$force === true`.
	 *
	 * @return array{template: string, class: string, path: string, status: string}
	 */
	public function generatePageClass(Template $template, bool $force = false): array
	{
		$tplName = (string) $template->name;
		$pascal = ProviderRegistry::studly($tplName);
		$className = $pascal . 'Page';

		$sitePath = rtrim((string) $this->wire()->config->paths->site, '/\\');
		$classesDir = $sitePath !== '' ? $sitePath . '/classes' : dirname(__DIR__, 4) . '/classes';
		$filePath = $classesDir . '/' . $className . '.php';

		return [
			'template' => $tplName,
			'class' => $className,
			'path' => $filePath,
			'status' => $this->writeScaffoldFile($filePath, fn(): string => $this->buildPageClassCode($template), $force),
		];
	}

	/**
	 * Generate a starter Inertia template controller (`site/templates/{template}.php`) if missing.
	 * Never overwrites an existing file unless `$force === true`.
	 *
	 * @return array{template: string, path: string, status: string}
	 */
	private function generateTemplateController(Template $template, bool $force = false): array
	{
		$tplName = (string) preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $template->name);
		if ($tplName === '') {
			$tplName = 'inertia';
		}
		$templatesDir = rtrim((string) $this->wire()->config->paths->templates, '/\\');
		$filePath = $templatesDir . '/' . $tplName . '.php';

		return [
			'template' => $tplName,
			'path' => $filePath,
			'status' => $this->writeScaffoldFile($filePath, fn(): string => $this->buildTemplateControllerCode($template), $force),
		];
	}

	/**
	 * Build the PHP source code for a template's Custom Page Class (`site/classes/[PascalCase]Page.php`).
	 */
	public function buildPageClassCode(Template $template): string
	{
		$tplName = (string) $template->name;
		$pascal = ProviderRegistry::studly($tplName);
		$className = $pascal . 'Page';
		$registry = $this->inertia->providers();
		$templateProvider = $registry->resolveTemplateProvider($template);
		$propertyLines = [];

		foreach ($templateProvider->eligibleFields($template) as $fName => $field) {
			$phpDocType = $registry->resolveFieldProvider($field)->phpType($field);
			$propertyLines[] = " * @property {$phpDocType} \${$fName}";
		}

		if ($propertyLines === []) {
			$propertyLines[] = ' * @property string $title';
		}

		$propertiesDoc = implode("\n", $propertyLines);

		return <<<PHP
<?php

declare(strict_types=1);

namespace ProcessWire;

use Totoglu\Inertia\InteractWithInertia;

/**
 * Custom Page Class for the `{$tplName}` template.
 *
{$propertiesDoc}
 */
class {$className} extends Page
{
	use InteractWithInertia;

	/**
	 * @return array<string, mixed>
	 */
	public function inertiaWithData(): array
	{
		return [
			'page' => \$this,
			'children' => \$this->inertia()->defer(
				fn(): PageArray => \$this->children('limit=20')
			),
		];
	}
}

PHP;
	}

	/**
	 * Build the PHP source code for a starter template controller (`site/templates/{template}.php`).
	 */
	private function buildTemplateControllerCode(Template $template): string
	{
		$tplName = (string) $template->name;
		$pascal = ProviderRegistry::studly($tplName);
		$className = $pascal . 'Page';
		$componentName = $pascal . '/Show';

		return <<<PHP
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var {$className} \$page */
return \$page->inertiaRender('{$componentName}');

PHP;
	}

	/**
	 * Generate and write the TypeScript definitions file (`inertia.d.ts`).
	 *
	 * @param list<Template> $templates
	 * @return array{path: string, written: bool, bytes: int, interfaces: list<string>}
	 */
	private function writeTypeScriptDefinitions(array $templates, ?string $customOutputPath = null): array
	{
		$targetPath = $this->resolveTypeScriptOutputPath($customOutputPath);
		$built = $this->buildTypeScriptDefinitions($templates);
		$written = $this->writeFileSafely($targetPath, $built['content']);

		return [
			'path' => $targetPath,
			'written' => $written,
			'bytes' => strlen($built['content']),
			'interfaces' => $built['interfaces'],
		];
	}

	/**
	 * Build the TypeScript `.d.ts` definitions content for the given templates by querying `ProviderRegistry`.
	 *
	 * @param list<Template> $templates
	 * @return array{content: string, interfaces: list<string>}
	 */
	public function buildTypeScriptDefinitions(array $templates): array
	{
		$registry = $this->inertia->providers();
		$mediaDefaults = $registry->mediaTsDefinitions();

		$interfaces = array_merge(
			array_keys($mediaDefaults),
			[
				'InertiaPageBase',
				'InertiaUser',
				'InertiaCsrf',
				'InertiaFlash',
				'InertiaSharedProps',
			]
		);

		/** @var array<string, string> $auxiliaryDefinitions */
		$auxiliaryDefinitions = [];
		/** @var list<string> $templateBlocks */
		$templateBlocks = [];
		/** @var list<string> $templateMapLines */
		$templateMapLines = [];
		$primaryInterface = 'InertiaPageBase';

		$registerAux = static function (array $definitions) use (&$mediaDefaults, &$auxiliaryDefinitions, &$interfaces): void {
			foreach ($definitions as $auxName => $auxDef) {
				if (isset($mediaDefaults[$auxName])) {
					$mediaDefaults[$auxName] = $auxDef;
					continue;
				}
				$auxiliaryDefinitions[$auxName] = $auxDef;
				$interfaces[] = $auxName;
			}
		};

		foreach ($templates as $idx => $template) {
			$tplName = (string) $template->name;
			$pascal = ProviderRegistry::studly($tplName);
			$interfaceName = $pascal . 'PageData';
			if ($idx === 0) {
				$primaryInterface = $interfaceName;
			}
			$interfaces[] = $interfaceName;

			$built = $registry->buildTemplateTsInterface($template, $interfaceName);
			$registerAux($built['definitions']);

			$templateBlocks[] = $built['interface'];
			$safeMapKey = ProviderRegistry::formatTsKey($tplName);
			$templateMapLines[] = "\t{$safeMapKey}: {$interfaceName};";
		}

		$registerAux($registry->getUserProvider()->tsDefinitions($registry));
		$registerAux($registry->globalTsDefinitions());

		$interfaces[] = 'InertiaTemplateMap';
		$interfaces[] = 'InertiaPageProps';

		$mediaInterfacesBlock = implode("\n\n", array_values($mediaDefaults));
		$userInterfaceBlock = $registry->getUserProvider()->tsDefinition($registry);

		$auxiliarySection = $auxiliaryDefinitions !== []
			? "\n" . implode("\n\n", array_values($auxiliaryDefinitions)) . "\n"
			: '';

		$templateSection = $templateBlocks !== []
			? "\n" . implode("\n\n", $templateBlocks) . "\n"
			: '';

		$mapBody = $templateMapLines !== []
			? "\n" . implode("\n", $templateMapLines) . "\n"
			: "\t[template: string]: InertiaPageBase;\n";

		$content = <<<TS
/**
 * Auto-generated TypeScript definitions for ProcessWire Inertia.js v3.
 * Generated by `php vendor/bin/wire inertia:types` — safe to regenerate at any time.
 */

{$mediaInterfacesBlock}

export interface InertiaPageBase {
	id: number;
	name: string;
	title: string;
	url: string;
	httpUrl: string;
	template: string;
	created: number;
	modified: number;
}

{$userInterfaceBlock}

export interface InertiaCsrf {
	name: string;
	value: string;
}

export interface InertiaFlash {
	messages: string[];
	warnings: string[];
	errors: string[];
	[key: string]: unknown;
}

export interface InertiaSharedProps {
	errors: Record<string, string | Record<string, string>>;
	flash?: InertiaFlash;
	csrf?: InertiaCsrf | null;
	auth?: {
		user?: InertiaUser | null;
		[key: string]: unknown;
	};
	[key: string]: unknown;
}
{$auxiliarySection}{$templateSection}
export interface InertiaTemplateMap {{$mapBody}}

export type InertiaPage = {$primaryInterface};

export interface InertiaPageProps<TPage = {$primaryInterface}> extends InertiaSharedProps {
	page: TPage;
	children?: InertiaPageBase[];
}

TS;

		return [
			'content' => $content,
			'interfaces' => array_values(array_unique($interfaces)),
		];
	}

	/**
	 * Resolve the absolute filesystem path for the TypeScript `.d.ts` output file.
	 */
	private function resolveTypeScriptOutputPath(?string $customOutputPath = null): string
	{
		$config = $this->wire()->config;
		$templatesPath = rtrim((string) $config->paths->templates, '/\\');
		$defaultOutput = $templatesPath . '/assets/js/types/inertia.d.ts';

		$rawPath = $customOutputPath !== null && trim($customOutputPath) !== ''
			? trim($customOutputPath)
			: trim((string) $this->inertia->typeScriptOutputPath);

		if ($rawPath === '' || str_contains($rawPath, "\0")) {
			return $defaultOutput;
		}

		$normalized = str_replace('\\', '/', $rawPath);
		foreach (explode('/', $normalized) as $segment) {
			if ($segment === '..') {
				return $defaultOutput;
			}
		}

		if (!str_ends_with(strtolower($normalized), '.ts')) {
			return $defaultOutput;
		}

		if (str_starts_with($rawPath, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $rawPath) === 1) {
			$sitePath = rtrim(str_replace('\\', '/', (string) $config->paths->site), '/');
			$tempDir = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/');
			$realTempDir = realpath(sys_get_temp_dir());
			$realTempNormalized = $realTempDir !== false ? rtrim(str_replace('\\', '/', $realTempDir), '/') : '';

			$inSite = $sitePath !== '' && str_starts_with($normalized, $sitePath . '/');
			$inTemp = ($tempDir !== '' && str_starts_with($normalized, $tempDir . '/'))
				|| ($realTempNormalized !== '' && str_starts_with($normalized, $realTempNormalized . '/'));

			if (!$inSite && !$inTemp) {
				return $defaultOutput;
			}

			return $rawPath;
		}

		return $templatesPath . '/' . ltrim($rawPath, '/\\');
	}

	/**
	 * Write a scaffolded file to disk unless it already exists and `$force` is `false`.
	 *
	 * @param string|callable(): string $content
	 * @return 'skipped'|'updated'|'created'
	 */
	public function writeScaffoldFile(string $filePath, string|callable $content, bool $force = false): string
	{
		$existed = is_file($filePath);
		if ($existed && !$force) {
			return 'skipped';
		}

		$resolvedContent = is_string($content) ? $content : (string) $content();
		$this->writeFileSafely($filePath, $resolvedContent);

		return $existed ? 'updated' : 'created';
	}

	public function writeFileSafely(string $filePath, string $content): bool
	{
		$dir = dirname($filePath);
		$files = $this->wire()->files;

		if (!is_dir($dir)) {
			$files->mkdir($dir, true);
		}

		return (bool) $files->filePutContents($filePath, $content);
	}
}
