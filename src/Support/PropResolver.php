<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Support;

use BackedEnum;
use Closure;
use JsonSerializable;
use ProcessWire\Config;
use ProcessWire\Field;
use ProcessWire\Fieldgroup;
use ProcessWire\Inertia;
use ProcessWire\Inputfield;
use ProcessWire\InputfieldPassword;
use ProcessWire\InputfieldWrapper;
use ProcessWire\Language;
use ProcessWire\Module;
use ProcessWire\Notice;
use ProcessWire\NoticeError;
use ProcessWire\Notices;
use ProcessWire\NoticeWarning;
use ProcessWire\NullPage;
use ProcessWire\Page;
use ProcessWire\PageArray;
use ProcessWire\Pagefile;
use ProcessWire\Pagefiles;
use ProcessWire\Pageimage;
use ProcessWire\PageRender;
use ProcessWire\Password;
use ProcessWire\Permission;
use ProcessWire\Role;
use ProcessWire\Session;
use ProcessWire\Template;
use ProcessWire\User;
use ProcessWire\Wire;
use ProcessWire\WireArray;
use ProcessWire\WireData;
use Totoglu\Inertia\Contracts\InertiaSerializable;
use Totoglu\Inertia\Props\AlwaysProp;
use Totoglu\Inertia\Props\DeferredProp;
use Totoglu\Inertia\Props\MergeProp;
use Totoglu\Inertia\Props\MergeStrategy;
use Totoglu\Inertia\Props\OnceProp;
use Totoglu\Inertia\Props\OptionalProp;
use Totoglu\Inertia\Props\PropInterface;
use Totoglu\Inertia\Props\ScrollProp;
use Totoglu\Inertia\Provider\Field\MediaFieldDataProvider;
use Totoglu\Inertia\Provider\ProviderRegistry;
use Totoglu\Inertia\Provider\TemplateDataProvider;
use Totoglu\Inertia\Request;
use Throwable;
use UnitEnum;

/**
 * Evaluates Inertia props (`DeferredProp`, `MergeProp`, `OnceProp`, `ScrollProp`, `OptionalProp`, `AlwaysProp`),
 * filters partial reloads, extracts protocol metadata, and serializes ProcessWire domain objects via `ProviderRegistry`.
 */
final class PropResolver extends Wire
{
	public const string ONCE_CACHE_NAMESPACE = 'inertia-once';

	private const int MAX_EVAL_DEPTH = 64;

	/** @var array<class-string, Closure> */
	private array $transformers = [];

	/** @var array<int, true> */
	private array $evaluatingObjects = [];

	private ?object $omitSentinel = null;

	private Request $activeRequest;

	private string $activeComponent = '';

	private ?Page $contextPage = null;

	private ?int $defaultOnceTtl = null;

	private bool $isPartial = false;

	/** @var list<string> */
	private array $only = [];

	/** @var list<string> */
	private array $except = [];

	/** @var list<string> */
	private array $reset = [];

	/** @var list<string> */
	private array $exceptOnce = [];

	private string $scrollIntent = 'append';

	/** @var array<string, list<string>> */
	private array $deferredGroups = [];

	/** @var list<string> */
	private array $mergeProps = [];

	/** @var list<string> */
	private array $deepMergeProps = [];

	/** @var list<string> */
	private array $prependProps = [];

	/** @var list<string> */
	private array $matchPropsOn = [];

	/** @var array<string, array{prop: string, expiresAt: int|null}> */
	private array $onceProps = [];

	/** @var array<string, array{pageName: string, previousPage: int|null, nextPage: int|null, currentPage: int, reset: bool}> */
	private array $scrollProps = [];

	/** @var array<string, mixed> View data contributed by embedded `MarkupInertia` / `InputfieldInertia` objects */
	private array $componentViewData = [];

	public function __construct(
		private readonly ProviderRegistry $registry = new ProviderRegistry()
	) {
		parent::__construct();
	}

	public function wired(): void
	{
		parent::wired();
		$this->wire($this->registry);
	}

	/**
	 * Clear all registered custom transformers.
	 */
	public function clearTransformers(): self
	{
		$this->transformers = [];

		return $this;
	}

	/**
	 * Register a custom class-targeted prop transformer callback.
	 *
	 * @param class-string $className
	 */
	public function addTransformer(string $className, callable $callback): self
	{
		if ($className !== '') {
			$this->transformers[$className] = Closure::fromCallable($callback);
		}

		return $this;
	}

	/**
	 * Resolve the complete Page Object payload (`props` + Inertia v3 protocol metadata keys).
	 *
	 * @param array<string, mixed> $props Merged shared and page-level props
	 * @return array{
	 *     props: array<int|string, mixed>,
	 *     componentViewData?: array<string, mixed>,
	 *     deferredProps?: array<string, list<string>>,
	 *     mergeProps?: list<string>,
	 *     deepMergeProps?: list<string>,
	 *     prependProps?: list<string>,
	 *     matchPropsOn?: list<string>,
	 *     onceProps?: array<string, array{prop: string, expiresAt: int|null}>,
	 *     scrollProps?: array<string, array{pageName: string, previousPage: int|null, nextPage: int|null, currentPage: int, reset: bool}>
	 * }
	 */
	public function resolve(
		string $component,
		array $props,
		Request $request,
		bool $ignoreExceptOnce = false,
		?Page $contextPage = null
	): array {
		$this->ensureModuleProvidersBooted();
		$runner = clone $this;

		return $runner->executeResolve($component, $props, $request, $ignoreExceptOnce, $contextPage);
	}

