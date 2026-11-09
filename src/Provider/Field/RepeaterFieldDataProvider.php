<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Provider\Field;

use ProcessWire\Field;
use ProcessWire\Fieldgroup;
use ProcessWire\NullPage;
use ProcessWire\Page;
use ProcessWire\PageArray;
use ProcessWire\Template;
use Totoglu\Inertia\Provider\FieldDataProvider;
use Totoglu\Inertia\Provider\ProviderRegistry;
use Totoglu\Inertia\Provider\TemplateDataProvider;

/**
 * Core FieldDataProvider for `FieldtypeRepeater` and `FieldtypeRepeaterMatrix`.
 *
 * Serializes repeater items with their full inner field values via `ProviderRegistry::providePage()`
 * (guarded against cyclic references) and generates strongly-typed `[PascalFieldName]RepeaterItem`
 * TypeScript interfaces from the underlying repeater template schema.
 */
final class RepeaterFieldDataProvider extends FieldDataProvider
{
	/**
	 * Track active repeater field names during TypeScript definition generation to prevent infinite recursion.
	 *
	 * @var array<string, true>
	 */
	private array $activeFieldNames = [];

	/**
	 * @return list<string>
	 */
	public function supports(): array
	{
		return [
			'FieldtypeRepeater',
			'FieldtypeRepeaterMatrix',
			'ProcessWire\\RepeaterPage',
			'ProcessWire\\RepeaterPageArray',
		];
	}

	public function transform(mixed $value, ?Field $field = null, ?Page $page = null): mixed
	{
		if ($value instanceof Page && !$value instanceof NullPage) {
			return self::isAccessibleRepeaterItem($value) ? $this->serializeRepeaterItem($value) : null;
		}

		$maxItems = $field !== null ? (int) $field->get('repeaterMaxItems') : 0;
		$items = [];
		if ($value instanceof PageArray || is_iterable($value)) {
			foreach ($value as $item) {
				if ($item instanceof Page && self::isAccessibleRepeaterItem($item)) {
					$serialized = $this->serializeRepeaterItem($item);
					if ($serialized !== null) {
						$items[] = $serialized;
						if ($maxItems > 0 && count($items) >= $maxItems) {
							break;
						}
					}
				}
			}
		}

		return $items;
	}

	private static function isAccessibleRepeaterItem(Page $item): bool
	{
		return TemplateDataProvider::isAccessiblePage($item) && !$item->isHidden();
	}

	public function phpType(Field $field): string
	{
		return 'PageArray';
	}

	public function tsType(Field $field): string
	{
		$repeaterTemplate = $this->resolveRepeaterTemplate($field);
		if ($repeaterTemplate === null) {
			return 'InertiaPageBase[]';
		}

		return self::interfaceNameForField($field) . '[]';
	}

	/**
	 * @return array<string, string>
	 */
	public function tsDefinitions(?Field $field = null): array
	{
		if ($field === null) {
			return [];
		}

		$fieldName = trim((string) $field->name);
		if ($fieldName === '' || isset($this->activeFieldNames[$fieldName])) {
			return [];
		}

		$repeaterTemplate = $this->resolveRepeaterTemplate($field);
		if (!$repeaterTemplate instanceof Template || !$repeaterTemplate->fieldgroup instanceof Fieldgroup) {
			return [];
		}

		$registry = $this->registry();
		if (!$registry instanceof ProviderRegistry) {
			return [];
		}

		$this->activeFieldNames[$fieldName] = true;
		try {
			$interfaceName = self::interfaceNameForField($field);
			$leadingLines = $this->isRepeaterMatrix($field) ? ["\tmatrixType?: number | string;"] : [];
			$built = $registry->buildTemplateTsInterface(
				$repeaterTemplate,
				$interfaceName,
				$leadingLines,
				['repeater_matrix_type']
			);

			$definitions = $built['definitions'];
			$definitions[$interfaceName] = $built['interface'];

			return $definitions;
		} finally {
			unset($this->activeFieldNames[$fieldName]);
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function serializeRepeaterItem(Page $item): ?array
	{
		if ((int) $item->id <= 0) {
			return null;
		}

		$registry = $this->registry();
		$data = $registry instanceof ProviderRegistry
			? $registry->providePage($item)
			: TemplateDataProvider::basePageData($item);

		$matrixType = $item->get('repeater_matrix_type');
		if ($matrixType !== null && $matrixType !== '') {
			$data['matrixType'] = is_numeric($matrixType) ? (int) $matrixType : (string) $matrixType;
		}

		return $data;
	}

	private function resolveRepeaterTemplate(Field $field): ?Template
	{
		$templates = $this->wire()->templates;

		$templateId = (int) $field->get('template_id');
		if ($templateId > 0) {
			$tpl = $templates->get($templateId);
			if ($tpl instanceof Template) {
				return $tpl;
			}
		}

		$fieldName = trim((string) $field->name);
		if ($fieldName !== '') {
			$tpl = $templates->get('repeater_' . $fieldName);
			if ($tpl instanceof Template) {
				return $tpl;
			}
		}

		return null;
	}

	private function isRepeaterMatrix(Field $field): bool
	{
		$typeClass = $field->type !== null ? $field->type->className() : '';

		return str_contains($typeClass, 'RepeaterMatrix');
	}

	private static function interfaceNameForField(Field $field): string
	{
		return ProviderRegistry::studly((string) $field->name) . 'RepeaterItem';
	}
}
