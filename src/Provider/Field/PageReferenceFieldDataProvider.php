<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Provider\Field;

use ProcessWire\Field;
use ProcessWire\NullPage;
use ProcessWire\Page;
use Totoglu\Inertia\Provider\FieldDataProvider;
use Totoglu\Inertia\Provider\TemplateDataProvider;

/**
 * Core FieldDataProvider for `FieldtypePage`.
 *
 * Emits shallow `InertiaPageBase` representations for referenced pages by default
 * to prevent circular references and N+1 recursion.
 */
final class PageReferenceFieldDataProvider extends FieldDataProvider
{
	/**
	 * @return list<string>
	 */
	public function supports(): array
	{
		return [
			'FieldtypePage',
		];
	}

	public function transform(mixed $value, ?Field $field = null, ?Page $page = null): mixed
	{
		$isSingle = $field !== null ? $this->isSingleReference($field) : $value instanceof Page;
		if ($isSingle && $value instanceof NullPage) {
			return null;
		}

		$candidates = $value instanceof Page ? [$value] : (is_iterable($value) ? $value : []);
		$items = [];

		foreach ($candidates as $item) {
			if ($item instanceof Page && TemplateDataProvider::isAccessiblePage($item)) {
				$data = TemplateDataProvider::basePageData($item);
				if ($isSingle) {
					return $data;
				}
				$items[] = $data;
			}
		}

		return $isSingle ? null : $items;
	}

	public function phpType(Field $field): string
	{
		return $this->isSingleReference($field) ? 'Page|NullPage' : 'PageArray';
	}

	public function tsType(Field $field): string
	{
		return $this->isSingleReference($field) ? 'InertiaPageBase | null' : 'InertiaPageBase[]';
	}

	private function isSingleReference(Field $field): bool
	{
		return (int) $field->get('derefAsPage') > 0;
	}
}