	/**
	 * Resolve an arbitrary value (such as an `InertiaSerializable` payload or shared array) outside a full PageResponse.
	 */
	public function resolveValue(mixed $value, ?Request $request = null): mixed
	{
		$inertia = $this->ensureModuleProvidersBooted();
		$runner = clone $this;
		if ($request === null) {
			if ($inertia instanceof Inertia) {
				$request = $inertia->request;
			} else {
				$request = new Request();
				$this->wire($request);
			}
		}
		$currentPage = $this->wire()->page;
		$contextPage = $currentPage instanceof Page && $currentPage->id > 0 ? $currentPage : null;
		$runner->activeRequest = $request;
		$runner->contextPage = $contextPage;
		$runner->defaultOnceTtl = $runner->resolveTemplateCacheTtl($contextPage);
		$runner->evaluatingObjects = [];
		$runner->omitSentinel = new \stdClass();

		return $runner->evaluateValue($value, '', true);
	}

	private function ensureModuleProvidersBooted(): ?Inertia
	{
		$inertia = $this->wire()->inertia;
		if ($inertia instanceof Inertia && $inertia->resolver === $this) {
			$inertia->providers();

			return $inertia;
		}

		return null;
	}

	/**
	 * Invoke a prop closure, injecting `$request` only when the callback's first parameter accepts a `Request` instance.
	 */
	public static function invokeCallback(Closure $callback, ?Request $request = null): mixed
	{
		$ref = new \ReflectionFunction($callback);
		$params = $ref->getParameters();
		if ($params === []) {
			return $callback();
		}

		$first = $params[0];
		if ($first->isVariadic()) {
			return $callback();
		}

		$type = $first->getType();
		$acceptsRequest = false;
		if ($type === null) {
			$acceptsRequest = !$ref->isInternal();
		} elseif ($type instanceof \ReflectionNamedType) {
			$typeName = $type->getName();
			$acceptsRequest = $typeName === 'mixed' || $typeName === 'object' || is_a(Request::class, $typeName, true);
		} elseif ($type instanceof \ReflectionUnionType) {
			foreach ($type->getTypes() as $member) {
				if ($member instanceof \ReflectionNamedType) {
					$typeName = $member->getName();
					if ($typeName === 'mixed' || $typeName === 'object' || is_a(Request::class, $typeName, true)) {
						$acceptsRequest = true;
						break;
					}
				}
			}
		} elseif ($type instanceof \ReflectionIntersectionType) {
			$acceptsRequest = true;
			foreach ($type->getTypes() as $member) {
				if (!$member instanceof \ReflectionNamedType || !is_a(Request::class, $member->getName(), true)) {
					$acceptsRequest = false;
					break;
				}
			}
		}

		if ($acceptsRequest) {
			if ($request !== null) {
				return $callback($request);
			}
			if ($first->isOptional()) {
				return $callback();
			}
			if ($first->allowsNull()) {
				return $callback(null);
			}
		}

		return $callback();
	}

	/**
	 * Merge multiple `viewData` associative arrays, concatenating and deduplicating list-type asset keys (`scripts`, `styles`).
	 *
	 * @param array<string, mixed> ...$datasets
	 * @return array<string, mixed>
	 */
	public static function mergeViewData(array ...$datasets): array
	{
		$merged = [];
		foreach ($datasets as $dataset) {
			foreach ($dataset as $key => $value) {
				if (
					($key === 'scripts' || $key === 'styles')
					&& is_array($value)
					&& isset($merged[$key])
					&& is_array($merged[$key])
				) {
					$merged[$key] = array_values(array_unique(array_merge($merged[$key], $value)));
				} else {
					$merged[$key] = $value;
				}
			}
		}

		return $merged;
	}

	/**
	 * @param array<string, mixed> $props
	 * @return array{
	 *     props: array<int|string, mixed>,
	 *     componentViewData?: array<string, mixed>,
	 *     deferredProps?: array<string, list<string>>,
	 *     mergeProps?: list<string>,
	 *     deepMergeProps?: list<string>,
	 *     prependProps?: list<string>,
	 *     matchPropsOn?: list<string>,
	 *     onceProps?: array<string, array{prop: string, expiresAt: int|null}>,
	 *     scrollProps?: array<string, array{pageName: string, previousPage: int|null, nextPage: int|null, currentPage: int, reset: bool}>
	 * }
	 */
	private function executeResolve(
		string $component,
		array $props,
		Request $request,
		bool $ignoreExceptOnce = false,
		?Page $contextPage = null
	): array {
		$expanded = self::expandDotNotation($props);

		$isInertia = $request->isInertia();

		$this->activeRequest = $request;
		$this->activeComponent = $component;
		$this->contextPage = $contextPage;
		$this->isPartial = $isInertia && $request->isPartial($component);
		$this->only = $this->isPartial ? $request->partialOnly() : [];
		$this->except = $this->isPartial ? $request->partialExcept() : [];
		$this->reset = $isInertia ? $request->resetProps() : [];
		$this->exceptOnce = (!$isInertia || $ignoreExceptOnce) ? [] : $request->exceptOnceProps();
		$this->scrollIntent = $isInertia ? $request->infiniteScrollMergeIntent() : 'append';

		$this->deferredGroups = [];
		$this->mergeProps = [];
		$this->deepMergeProps = [];
		$this->prependProps = [];
		$this->matchPropsOn = [];
		$this->onceProps = [];
		$this->scrollProps = [];
		$this->componentViewData = [];
		$this->evaluatingObjects = [];
		$this->omitSentinel = new \stdClass();
		$this->defaultOnceTtl = $this->resolveTemplateCacheTtl($contextPage);

		// 1. Collect Deferred, Merge, Once, and Scroll metadata across top-level and dot-expanded nested arrays
		$this->collectMetadata($expanded, '');

		// 2. Filter and evaluate props recursively
		$resolvedProps = $this->evaluateArray($expanded, '', false);

		$result = [
			'props' => $resolvedProps,
		];

		if ($this->componentViewData !== []) {
			$result['componentViewData'] = $this->componentViewData;
		}

		if ($this->deferredGroups !== []) {
			$result['deferredProps'] = $this->deferredGroups;
		}

		if ($this->mergeProps !== []) {
			$result['mergeProps'] = array_values(array_unique($this->mergeProps));
		}

		if ($this->deepMergeProps !== []) {
			$result['deepMergeProps'] = array_values(array_unique($this->deepMergeProps));
		}

		if ($this->prependProps !== []) {
			$result['prependProps'] = array_values(array_unique($this->prependProps));
		}

		if ($this->matchPropsOn !== []) {
			$result['matchPropsOn'] = array_values(array_unique($this->matchPropsOn));
		}

		if ($this->onceProps !== []) {
			$result['onceProps'] = $this->onceProps;
		}

		if ($this->scrollProps !== []) {
			$result['scrollProps'] = $this->scrollProps;
		}

		return $result;
	}

