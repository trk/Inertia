<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Support;

use Closure;
use ProcessWire\Inertia;
use ProcessWire\Page;
use ProcessWire\Process;
use ProcessWire\Wire;
use Totoglu\Inertia\PageResponse;
use Totoglu\Inertia\Ssr\SsrResponse;

/**
 * Renders the Inertia Root View PHP shell (`site/templates/_inertia.php` or fallback stub)
 * with realpath confinement, fuel/superglobal variable protection, and `<head>` breakout neutralization.
 */
final class RootViewRenderer extends Wire
{
	private const array BLOCKED_VIEW_KEYS = [
		'GLOBALS',
		'_GET',
		'_POST',
		'_COOKIE',
		'_REQUEST',
		'_SERVER',
		'_ENV',
		'_FILES',
		'_SESSION',
		'this',
		'fuel',
		'out',
		'_filename',
		'wire',
	];

	public function __construct(
		private readonly Inertia $module,
		private readonly string $stubsPath
	) {
		parent::__construct();
	}

	/**
	 * Render the configured Root View PHP template or inline HTML fallback.
	 *
	 * @param array<string, mixed> $pageObject
	 * @param array<string, mixed> $sharedViewData
	 */
	public function render(
		PageResponse $pageResponse,
		array $pageObject,
		array $sharedViewData,
		?SsrResponse $ssr
	): string {
		$wire = $this->wire() ?? $this->module->wire();
		$config = $wire->config;
		$files = $wire->files;
		$templatesPath = rtrim((string) $config->paths->templates, '/\\');
		$siteModulesPath = rtrim((string) $config->paths->siteModules, '/\\');
		$defaultStubFile = $this->stubsPath . '/_inertia.php';

		$configuredView = trim((string) $this->module->rootView);
		$viewName = $pageResponse->getRootView() ?? ($configuredView !== '' ? $configuredView : '_inertia.php');
		if (!str_ends_with($viewName, '.php')) {
			$viewName .= '.php';
		}

		$candidatePath = str_starts_with($viewName, '/')
			? $viewName
			: ($templatesPath !== '' ? $templatesPath . '/' . ltrim($viewName, '/') : '');

		$resolvedCandidate = $candidatePath !== '' && is_file($candidatePath) ? realpath($candidatePath) : false;
		$realTemplatesDir = $templatesPath !== '' && is_dir($templatesPath) ? realpath($templatesPath) : false;
		$realSiteModulesDir = $siteModulesPath !== '' && is_dir($siteModulesPath) ? realpath($siteModulesPath) : false;
		$realStubsDir = is_dir($this->stubsPath) ? realpath($this->stubsPath) : false;

		$isAllowedPath = false;
		if (is_string($resolvedCandidate)) {
			if (is_string($realTemplatesDir) && str_starts_with($resolvedCandidate, $realTemplatesDir . DIRECTORY_SEPARATOR)) {
				$isAllowedPath = true;
			} elseif (is_string($realSiteModulesDir) && str_starts_with($resolvedCandidate, $realSiteModulesDir . DIRECTORY_SEPARATOR)) {
				$isAllowedPath = true;
			} elseif (is_string($realStubsDir) && str_starts_with($resolvedCandidate, $realStubsDir . DIRECTORY_SEPARATOR)) {
				$isAllowedPath = true;
			}
		}

		$targetFile = $isAllowedPath && is_string($resolvedCandidate)
			? $resolvedCandidate
			: (is_string($realStubsDir) ? $realStubsDir . '/_inertia.php' : $defaultStubFile);

		/** @var Page|null $currentPage */
		$currentPage = $pageResponse->getPage() ?? $wire->page;
		$pageViewData = ($currentPage instanceof Page && method_exists($currentPage, 'inertiaViewData'))
			? (array) $currentPage->inertiaViewData($pageObject)
			: [];
		$pageWithViewData = ($currentPage instanceof Page && method_exists($currentPage, 'inertiaWithViewData'))
			? (array) $currentPage->inertiaWithViewData($pageObject)
			: [];

		$wireProcess = $wire->process;
		$currentProcess = $pageResponse->getProcess() ?? ($wireProcess instanceof Process ? $wireProcess : null);
		$processViewData = ($currentProcess instanceof Process && method_exists($currentProcess, 'inertiaViewData'))
			? (array) $currentProcess->inertiaViewData($pageObject)
			: [];
		$processWithViewData = ($currentProcess instanceof Process && method_exists($currentProcess, 'inertiaWithViewData'))
			? (array) $currentProcess->inertiaWithViewData($pageObject)
			: [];

		$vars = PropResolver::mergeViewData(
			$sharedViewData,
			$pageViewData,
			$pageWithViewData,
			$processViewData,
			$processWithViewData,
			(array) $pageResponse->getViewData()
		);

		$reservedFuel = is_object($wire->fuel) && method_exists($wire->fuel, 'getArray')
			? array_keys($wire->fuel->getArray())
			: [];
		foreach (array_keys($vars) as $varKey) {
			if (
				!is_string($varKey)
				|| preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $varKey) !== 1
				|| in_array($varKey, self::BLOCKED_VIEW_KEYS, true)
				|| ($varKey !== 'inertia' && in_array($varKey, $reservedFuel, true))
			) {
				unset($vars[$varKey]);
			}
		}

		foreach ($vars as $varKey => $varVal) {
			if ($varVal instanceof Closure) {
				$vars[$varKey] = $varVal($pageObject);
			}
		}

		$locale = isset($vars['locale']) && is_scalar($vars['locale']) && trim((string) $vars['locale']) !== ''
			? trim((string) $vars['locale'])
			: 'en';

		$ssrHead = ($ssr !== null && $ssr->headMarkup() !== '') ? $ssr->headMarkup() : '';
		$rawCustomHead = $vars['head'] ?? '';
		if (is_array($rawCustomHead)) {
			$customHead = implode("\n\t", array_values(array_filter(
				array_map(
					static fn(mixed $item): string => is_scalar($item)
						? (string) preg_replace('/<\/head\b/i', '&lt;/head', trim((string) $item))
						: '',
					$rawCustomHead
				),
				static fn(string $item): bool => $item !== ''
			)));
		} else {
			$customHead = is_scalar($rawCustomHead)
				? (string) preg_replace('/<\/head\b/i', '&lt;/head', trim((string) $rawCustomHead))
				: '';
		}
		$headParts = array_values(array_filter([$ssrHead, $customHead], static fn(string $part): bool => $part !== ''));

		$vars['locale'] = $locale;
		$vars['head'] = implode("\n\t", $headParts);
		$vars['inertia'] = $this->module;
		$vars['pageObject'] = $pageObject;

		$allowedPaths = array_values(array_filter([
			is_string($realTemplatesDir) ? $realTemplatesDir : ($templatesPath !== '' ? $templatesPath : null),
			is_string($realSiteModulesDir) ? $realSiteModulesDir : ($siteModulesPath !== '' ? $siteModulesPath : null),
			is_string($realStubsDir) ? $realStubsDir : $this->stubsPath,
		]));

		if (is_file($targetFile)) {
			return (string) $files->render($targetFile, $vars, [
				'allowDotDot' => false,
				'allowedPaths' => $allowedPaths,
			]);
		}

		$safeLocale = $wire->sanitizer->entities($locale);

		return "<!DOCTYPE html>\n<html lang=\"{$safeLocale}\">\n<head>\n\t<meta charset=\"utf-8\" />\n\t<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />\n\t"
			. $vars['head']
			. "\n</head>\n<body>\n\t"
			. $this->module->app($pageObject)
			. "\n</body>\n</html>";
	}
}
