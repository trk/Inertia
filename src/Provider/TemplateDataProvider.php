<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Provider;

use ProcessWire\Field;
use ProcessWire\Fieldgroup;
use ProcessWire\NullPage;
use ProcessWire\Page;
use ProcessWire\Template;
use ProcessWire\Wire;
use Totoglu\Inertia\Provider\Field\ScalarFieldDataProvider;

/**
 * Default (`'*'`) and extensible template data provider for ProcessWire `Page` instances.
 *
 * Serializes base `Page` properties (`id`, `name`, `title`, `url`, `httpUrl`, `template`, `created`, `modified`)
 * and transforms template fields via `ProviderRegistry`. Subclasses can target specific templates via `templates()`
 * and customize `extraData()`, `excludedFields()`, or `extraTsTypes()`.
 */
class TemplateDataProvider extends Wire
{
	/**
	 * Pure UI fieldset markers that hold no runtime data.
	 *
	 * @var list<string>
	 */
	public const array UI_FIELDSET_TYPES = [
		'FieldtypeFieldsetOpen',
		'FieldtypeFieldsetClose',
		'FieldtypeFieldsetTabOpen',
		'FieldtypeFieldsetGroup',
	];

	/**
	 * @return list<string>
	 */
	public function templates(): array
	{
		return ['*'];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function provide(Page $page, ProviderRegistry $registry): array
	{
		$data = self::basePageData($page, $registry);

		foreach ($this->eligibleFields($page->template, ['title']) as $name => $field) {
			$data[$name] = $registry->transformField($field, $page);
		}

		$extra = $this->extraData($page);
		if ($extra !== []) {
			$data = array_merge($data, $extra);
		}

		return $data;
	}

	/**
	 * Return eligible non-excluded, non-UI-fieldset fields attached to `$template`.
	 *
	 * @param list<string> $skipFieldNames
	 * @return array<string, Field>
	 */
	public function eligibleFields(?Template $template, array $skipFieldNames = []): array
	{
		$fieldgroup = $template?->fieldgroup;
		if (!$fieldgroup instanceof Fieldgroup) {
			return [];
		}

		$excluded = array_fill_keys(array_merge($this->excludedFields(), $skipFieldNames), true);
		$fields = [];

		foreach ($fieldgroup as $field) {
			if (!$field instanceof Field || self::isUiFieldset($field)) {
				continue;
			}
			$name = trim((string) $field->name);
			if ($name === '' || isset($excluded[$name])) {
				continue;
			}
			$fields[$name] = $field;
		}

		return $fields;
	}

	/**
	 * Override in custom `TemplateDataProvider` subclasses to append or override computed properties for `$page`.
	 *
	 * @return array<string, mixed>
	 */
	protected function extraData(Page $page): array
	{
		return [];
	}

	/**
	 * @return list<string>
	 */
	public function excludedFields(): array
	{
		return [];
	}

	/**
	 * @return array<string, string>
	 */
	public function extraTsTypes(Template $template): array
	{
		return [];
	}

	/**
	 * @return array<string, string>
	 */
	public function tsDefinitions(Template $template): array
	{
		return [];
	}

	/**
	 * Return the canonical base properties for any ProcessWire `Page`.
	 *
	 * @return array{id: int, name: string, title: string, url: string, httpUrl: string, template: string, created: int, modified: int}
	 */
	public static function basePageData(Page $page, ?ProviderRegistry $registry = null): array
	{
		$hasTemplate = $page->template instanceof Template;

		return [
			'id' => (int) $page->id,
			'name' => (string) $page->name,
			'title' => self::resolveBaseTitle($page, $registry),
			'url' => $hasTemplate ? (string) $page->url : '',
			'httpUrl' => $hasTemplate ? (string) $page->httpUrl : '',
			'template' => $hasTemplate ? (string) $page->template->name : '',
			'created' => (int) $page->created,
			'modified' => (int) $page->modified,
		];
	}

	private static function resolveBaseTitle(Page $page, ?ProviderRegistry $registry = null): string
	{
		$titleField = $page->template instanceof Template && $page->template->fieldgroup instanceof Fieldgroup
			? $page->template->fieldgroup->getField('title', true)
			: null;

		if ($registry !== null && $titleField instanceof Field) {
			$transformed = (string) $registry->transformField($titleField, $page);

			return $transformed !== '' ? $transformed : (string) $page->name;
		}

		$title = (string) $page->get('title');
		if ($title === '') {
			return (string) $page->name;
		}

		if (!$titleField instanceof Field) {
			$titleField = $page->wire()->fields->get('title');
		}

		if ($titleField instanceof Field) {
			return ScalarFieldDataProvider::decodeEntityEncodedText($title, $titleField, $page);
		}

		return $title;
	}

	/**
	 * Determine whether `$page` is an existing, non-trashed, published `Page` (or the current request `$page` when `$allowCurrentPage` is true).
	 */
	public static function isAccessiblePage(Page $page, bool $allowCurrentPage = false, ?Page $currentPage = null): bool
	{
		if ($page instanceof NullPage || $page->id <= 0 || $page->isTrash()) {
			return false;
		}

		if ($page->isUnpublished()) {
			if ($allowCurrentPage && $currentPage instanceof Page && $currentPage->id > 0 && $currentPage->id === $page->id) {
				return true;
			}

			return false;
		}

		return true;
	}

	public static function isUiFieldset(Field $field): bool
	{
		$typeClass = $field->type !== null ? $field->type->className() : '';

		return in_array($typeClass, self::UI_FIELDSET_TYPES, true);
	}
}