	/**
	 * Recursively collect `DeferredProp`, `MergeProp`, `OnceProp`, and `ScrollProp` metadata across the prop tree.
	 *
	 * @param array<int|string, mixed> $items
	 */
	private function collectMetadata(array $items, string $prefix, int $depth = 0): void
	{
		if ($depth >= self::MAX_EVAL_DEPTH) {
			return;
		}

		foreach ($items as $key => $value) {
			if (!is_string($key)) {
				continue;
			}

			$path = $prefix === '' ? $key : $prefix . '.' . $key;

			if ($value instanceof DeferredProp) {
				$skippedByExceptOnce = $this->shouldSkipOnce($value, $path);

				if (!$this->isPartial && !$skippedByExceptOnce) {
					$this->deferredGroups[$value->group()][] = $path;
				}

				$includedInPartial = $this->isIncludedInStandardOrRequestedPartial($path);

				if ($includedInPartial && !$skippedByExceptOnce && $value->shouldMerge() && !$this->isResetPath($path)) {
					$this->recordMergeMetadata($path, $value);
				}

				if ($includedInPartial && $value->isOnce()) {
					$this->recordOnceMetadata($value, $path);
				}
			} elseif ($value instanceof MergeProp) {
				$excludedFromPartial = $this->isExcludedFromPartial($path);

				if (!$excludedFromPartial && !$this->shouldSkipOnce($value, $path) && !$this->isResetPath($path)) {
					$this->recordMergeMetadata($path, $value);
				}

				if (!$excludedFromPartial && $value->isOnce()) {
					$this->recordOnceMetadata($value, $path);
				}
			} elseif ($value instanceof OptionalProp) {
				if ($this->isIncludedInStandardOrRequestedPartial($path) && $value->isOnce()) {
					$this->recordOnceMetadata($value, $path);
				}
			} elseif ($value instanceof OnceProp) {
				if (!$this->isExcludedFromPartial($path) && $value->isOnce()) {
					$this->recordOnceMetadata($value, $path);
				}
			} elseif ($value instanceof ScrollProp) {
				$dataPath = $path . '.' . $value->wrapper();
				if (!$this->isExcludedFromPartial($path) && !$this->isExcludedFromPartial($dataPath)) {
					$isReset = $this->isResetPath($path) || $this->isResetPath($dataPath);
					$this->scrollProps[$path] = $value->metadata($isReset, $this->activeRequest);

					if (!$isReset) {
						if ($this->scrollIntent === 'prepend') {
							$this->prependProps[] = $dataPath;
						} else {
							$this->mergeProps[] = $dataPath;
						}

						foreach ($value->matchesOn() as $matchField) {
							$this->matchPropsOn[] = self::qualifySubPath($dataPath, $matchField);
						}
					}
				}
			} elseif (is_array($value) && !array_is_list($value)) {
				$this->collectMetadata($value, $path, $depth + 1);
			}
		}
	}

	/**
	 * Expand dot-notated top-level keys (e.g. `'auth.user' => $user`) into nested arrays.
	 *
	 * @param array<string, mixed> $props
	 * @return array<string, mixed>
	 */
	private static function expandDotNotation(array $props): array
	{
		$result = [];

		foreach ($props as $key => $value) {
			if (is_string($key) && in_array($key, ['__proto__', 'constructor', 'prototype'], true)) {
				continue;
			}

			if (!is_string($key) || !str_contains($key, '.')) {
				if (is_string($key) && isset($result[$key]) && is_array($result[$key]) && is_array($value)) {
					$result[$key] = array_replace_recursive($result[$key], $value);
				} else {
					$result[$key] = $value;
				}
				continue;
			}

			$segments = explode('.', $key);
			if (count($segments) > 32) {
				continue;
			}

			$hasForbiddenSegment = false;
			foreach ($segments as $segment) {
				if ($segment === '' || in_array($segment, ['__proto__', 'constructor', 'prototype'], true)) {
					$hasForbiddenSegment = true;
					break;
				}
			}
			if ($hasForbiddenSegment) {
				continue;
			}

			$cursor = &$result;

			$lastIndex = count($segments) - 1;
			foreach ($segments as $i => $segment) {
				if ($i === $lastIndex) {
					if (isset($cursor[$segment]) && is_array($cursor[$segment]) && is_array($value)) {
						$cursor[$segment] = array_replace_recursive($cursor[$segment], $value);
					} else {
						$cursor[$segment] = $value;
					}
				} else {
					if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
						$cursor[$segment] = [];
					}
					$cursor = &$cursor[$segment];
				}
			}
			unset($cursor);
		}

