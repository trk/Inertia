<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Support;

use BackedEnum;
use Closure;
use JsonSerializable;
use ProcessWire\Field;
use ProcessWire\Fieldgroup;
use ProcessWire\Inputfield;
use ProcessWire\InputfieldPassword;
use ProcessWire\InputfieldWrapper;
use ProcessWire\Language;
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
use ProcessWire\Permission;
use ProcessWire\Role;
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

	/** @var array<class-string, Closure> */
	private array $transformers = [];

	private Request $activeRequest;

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
		$this->registry->wire($this);
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
	 *     props: array<string, mixed>,
	 *     deferredProps?: array<string, list<string>>,
	 *     mergeProps?: list<string>,
	 *     deepMergeProps?: list<string>,
	 *     prependProps?: list<string>,
	 *     matchPropsOn?: list<string>,
	 *     onceProps?: array<string, array{prop: string, expiresAt: int|null}>,
	 *     scrollProps?: array<string, array{pageName: string, previousPage: int|null, nextPage: int|null, currentPage: int, reset: bool}>
	 * }
	 */
	public function resolve(string $component, array $props, Request $request): array
	{
		$runner = clone $this;

		return $runner->executeResolve($component, $props, $request);
	}

	/**
	 * Resolve an arbitrary value (such as an `InertiaSerializable` payload or shared array) outside a full PageResponse.
	 */
	public function resolveValue(mixed $value, ?Request $request = null): mixed
	{
		$runner = clone $this;
		$runner->activeRequest = $request ?? new Request();

		return $runner->evaluateValue($value, '', true);
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
	 *     props: array<string, mixed>,
	 *     deferredProps?: array<string, list<string>>,
	 *     mergeProps?: list<string>,
	 *     deepMergeProps?: list<string>,
	 *     prependProps?: list<string>,
	 *     matchPropsOn?: list<string>,
	 *     onceProps?: array<string, array{prop: string, expiresAt: int|null}>,
	 *     scrollProps?: array<string, array{pageName: string, previousPage: int|null, nextPage: int|null, currentPage: int, reset: bool}>
	 * }
	 */
	private function executeResolve(string $component, array $props, Request $request): array
	{
		$expanded = self::expandDotNotation($props);

		$this->activeRequest = $request;
		$this->isPartial = $request->isPartial($component);
		$this->only = $this->isPartial ? $request->partialOnly() : [];
		$this->except = $this->isPartial ? $request->partialExcept() : [];
		$this->reset = $request->resetProps();
		$this->exceptOnce = $request->exceptOnceProps();
		$this->scrollIntent = $request->infiniteScrollMergeIntent();

		$this->deferredGroups = [];
		$this->mergeProps = [];
		$this->deepMergeProps = [];
		$this->prependProps = [];
		$this->matchPropsOn = [];
		$this->onceProps = [];
		$this->scrollProps = [];
		$this->componentViewData = [];
		$this->defaultOnceTtl = $this->resolveTemplateCacheTtl();

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
	private function collectMetadata(array $items, string $prefix): void
	{
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
				if (!$this->isExcludedFromPartial($path)) {
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
				$this->collectMetadata($value, $path);
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
			if (!is_string($key) || !str_contains($key, '.')) {
				if (is_string($key) && isset($result[$key]) && is_array($result[$key]) && is_array($value)) {
					$result[$key] = array_replace_recursive($result[$key], $value);
				} else {
					$result[$key] = $value;
				}
				continue;
			}

			$segments = explode('.', $key);
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
	private function evaluateArray(array $items, string $prefix, bool $bypassPartialFilter): array
	{
		$isList = array_is_list($items);
		$resolved = [];

		foreach ($items as $key => $value) {
			$keyStr = (string) $key;
			$path = $prefix === '' ? $keyStr : $prefix . '.' . $keyStr;
			$isAlways = $value instanceof AlwaysProp || $bypassPartialFilter || self::containsAlwaysProp($value);

			if (!$isAlways) {
				// OptionalProp and DeferredProp are ONLY evaluated when explicitly requested in `$only` during a partial reload
				if (($value instanceof OptionalProp || $value instanceof DeferredProp) && !$this->isExplicitlyRequestedInPartial($path)) {
					continue;
				}

				// OnceProp (or MergeProp with `->once()`) is skipped when its tracking key or prop path
				// is present in `X-Inertia-Except-Once-Props`, unless `->fresh(true)` is set or it is explicitly requested in `$only`.
				if (
					($value instanceof OnceProp || $value instanceof MergeProp)
					&& $this->shouldSkipOnce($value, $path)
				) {
					continue;
				}

				if ($this->isExcludedFromPartial($path)) {
					continue;
				}
			}

			$resolved[$key] = $this->evaluateValue($value, $path, $value instanceof AlwaysProp || $bypassPartialFilter);
		}

		return $isList ? array_values($resolved) : $resolved;
	}

	/**
	 * Evaluate a single prop value to its JSON-ready representation.
	 */
	private function evaluateValue(mixed $value, string $path, bool $bypassPartialFilter): mixed
	{
		while ($value instanceof PropInterface || $value instanceof Closure) {
			if ($value instanceof AlwaysProp) {
				$bypassPartialFilter = true;
			}
			if ($value instanceof PropInterface) {
				if (
					($value instanceof OnceProp || $value instanceof DeferredProp || $value instanceof MergeProp || $value instanceof OptionalProp)
					&& $value->isOnce()
				) {
					return $this->evaluateOncePropWithCache($value, $path, $bypassPartialFilter);
				}

				$value = $value($this->activeRequest);
			} else {
				$value = $value();
			}
		}

		if (is_object($value)) {
			// 1. Custom registered transformers take priority
			foreach ($this->transformers as $targetClass => $callback) {
				if (is_a($value, $targetClass)) {
					return $this->evaluateValue($callback($value), $path, $bypassPartialFilter);
				}
			}

			// 2. InertiaSerializable contract, PHP Enums, ProcessWire User/Page/Media objects (via ProviderRegistry), and JsonSerializable
			if ($value instanceof InertiaSerializable) {
				$this->collectObjectViewData($value);

				return $this->evaluateValue($value->toInertiaProp(), $path, $bypassPartialFilter);
			}

			if ($value instanceof BackedEnum) {
				return $value->value;
			}

			if ($value instanceof UnitEnum) {
				return $value->name;
			}

			if ($value instanceof NullPage) {
				return null;
			}

			if ($value instanceof User) {
				return $this->evaluateValue($this->registry->provideUser($value), $path, $bypassPartialFilter);
			}

			if ($value instanceof Language) {
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
				return [
					'id' => (int) $value->id,
					'name' => (string) $value->name,
					'title' => (string) $value->get('title|name'),
				];
			}

			if ($value instanceof Pageimage) {
				return $this->evaluateValue($this->registry->provideImage($value), $path, $bypassPartialFilter);
			}

			if ($value instanceof Pagefile) {
				return $this->evaluateValue($this->registry->provideFile($value), $path, $bypassPartialFilter);
			}

			if ($value instanceof InputfieldWrapper) {
				return $this->serializeInputfieldWrapper($value, $path, $bypassPartialFilter);
			}

			if ($value instanceof Inputfield) {
				return $this->serializeStandardInputfield($value, $path, $bypassPartialFilter);
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
				return $this->serializeNotice($value);
			}

			if ($value instanceof Notices) {
				$notices = [];
				foreach ($value as $noticeItem) {
					if ($noticeItem instanceof Notice) {
						$notices[] = $this->serializeNotice($noticeItem);
					}
				}

				return $notices;
			}

			$objectProvider = $this->registry->resolveObjectProvider($value);
			if ($objectProvider !== null) {
				return $this->evaluateValue($objectProvider->transform($value), $path, $bypassPartialFilter);
			}

			if ($value instanceof Page) {
				/** @var Page|null $currentPage */
				$currentPage = $this->wire()->page;
				if (!TemplateDataProvider::isAccessiblePage($value, true, $currentPage instanceof Page ? $currentPage : null)) {
					return null;
				}

				return $this->evaluateValue($this->registry->providePage($value), $path, $bypassPartialFilter);
			}

			if ($value instanceof PageArray) {
				$items = [];
				foreach ($value as $item) {
					if ($item instanceof Page && !TemplateDataProvider::isAccessiblePage($item)) {
						continue;
					}
					$items[] = $item;
				}

				return $this->evaluateArray($items, $path, $bypassPartialFilter);
			}

			if ($value instanceof Pagefiles) {
				$items = [];
				foreach ($value as $item) {
					if ($item instanceof Pagefile && !MediaFieldDataProvider::isAccessiblePagefile($item)) {
						continue;
					}
					$items[] = $item;
				}

				return $this->evaluateArray($items, $path, $bypassPartialFilter);
			}

			if ($value instanceof WireArray) {
				$items = [];
				foreach ($value as $item) {
					if ($item instanceof Page && !TemplateDataProvider::isAccessiblePage($item)) {
						continue;
					}
					$items[] = $item;
				}

				return $this->evaluateArray($items, $path, $bypassPartialFilter);
			}

			if ($value instanceof JsonSerializable) {
				return $this->evaluateValue($value->jsonSerialize(), $path, $bypassPartialFilter);
			}

			if ($value instanceof WireData) {
				return $this->evaluateValue($value->getArray(), $path, $bypassPartialFilter);
			}
		}

		if (is_array($value)) {
			return $this->evaluateArray($value, $path, $bypassPartialFilter);
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
	private function serializeInputfieldWrapper(InputfieldWrapper $wrapper, string $path, bool $bypassPartialFilter): array
	{
		$this->collectObjectViewData($wrapper);
		$fields = [];
		foreach ($wrapper->children() as $child) {
			if (!$child instanceof Inputfield) {
				continue;
			}
			$childKey = (string) ($child->attr('name') ?: $child->className());
			$childPath = $path === '' ? $childKey : $path . '.' . $childKey;
			$fields[] = $this->evaluateValue($child, $childPath, $bypassPartialFilter);
		}

		return [
			'component' => $wrapper->className(),
			'id' => (string) $wrapper->attr('id'),
			'name' => (string) $wrapper->attr('name'),
			'label' => (string) $wrapper->label,
			'description' => (string) $wrapper->description,
			'notes' => (string) $wrapper->notes,
			'icon' => (string) $wrapper->icon,
			'action' => (string) $wrapper->attr('action'),
			'method' => (string) ($wrapper->attr('method') ?: 'post'),
			'collapsed' => (int) $wrapper->collapsed,
			'columnWidth' => (int) ($wrapper->columnWidth ?: 100),
			'showIf' => (string) $wrapper->showIf,
			'fields' => $fields,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function serializeStandardInputfield(Inputfield $field, string $path, bool $bypassPartialFilter): array
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
		$isPassword = $field instanceof InputfieldPassword
			|| strtolower($typeName) === 'password'
			|| strtolower((string) $field->attr('type')) === 'password'
			|| strtolower((string) $field->attr('name')) === 'pass';
		$rawValue = $isPassword ? '' : $field->attr('value');

		return [
			'component' => $field->className(),
			'type' => $typeName,
			'id' => (string) $field->attr('id'),
			'name' => (string) $field->attr('name'),
			'label' => (string) $field->label,
			'description' => (string) $field->description,
			'notes' => (string) $field->notes,
			'icon' => (string) $field->icon,
			'value' => $this->evaluateValue($rawValue, $path, $bypassPartialFilter),
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
		$rawText = trim((string) $notice->text);
		$safeText = $allowMarkup
			? $rawText
			: $this->wire()->sanitizer->entities($rawText);

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

	/**
	 * Evaluate a `OnceProp` (or any prop with `->once()`), caching its resolved JSON-ready value in `WireCache`
	 * when an explicit `->until(...)` or active `$page->template->cache_time` TTL is configured.
	 */
	private function evaluateOncePropWithCache(
		OnceProp|DeferredProp|MergeProp|OptionalProp $prop,
		string $path,
		bool $bypassPartialFilter
	): mixed {
		$trackingKey = $prop->key() ?? $path;
		$effectiveTtl = $prop->ttlSeconds($this->defaultOnceTtl);
		$cacheKey = $this->buildOnceCacheKey($trackingKey);

		if ($effectiveTtl !== null && $effectiveTtl > 0 && $cacheKey !== null && !$prop->isFresh()) {
			try {
				$cache = $this->wire()->cache;
				$cached = $cache->getFor(self::ONCE_CACHE_NAMESPACE, $cacheKey);
				if (is_array($cached) && ($cached['__inertia_once'] ?? false) === true && array_key_exists('value', $cached)) {
					return $cached['value'];
				}
			} catch (Throwable) {
				// Ignore cache read failure and evaluate directly
			}
		}

		$raw = $prop($this->activeRequest);
		$evaluated = $this->evaluateValue($raw, $path, $bypassPartialFilter);

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
			} catch (Throwable) {
				// Ignore cache write failure
			}
		}

		return $evaluated;
	}

	/**
	 * Build a per-page, per-language, per-urlSegment/pageNum `WireCache` key for the current `$page`,
	 * honoring ProcessWire's native `PageRender::getCacheFile()` secondary ID dimensions.
	 */
	private function buildOnceCacheKey(string $trackingKey): ?string
	{
		try {
			$wire = $this->wire();
			/** @var Page|null $page */
			$page = $wire->page;
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

			$variant = '';
			$input = $wire->input;
			$urlSegmentStr = trim((string) $input->urlSegmentStr, '/');
			if ($urlSegmentStr !== '') {
				$variant .= ':u_' . (preg_replace('/[^a-zA-Z0-9_-]/', '_', $urlSegmentStr) ?? md5($urlSegmentStr));
			}

			$pageNum = (int) $input->pageNum();
			if ($pageNum > 1) {
				$variant .= ":n{$pageNum}";
			}

			$safeKey = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $trackingKey) ?? $trackingKey;

			return "p{$page->id}:l{$langId}{$authSegment}{$variant}:{$safeKey}";
		} catch (Throwable) {
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
			$targetPage = $page ?? $wire->page;
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
		} catch (Throwable) {
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
					$matchedIds = $wire->pages->findIDs($selector, ['findHidden' => true]);
					foreach ($matchedIds as $id) {
						$intId = (int) $id;
						if ($intId > 0) {
							$pageIds[] = $intId;
						}
					}
				}
			}

			foreach (array_unique($pageIds) as $id) {
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

	private static function qualifySubPath(string $baseKey, string $subPath): string
	{
		$cleanSub = ltrim($subPath, '.');
		$prefix = $baseKey . '.';

		return ($cleanSub === $baseKey || str_starts_with($cleanSub, $prefix))
			? $cleanSub
			: $prefix . $cleanSub;
	}

	private static function containsAlwaysProp(mixed $value): bool
	{
		if ($value instanceof AlwaysProp) {
			return true;
		}

		if (is_array($value) && !array_is_list($value)) {
			foreach ($value as $child) {
				if (self::containsAlwaysProp($child)) {
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
