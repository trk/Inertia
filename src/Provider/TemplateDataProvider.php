<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Provider;

use ProcessWire\Field;
use ProcessWire\Fieldgroup;
use ProcessWire\NullPage;
use ProcessWire\Page;
use ProcessWire\Template;
use ProcessWire\User;
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
			if (!self::isFieldViewableOnPage($field, $page)) {
				continue;
			}
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

		if ($titleField instanceof Field && !self::isFieldViewableOnPage($titleField, $page)) {
			return (string) $page->name;
		}

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
	 * Determine whether `$page` is an existing, non-trashed, published, and viewable `Page`
	 * (or the current request `$page` when `$allowCurrentPage` is true).
	 */
	public static function isAccessiblePage(Page $page, bool $allowCurrentPage = false, ?Page $currentPage = null, int $depth = 0): bool
	{
		if ($page instanceof NullPage || $page->id <= 0 || $page->isTrash()) {
			return false;
		}

		$isCurrentAllowed = $allowCurrentPage
			&& $currentPage instanceof Page
			&& $currentPage->id > 0
			&& $currentPage->id === $page->id;

		if ($page->isUnpublished() && !$isCurrentAllowed) {
			return false;
		}

		if ($page->template instanceof Template) {
			$hasAccessContext = (int) $page->id === 1
				|| (int) $page->parent_id > 0
				|| (bool) $page->template->useRoles
				|| $page instanceof User
				|| ($page->hasField('process') && (bool) $page->get('process'));

			if ($hasAccessContext) {
				if ($isCurrentAllowed) {
					if (!$page->viewable(false) && !$page->editable()) {
						return false;
					}
				} elseif (!$page->viewable(false)) {
					return false;
				}
			}
		}

		if ($page instanceof \ProcessWire\RepeaterPage) {
			if ($depth > 20 || $page->isHidden()) {
				return false;
			}
			$forField = $page->getForField();
			$directOwner = $page->getForPage();
			if ($directOwner instanceof Page && $directOwner->id > 0 && $directOwner->id !== $page->id) {
				if (!self::isAccessiblePage($directOwner, $allowCurrentPage, $currentPage, $depth + 1)) {
					return false;
				}
				if ($forField instanceof Field && !self::isFieldViewableOnPage($forField, $directOwner)) {
					return false;
				}
			} else {
				$rootOwner = $page->getForPageRoot();
				if ($rootOwner instanceof Page && $rootOwner->id > 0 && $rootOwner->id !== $page->id) {
					if (!self::isAccessiblePage($rootOwner, $allowCurrentPage, $currentPage, $depth + 1)) {
						return false;
					}
				}
				if ($forField instanceof Field && $directOwner instanceof Page && $directOwner->id > 0) {
					if (!self::isFieldViewableOnPage($forField, $directOwner)) {
						return false;
					}
				}
			}
		}

		return true;
	}

	/**
	 * Enforce ProcessWire field-level RBAC (`$field->useRoles` / `$field->viewable($page)`).
	 */
	public static function isFieldViewableOnPage(Field $field, Page $page): bool
	{
		if (!(bool) $field->useRoles) {
			return true;
		}

		return $field->viewable($page) || ($page->editable() && $field->editable($page));
	}

	public static function isUiFieldset(Field $field): bool
	{
		$typeClass = $field->type !== null ? $field->type->className() : '';

		return in_array($typeClass, self::UI_FIELDSET_TYPES, true);
	}
}