		return $result;
	}

	/**
	 * Recursively filter and evaluate prop arrays, closures, and object transformers.
	 *
	 * @param array<int|string, mixed> $items
	 * @return array<int|string, mixed>
	 */
	private function evaluateArray(array $items, string $prefix, bool $bypassPartialFilter, int $depth = 0): array
	{
		$result = $this->evaluateArrayInternal($items, $prefix, $bypassPartialFilter, $depth);

		return is_array($result) ? $result : [];
	}

	/**
	 * @param array<int|string, mixed> $items
	 * @return array<int|string, mixed>|object
	 */
	private function evaluateArrayInternal(array $items, string $prefix, bool $bypassPartialFilter, int $depth = 0): array|object
	{
		if ($depth >= self::MAX_EVAL_DEPTH) {
			return [];
		}

		$isList = array_is_list($items);
		$resolved = [];
		$filteredByProtocol = false;

		foreach ($items as $key => $value) {
			if (!$isList && is_string($key) && in_array($key, ['__proto__', 'constructor', 'prototype'], true)) {
				continue;
			}
			$keyStr = (string) $key;
			$path = $prefix === '' ? $keyStr : $prefix . '.' . $keyStr;
			$isAlways = $value instanceof AlwaysProp || $bypassPartialFilter || self::containsAlwaysProp($value);

			if (!$isAlways) {
				// OptionalProp and DeferredProp are ONLY evaluated when explicitly requested in `$only` during a partial reload
				if (($value instanceof OptionalProp || $value instanceof DeferredProp) && !$this->isExplicitlyRequestedInPartial($path)) {
					$filteredByProtocol = true;
					continue;
				}

				// OnceProp (or MergeProp with `->once()`) is skipped when its tracking key or prop path
				// is present in `X-Inertia-Except-Once-Props`, unless `->fresh(true)` is set or it is explicitly requested in `$only`.
				if (
					($value instanceof OnceProp || $value instanceof MergeProp)
					&& $this->shouldSkipOnce($value, $path)
				) {
					$filteredByProtocol = true;
					continue;
				}

				if ($this->isExcludedFromPartial($path)) {
					$filteredByProtocol = true;
					continue;
				}
			}

			$evaluatedChild = $this->evaluateValueInternal($value, $path, $value instanceof AlwaysProp || $bypassPartialFilter, $depth + 1);
			if ($this->omitSentinel !== null && $evaluatedChild === $this->omitSentinel) {
				if ($isList) {
					$resolved[$key] = [];
				} else {
					$filteredByProtocol = true;
				}
				continue;
			}

			$resolved[$key] = $evaluatedChild;
		}

		if ($prefix !== '' && !$isList && $items !== [] && $resolved === [] && $filteredByProtocol && $this->omitSentinel !== null) {
			return $this->omitSentinel;
		}

		return $isList ? array_values($resolved) : $resolved;
	}

	/**
	 * Evaluate a single prop value to its JSON-ready representation.
	 */
	private function evaluateValue(mixed $value, string $path, bool $bypassPartialFilter, int $depth = 0): mixed
	{
		$result = $this->evaluateValueInternal($value, $path, $bypassPartialFilter, $depth);

		return ($this->omitSentinel !== null && $result === $this->omitSentinel) ? [] : $result;
	}

	private function evaluateValueInternal(mixed $value, string $path, bool $bypassPartialFilter, int $depth = 0): mixed
	{
		if ($depth >= self::MAX_EVAL_DEPTH) {
			return null;
		}

		$unwrapCount = 0;
		while ($value instanceof PropInterface || $value instanceof Closure) {
			if (++$unwrapCount > self::MAX_EVAL_DEPTH) {
				return null;
			}
			if ($value instanceof AlwaysProp) {
				$bypassPartialFilter = true;
			}
			if ($value instanceof PropInterface) {
				if (
					($value instanceof OnceProp || $value instanceof DeferredProp || $value instanceof MergeProp || $value instanceof OptionalProp)
					&& $value->isOnce()
				) {
					return $this->evaluateOncePropWithCache($value, $path, $bypassPartialFilter, $depth + 1);
				}

				$value = $value($this->activeRequest);
			} else {
				$value = self::invokeCallback($value, $this->activeRequest);
			}
		}

		if (is_float($value) && !is_finite($value)) {
			return null;
		}

		if (is_object($value)) {
			if ($value instanceof BackedEnum) {
				return $value->value;
			}

			if ($value instanceof UnitEnum) {
				return $value->name;
			}

			if ($value instanceof NullPage) {
				return null;
			}

			$objId = spl_object_id($value);
			if (isset($this->evaluatingObjects[$objId])) {
				return null;
			}

			$this->evaluatingObjects[$objId] = true;
			try {
				// 1. Custom registered transformers take priority
				foreach ($this->transformers as $targetClass => $callback) {
					if (is_a($value, $targetClass)) {
						return $this->evaluateValue($callback($value), $path, $bypassPartialFilter, $depth + 1);
					}
				}

				// 2. InertiaSerializable contract, ProcessWire User/Page/Media objects (via ProviderRegistry), and JsonSerializable
				if ($value instanceof InertiaSerializable) {
					$this->collectObjectViewData($value);

					return $this->evaluateValue($value->toInertiaProp(), $path, $bypassPartialFilter, $depth + 1);
				}

				if ($value instanceof User) {
					if ($value->id <= 0 || $value->isTrash() || $value->isUnpublished()) {
						return null;
					}

					return $this->evaluateValue($this->registry->provideUser($value), $path, $bypassPartialFilter, $depth + 1);
				}

				if ($value instanceof Language) {
					if ($value->id <= 0 || $value->isTrash()) {
						return null;
					}
					$user = $this->wire()->user;
					$userLang = $user instanceof User ? $user->language : null;

					return [
						'id' => (int) $value->id,
						'name' => (string) $value->name,
						'title' => (string) $value->get('title|name'),
						'isDefault' => (bool) $value->isDefault(),
						'isCurrent' => $userLang instanceof Language && (int) $userLang->id === (int) $value->id,
					];
				}

				if ($value instanceof Role || $value instanceof Permission) {
					if ($value->id <= 0 || $value->isTrash()) {
						return null;
					}

					return [
						'id' => (int) $value->id,
						'name' => (string) $value->name,
						'title' => (string) $value->get('title|name'),
					];
				}

				if ($value instanceof Pageimage) {
					return $this->evaluateValue($this->registry->provideImage($value, $this->contextPage), $path, $bypassPartialFilter, $depth + 1);
				}

				if ($value instanceof Pagefile) {
					return $this->evaluateValue($this->registry->provideFile($value, $this->contextPage), $path, $bypassPartialFilter, $depth + 1);
				}

				if ($value instanceof InputfieldWrapper) {
					return $this->serializeInputfieldWrapper($value, $path, $bypassPartialFilter, $depth + 1);
				}

				if ($value instanceof Inputfield) {
					return $this->serializeStandardInputfield($value, $path, $bypassPartialFilter, $depth + 1);
				}

				if ($value instanceof Field) {
					return [
						'id' => (int) $value->id,
						'name' => (string) $value->name,
						'label' => (string) ($value->label ?: $value->name),
						'type' => $value->type !== null ? $value->type->className() : '',
						'icon' => (string) $value->getIcon(),
						'flags' => (int) $value->flags,
					];
				}

				if ($value instanceof Template) {
					$fieldNames = [];
					if ($value->fieldgroup instanceof Fieldgroup) {
						foreach ($value->fieldgroup as $tplField) {
							if ($tplField instanceof Field) {
								$fieldNames[] = (string) $tplField->name;
							}
						}
					}

					return [
						'id' => (int) $value->id,
						'name' => (string) $value->name,
						'label' => (string) ($value->label ?: $value->name),
						'icon' => (string) $value->getIcon(),
						'flags' => (int) $value->flags,
						'pageClass' => (string) $value->pageClass,
						'fields' => $fieldNames,
					];
				}

				if ($value instanceof Notice) {
					if (!(bool) $this->wire()->config->debug && ((int) $value->flags & Notice::debug) !== 0) {
						return null;
					}

					return $this->serializeNotice($value);
				}

				if ($value instanceof Notices) {
					$isDebug = (bool) $this->wire()->config->debug;
					$notices = [];
					$visibleNotices = $value->getVisible();
					if (is_iterable($visibleNotices)) {
						foreach ($visibleNotices as $noticeItem) {
							if (!$noticeItem instanceof Notice) {
								continue;
							}
							if (!$isDebug && ((int) $noticeItem->flags & Notice::debug) !== 0) {
								continue;
							}
							$notices[] = $this->serializeNotice($noticeItem);
						}
					}

					return $notices;
				}

				$objectProvider = $this->registry->resolveObjectProvider($value);
				if ($objectProvider !== null) {
					return $this->evaluateValue($objectProvider->transform($value, null, $this->contextPage), $path, $bypassPartialFilter, $depth + 1);
				}

				if ($value instanceof Page) {
					/** @var Page|null $currentPage */
					$currentPage = $this->contextPage ?? $this->wire()->page;
					$effectiveCurrentPage = $currentPage instanceof Page ? $currentPage : null;
					if (!TemplateDataProvider::isAccessiblePage($value, true, $effectiveCurrentPage)) {
						return null;
					}

					return $this->evaluateValue($this->registry->providePage($value, $effectiveCurrentPage), $path, $bypassPartialFilter, $depth + 1);
				}

				if ($value instanceof PageArray) {
					$items = [];
					foreach ($value as $item) {
						if (!TemplateDataProvider::isAccessiblePage($item)) {
							continue;
						}
						$items[] = $item;
					}

					return $this->evaluateArray($items, $path, $bypassPartialFilter, $depth + 1);
				}

				if ($value instanceof Pagefiles) {
					$items = [];
					foreach ($value as $item) {
						if ($item instanceof Pagefile && !MediaFieldDataProvider::isAccessiblePagefile($item, $this->contextPage)) {
							continue;
						}
						$items[] = $item;
					}

					return $this->evaluateArray($items, $path, $bypassPartialFilter, $depth + 1);
				}

				if ($value instanceof WireArray) {
					$items = [];
					foreach ($value as $item) {
						if ($item instanceof Page && !TemplateDataProvider::isAccessiblePage($item)) {
							continue;
						}
						$items[] = $item;
					}

					return $this->evaluateArray($items, $path, $bypassPartialFilter, $depth + 1);
				}

				if ($value instanceof Config || $value instanceof Session || $value instanceof Password || $value instanceof Module) {
					return null;
				}

				if ($value instanceof JsonSerializable) {
					return $this->evaluateValue($value->jsonSerialize(), $path, $bypassPartialFilter, $depth + 1);
				}

				if ($value instanceof WireData) {
					return $this->evaluateValue($value->getArray(), $path, $bypassPartialFilter, $depth + 1);
				}
			} finally {
				unset($this->evaluatingObjects[$objId]);
			}
		}

		if (is_array($value)) {
			return $this->evaluateArrayInternal($value, $path, $bypassPartialFilter, $depth + 1);
		}

		return $value;
	}

	private function collectObjectViewData(object $value): void
	{
		$viewData = method_exists($value, 'inertiaViewData') ? (array) $value->inertiaViewData() : [];
		$withViewData = method_exists($value, 'inertiaWithViewData') ? (array) $value->inertiaWithViewData() : [];
		if ($viewData !== [] || $withViewData !== []) {
			$this->componentViewData = self::mergeViewData($this->componentViewData, $viewData, $withViewData);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function serializeInputfieldWrapper(InputfieldWrapper $wrapper, string $path, bool $bypassPartialFilter, int $depth = 0): array
	{
		$this->collectObjectViewData($wrapper);
		$fields = [];
		foreach ($wrapper->children() as $child) {
			if (!$child instanceof Inputfield) {
				continue;
			}
			$childAttrName = Request::scalarString($child->attr('name'));
			$childKey = $childAttrName !== '' ? $childAttrName : $child->className();
			$childPath = $path === '' ? $childKey : $path . '.' . $childKey;
			$fields[] = $this->evaluateValue($child, $childPath, $bypassPartialFilter, $depth + 1);
		}

		$methodAttr = Request::scalarString($wrapper->attr('method'));

		return [
			'component' => $wrapper->className(),
			'id' => Request::scalarString($wrapper->attr('id')),
			'name' => Request::scalarString($wrapper->attr('name')),
			'label' => (string) $wrapper->label,
			'description' => (string) $wrapper->description,
			'notes' => (string) $wrapper->notes,
			'icon' => (string) $wrapper->icon,
			'action' => Request::scalarString($wrapper->attr('action')),
			'method' => $methodAttr !== '' ? $methodAttr : 'post',
			'collapsed' => (int) $wrapper->collapsed,
			'columnWidth' => (int) ($wrapper->columnWidth ?: 100),
			'showIf' => (string) $wrapper->showIf,
			'fields' => $fields,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function serializeStandardInputfield(Inputfield $field, string $path, bool $bypassPartialFilter, int $depth = 0): array
	{
		$this->collectObjectViewData($field);
		$options = [];
		if (method_exists($field, 'getOptions')) {
			$rawOptions = $field->getOptions();
			if (is_array($rawOptions)) {
				$options = $rawOptions;
			}
		}

		$typeName = str_replace('Inputfield', '', $field->className());
		$fieldAttrType = Request::scalarString($field->attr('type'));
		$fieldAttrName = Request::scalarString($field->attr('name'));
		$isPassword = $field instanceof InputfieldPassword
			|| strtolower($typeName) === 'password'
			|| strtolower($fieldAttrType) === 'password'
			|| strtolower($fieldAttrName) === 'pass';
		$rawValue = $isPassword ? '' : $field->attr('value');

		return [
			'component' => $field->className(),
			'type' => $typeName,
			'id' => Request::scalarString($field->attr('id')),
			'name' => $fieldAttrName,
			'label' => (string) $field->label,
			'description' => (string) $field->description,
			'notes' => (string) $field->notes,
			'icon' => (string) $field->icon,
			'value' => $this->evaluateValue($rawValue, $path, $bypassPartialFilter, $depth + 1),
			'options' => $options,
			'required' => (bool) $field->required,
			'collapsed' => (int) $field->collapsed,
			'columnWidth' => (int) ($field->columnWidth ?: 100),
			'showIf' => (string) $field->showIf,
			'requiredIf' => (string) $field->requiredIf,
			'errors' => array_values(array_map('strval', $field->getErrors())),
		];
	}

	/**
	 * @return array{type: string, text: string, allowMarkup: bool, flags: int, class: string, timestamp: int}
	 */
	private function serializeNotice(Notice $notice): array
	{
		$flags = (int) $notice->flags;
		$allowMarkup = ($flags & Notice::allowMarkup) !== 0;
		$safeText = Inertia::formatNoticeText(
			Request::scalarString($notice->text),
			$flags,
			$this->wire()->sanitizer
		);

		return [
			'type' => $notice instanceof NoticeError ? 'error' : ($notice instanceof NoticeWarning ? 'warning' : 'message'),
			'text' => $safeText,
			'allowMarkup' => $allowMarkup,
			'flags' => $flags,
			'class' => (string) $notice->class,
			'timestamp' => (int) $notice->timestamp,
		];
	}

	private function recordOnceMetadata(OnceProp|DeferredProp|MergeProp|OptionalProp $prop, string $path): void
	{
		$trackingKey = $prop->key() ?? $path;
		$this->onceProps[$trackingKey] = [
			'prop' => $path,
			'expiresAt' => $prop->expiresAt($this->defaultOnceTtl),
		];
	}

	private function recordMergeMetadata(string $key, DeferredProp|MergeProp $prop): void
	{
		$appendPaths = $prop->appendPaths();
		$prependPaths = $prop->prependPaths();

		if ($appendPaths !== [] || $prependPaths !== []) {
			foreach ($appendPaths as $subPath) {
				$fullPath = self::qualifySubPath($key, $subPath);
				if (!$this->isResetPath($fullPath)) {
					$this->mergeProps[] = $fullPath;
				}
			}
			foreach ($prependPaths as $subPath) {
				$fullPath = self::qualifySubPath($key, $subPath);
				if (!$this->isResetPath($fullPath)) {
					$this->prependProps[] = $fullPath;
				}
			}
		} else {
			match ($prop->mergeStrategy()) {
				MergeStrategy::Deep => $this->deepMergeProps[] = $key,
				MergeStrategy::Prepend => $this->prependProps[] = $key,
				MergeStrategy::Append => $this->mergeProps[] = $key,
			};
		}

		foreach ($prop->matchesOn() as $matchField) {
			$fullMatchField = self::qualifySubPath($key, $matchField);
			if (!$this->isResetPath($fullMatchField)) {
				$this->matchPropsOn[] = $fullMatchField;
			}
		}
	}

	private function isIncludedInStandardOrRequestedPartial(string $path): bool
	{
		return !$this->isPartial || ($this->isExplicitlyRequestedInPartial($path) && !$this->isExcludedFromPartial($path));
	}

	private function isExplicitlyRequestedInPartial(string $path): bool
	{
		if (!$this->isPartial || $this->only === []) {
			return false;
		}

		foreach ($this->only as $pattern) {
			if ($pattern === $path || str_starts_with($pattern, $path . '.')) {
				return true;
			}
		}

		return false;
	}

	private function isExcludedFromPartial(string $path): bool
	{
		if (!$this->isPartial) {
			return false;
		}

		if ($this->only !== [] && !self::matchesNestedPath($path, $this->only)) {
			return true;
		}

		if ($this->except !== [] && self::matchesExactOrParent($path, $this->except)) {
			return true;
		}

		return false;
	}

	private function shouldSkipOnce(OnceProp|DeferredProp|MergeProp|OptionalProp $prop, string $path): bool
	{
		if (!$prop->isOnce() || $prop->isFresh() || $this->isExplicitlyRequestedInPartial($path)) {
			return false;
		}

		$trackingKey = $prop->key() ?? $path;

		return in_array($trackingKey, $this->exceptOnce, true) || in_array($path, $this->exceptOnce, true);
	}

	private function hasPartialSubPathFilter(string $path): bool
	{
		if ($path === '' || !$this->isPartial) {
			return false;
		}

		$prefix = $path . '.';
		foreach ($this->only as $pattern) {
			if (str_starts_with($pattern, $prefix)) {
				return true;
			}
		}
		foreach ($this->except as $pattern) {
			if (str_starts_with($pattern, $prefix)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Evaluate a `OnceProp` (or any prop with `->once()`), caching its resolved JSON-ready value in `WireCache`
	 * when an explicit `->until(...)` or active `$page->template->cache_time` TTL is configured.
	 */
	private function evaluateOncePropWithCache(
		OnceProp|DeferredProp|MergeProp|OptionalProp $prop,
		string $path,
		bool $bypassPartialFilter,
		int $depth = 0
	): mixed {
		$trackingKey = $prop->key() ?? $path;
		$effectiveTtl = $prop->ttlSeconds($this->defaultOnceTtl);
		$blocksPartialCache = $this->isPartial && (
			!($prop instanceof DeferredProp || $prop instanceof OptionalProp)
			|| $this->isResetPath($path)
			|| $this->hasPartialSubPathFilter($path)
		);
		$hasDynamicContext = $blocksPartialCache
			|| $this->activeRequest->isMutatingMethod()
			|| $this->activeRequest->json() !== []
			|| $this->activeRequest->getArray() !== []
			|| $_GET !== [];
		$cacheKey = $hasDynamicContext ? null : $this->buildOnceCacheKey($trackingKey);

		if ($effectiveTtl !== null && $effectiveTtl > 0 && $cacheKey !== null && !$prop->isFresh()) {
			try {
				$cache = $this->wire()->cache;
				$cached = $cache->getFor(self::ONCE_CACHE_NAMESPACE, $cacheKey);
				if (is_array($cached) && ($cached['__inertia_once'] ?? false) === true && array_key_exists('value', $cached)) {
					return $cached['value'];
				}
			} catch (Throwable $e) {
				$this->wire()->log->error('Inertia OnceProp cache read failed: ' . $e->getMessage());
			}
		}

		$raw = $prop($this->activeRequest);
		$evaluated = $this->evaluateValue($raw, $path, $bypassPartialFilter, $depth + 1);

		if ($effectiveTtl !== null && $effectiveTtl > 0 && $cacheKey !== null) {
			try {
				$this->wire()->cache->saveFor(
					self::ONCE_CACHE_NAMESPACE,
					$cacheKey,
					[
						'__inertia_once' => true,
						'value' => $evaluated,
					],
					$effectiveTtl
				);
			} catch (Throwable $e) {
				$this->wire()->log->error('Inertia OnceProp cache write failed: ' . $e->getMessage());
			}
		}

		return $evaluated;
	}

	/**
	 * Build a per-page, per-language, per-component, per-urlSegment/pageNum `WireCache` key for the current `$page`,
	 * honoring ProcessWire's native `PageRender::getCacheFile()` secondary ID dimensions.
	 */
	private function buildOnceCacheKey(string $trackingKey): ?string
	{
		try {
			$wire = $this->wire();
			/** @var Page|null $page */
			$page = $this->contextPage ?? $wire->page;
			if (!$page instanceof Page || $page->id <= 0) {
				return null;
			}

			/** @var User|null $user */
			$user = $wire->user;
			$langId = $user instanceof User && $user->language instanceof Language
				? (int) $user->language->id
				: 0;
			$authSegment = ($user instanceof User && $user->isLoggedin())
				? ':u' . (int) $user->id
				: ':guest';
			$roleSegment = '';
			if ($user instanceof User) {
				$roleIds = [];
				$rolesField = $user->get('roles');
				if (is_iterable($rolesField)) {
					foreach ($rolesField as $role) {
						if ($role instanceof Role && $role->id > 0) {
							$roleIds[] = (int) $role->id;
						}
					}
				}
				sort($roleIds);
				$roleSegment = implode(',', array_unique($roleIds));
			}

			$input = $wire->input;
			$config = $wire->config;
			$scheme = ((bool) $config->https) ? 'https' : 'http';
			$host = strtolower(trim((string) $config->httpHost));
			$urlSegmentStr = trim((string) $input->urlSegmentStr, '/');
			$pageNum = (int) $input->pageNum();
			$digest = hash(
				'xxh128',
				$scheme . '://' . $host
				. "\0" . $this->activeComponent
				. "\0" . $urlSegmentStr
				. "\0n" . $pageNum
				. "\0r" . $roleSegment
				. "\0" . $trackingKey
			);

			return "p{$page->id}:l{$langId}{$authSegment}:{$digest}";
		} catch (Throwable $e) {
			$this->wire()->log->error('Inertia OnceProp cache key resolution failed: ' . $e->getMessage());

			return null;
		}
	}

	/**
	 * Resolve the active `$page->template` cache TTL in seconds when template caching is allowed for the current request,
	 * delegating directly to ProcessWire's native `PageRender::isCacheAllowed()` (`cache_time`, `useCacheForUsers`, `noCacheGetVars`, `noCachePostVars`, `PageRenderNoCachePage`).
	 */
	public function resolveTemplateCacheTtl(?Page $page = null): ?int
	{
		try {
			$wire = $this->wire();
			/** @var Page|null $targetPage */
			$targetPage = $page ?? $this->contextPage ?? $wire->page;
			if (!$targetPage instanceof Page || $targetPage->id <= 0) {
				return null;
			}

			$template = $targetPage->template;
			if (!$template instanceof Template) {
				return null;
			}

			$cacheTime = (int) $template->cache_time;
			if ($cacheTime <= 0) {
				return null;
			}

			$pageRender = $wire->modules->get('PageRender');
			if ($pageRender instanceof PageRender && !$pageRender->isCacheAllowed($targetPage)) {
				return null;
			}

			return $cacheTime;
		} catch (Throwable $e) {
			$this->wire()->log->error('Inertia template cache TTL resolution failed: ' . $e->getMessage());

			return null;
		}
	}

	/**
	 * Clear `OnceProp` server-side `WireCache` entries according to `$page->template->cacheExpire`:
	 *  - `Template::cacheExpireNone`: do not expire on save; expire the page itself on delete/trash.
	 *  - `Template::cacheExpireSite`: expire all `inertia-once` entries across the entire site.
	 *  - `Template::cacheExpirePage`: expire entries for `$page->id`.
	 *  - `Template::cacheExpireParents`: expire entries for `$page->id` and all `$page->parents`.
	 *  - `Template::cacheExpireSpecific`: expire entries for `$page->id` and IDs in `$template->cacheExpirePages`.
	 *  - `Template::cacheExpireSelector`: expire entries for `$page->id` and IDs matching `$template->cacheExpireSelector`.
	 */
	public function clearOnceCacheForPage(Page $page, string $eventMethod = 'saved'): void
	{
		if ($page->id <= 0 || !$page->template instanceof Template) {
			return;
		}

		try {
			$template = $page->template;
			$cacheExpire = (int) $template->cacheExpire;

			if ($cacheExpire === Template::cacheExpireNone) {
				if ($eventMethod === 'saved') {
					return;
				}
				$cacheExpire = Template::cacheExpirePage;
			}

			$wire = $this->wire();
			$cache = $wire->cache;

			if ($cacheExpire === Template::cacheExpireSite) {
				$cache->deleteFor(self::ONCE_CACHE_NAMESPACE);

				return;
			}

			$pageIds = [(int) $page->id];

			if ($cacheExpire === Template::cacheExpireParents) {
				foreach ($page->parents as $parent) {
					if ($parent instanceof Page && $parent->id > 0) {
						$pageIds[] = (int) $parent->id;
					}
				}
			} elseif ($cacheExpire === Template::cacheExpireSpecific) {
				$specificIds = $template->cacheExpirePages;
				if (is_iterable($specificIds)) {
					foreach ($specificIds as $id) {
						$intId = $id instanceof Page ? (int) $id->id : (int) $id;
						if ($intId > 0) {
							$pageIds[] = $intId;
						}
					}
				}
			} elseif ($cacheExpire === Template::cacheExpireSelector) {
				$selector = trim((string) $template->cacheExpireSelector);
				if ($selector !== '') {
					if (!self::hasPositiveTopLevelLimit($selector)) {
						$selector .= ', limit=251';
					}
					$matchedIds = $wire->pages->findIDs($selector, ['findHidden' => true]);
					if (count($matchedIds) > 250) {
						$cache->deleteFor(self::ONCE_CACHE_NAMESPACE);

						return;
					}
					foreach ($matchedIds as $id) {
						$intId = (int) $id;
						if ($intId > 0) {
							$pageIds[] = $intId;
						}
					}
				}
			}

			$uniqueIds = array_unique($pageIds);
			if (count($uniqueIds) > 250) {
				$cache->deleteFor(self::ONCE_CACHE_NAMESPACE);

				return;
			}

			foreach ($uniqueIds as $id) {
				$cache->deleteFor(self::ONCE_CACHE_NAMESPACE, "p{$id}:*");
			}
		} catch (Throwable $e) {
			$this->wire()->log->error(sprintf($this->_('Inertia OnceProp cache clear failed: %s'), $e->getMessage()));
		}
	}

	private function isResetPath(string $path): bool
	{
		return $this->reset !== [] && self::matchesExactOrParent($path, $this->reset);
	}

	private static function hasPositiveTopLevelLimit(string $selector): bool
	{
		$depth = 0;
		$quote = '';
		$clause = '';
		$len = strlen($selector);

		for ($i = 0; $i <= $len; $i++) {
			$ch = $i < $len ? $selector[$i] : ',';
			if ($quote !== '') {
				if ($ch === $quote && ($i === 0 || $selector[$i - 1] !== '\\')) {
					$quote = '';
				}
				$clause .= $ch;
				continue;
			}
			if ($ch === '"' || $ch === "'") {
				$quote = $ch;
				$clause .= $ch;
				continue;
			}
			if ($ch === '[' || $ch === '(') {
				$depth++;
				$clause .= $ch;
				continue;
			}
			if ($ch === ']' || $ch === ')') {
				$depth = max(0, $depth - 1);
				$clause .= $ch;
				continue;
			}
			if ($ch === ',' && $depth === 0) {
				if (preg_match('/^\s*limit\s*=\s*([0-9]+)\s*$/i', $clause, $m) === 1 && (int) $m[1] >= 1) {
					return true;
				}
				$clause = '';
				continue;
			}
			$clause .= $ch;
		}

		return false;
	}

	private static function qualifySubPath(string $baseKey, string $subPath): string
	{
		$cleanSub = ltrim($subPath, '.');
		$prefix = $baseKey . '.';

		return ($cleanSub === $baseKey || str_starts_with($cleanSub, $prefix))
			? $cleanSub
			: $prefix . $cleanSub;
	}

	private static function containsAlwaysProp(mixed $value, int $depth = 0): bool
	{
		if ($depth >= self::MAX_EVAL_DEPTH) {
			return false;
		}

		if ($value instanceof AlwaysProp) {
			return true;
		}

		if (is_array($value) && !array_is_list($value)) {
			foreach ($value as $child) {
				if (self::containsAlwaysProp($child, $depth + 1)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check whether nested `$path` matches an entry in `$only` (either exact, parent of `$only`, or child of an `$only` entry).
	 *
	 * @param list<string> $only
	 */
	private static function matchesNestedPath(string $path, array $only): bool
	{
		foreach ($only as $pattern) {
			if (
				$pattern === $path
				|| str_starts_with($pattern, $path . '.')
				|| str_starts_with($path, $pattern . '.')
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether `$path` matches an entry in `$patterns` or is a child of a matched key.
	 *
	 * @param list<string> $patterns
	 */
	private static function matchesExactOrParent(string $path, array $patterns): bool
	{
		foreach ($patterns as $pattern) {
			if ($pattern === $path || str_starts_with($path, $pattern . '.')) {
				return true;
			}
		}

		return false;
	}
}
