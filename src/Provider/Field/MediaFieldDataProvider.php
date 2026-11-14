<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Provider\Field;

use ProcessWire\Field;
use ProcessWire\Page;
use ProcessWire\Pagefile;
use ProcessWire\Pageimage;
use ProcessWire\User;
use Totoglu\Inertia\Provider\FieldDataProvider;
use Totoglu\Inertia\Provider\TemplateDataProvider;

/**
 * Core FieldDataProvider for `FieldtypeImage` and `FieldtypeFile`.
 *
 * Normalizes single (`maxFiles === 1`) and multi-file/image fields consistently
 * regardless of `$page->of()` output formatting state.
 */
final class MediaFieldDataProvider extends FieldDataProvider
{
	/**
	 * @return list<string>
	 */
	public function supports(): array
	{
		return [
			'FieldtypeImage',
			'FieldtypeFile',
		];
	}

	public function transform(mixed $value, ?Field $field = null, ?Page $page = null): mixed
	{
		$isImage = $field !== null ? $this->isImageField($field) : $value instanceof Pageimage;
		$isSingle = $this->isSingleMedia($field);

		return $isImage
			? self::serializeImageValue($value, $isSingle)
			: self::serializeFileValue($value, $isSingle);
	}

	public function phpType(Field $field): string
	{
		if ($this->isImageField($field)) {
			return $this->isSingleMedia($field) ? 'Pageimage|null' : 'Pageimages';
		}

		return $this->isSingleMedia($field) ? 'Pagefile|null' : 'Pagefiles';
	}

	public function tsType(Field $field): string
	{
		$base = $this->isImageField($field) ? 'InertiaImage' : 'InertiaFile';

		return $this->isSingleMedia($field) ? "{$base} | null" : "{$base}[]";
	}

	/**
	 * @return array<string, string>
	 */
	public function tsDefinitions(?Field $field = null): array
	{
		$defaults = self::defaultTsDefinitions();
		if ($field === null || $field->type === null) {
			return $defaults;
		}

		if ($this->isImageField($field)) {
			unset($defaults['InertiaFile']);

			return $defaults;
		}

		return ['InertiaFile' => $defaults['InertiaFile']];
	}

	/**
	 * Canonical TypeScript interface definitions for `InertiaImage` and `InertiaFile`.
	 *
	 * @return array<string, string>
	 */
	public static function defaultTsDefinitions(): array
	{
		return [
			'InertiaImageFocus' => <<<'TS'
export interface InertiaImageFocus {
	top: number;
	left: number;
	zoom: number;
}
TS,
			'InertiaImage' => <<<'TS'
export interface InertiaImage {
	name: string;
	url: string;
	httpUrl: string;
	description: string;
	ext: string;
	filesize: number;
	width: number;
	height: number;
	focus: InertiaImageFocus;
}
TS,
			'InertiaFile' => <<<'TS'
export interface InertiaFile {
	name: string;
	url: string;
	httpUrl: string;
	description: string;
	ext: string;
	filesize: number;
}
TS,
		];
	}

	/**
	 * @param callable(Pageimage): array<string, mixed>|null $formatter
	 * @return array<string, mixed>|list<array<string, mixed>>|null
	 */
	public static function serializeImageValue(mixed $value, bool $single = true, ?callable $formatter = null): ?array
	{
		return self::serializeMediaValue(
			$value,
			$single,
			Pageimage::class,
			$formatter ?? static fn(Pageimage $item): array => self::formatPageimage($item)
		);
	}

	/**
	 * @return array<string, mixed>|list<array<string, mixed>>|null
	 */
	public static function serializeFileValue(mixed $value, bool $single = true): ?array
	{
		return self::serializeMediaValue(
			$value,
			$single,
			Pagefile::class,
			static fn(Pagefile $item): array => self::formatPagefile($item)
		);
	}

	/**
	 * @template T of Pagefile
	 * @param class-string<T> $expectedClass
	 * @param callable(T): array<string, mixed> $formatter
	 * @return array<string, mixed>|list<array<string, mixed>>|null
	 */
	private static function serializeMediaValue(mixed $value, bool $single, string $expectedClass, callable $formatter): ?array
	{
		$candidates = $value instanceof $expectedClass ? [$value] : (is_iterable($value) ? $value : []);
		$items = [];

		foreach ($candidates as $item) {
			if ($item instanceof $expectedClass && self::isAccessiblePagefile($item)) {
				$formatted = $formatter($item);
				if ($single) {
					return $formatted;
				}
				$items[] = $formatted;
			}
		}

		return $single ? null : $items;
	}

	public static function isAccessiblePagefile(Pagefile $file): bool
	{
		if ($file->isTemp() || $file->hidden()) {
			return false;
		}

		$ownerPage = $file->page;
		if ($ownerPage instanceof Page && $ownerPage->id > 0) {
			if ($file->field instanceof Field && !TemplateDataProvider::isFieldViewableOnPage($file->field, $ownerPage)) {
				return false;
			}

			if ($ownerPage instanceof User) {
				if ($ownerPage->isTrash() || $ownerPage->isUnpublished()) {
					return false;
				}
				$viewer = $file->wire()?->user;
				$isSelfOrAdmin = ($viewer instanceof User)
					&& (
						((int) $viewer->id === (int) $ownerPage->id)
						|| $viewer->isSuperuser()
						|| ($viewer->hasPermission('user-admin') && !$ownerPage->isSuperuser())
					);
				if ($isSelfOrAdmin) {
					return true;
				}

				return $file->field instanceof Field
					&& (bool) $file->field->useRoles
					&& $file->field->viewable($ownerPage);
			}

			$currentPage = $file->wire()?->page;
			$isCurrentAllowed = $currentPage instanceof Page && $currentPage->id > 0 && $currentPage->id === $ownerPage->id;
			if (!TemplateDataProvider::isAccessiblePage($ownerPage, true, $currentPage instanceof Page ? $currentPage : null)) {
				return false;
			}

			$hasAccessContext = $ownerPage->template instanceof \ProcessWire\Template && (
				(int) $ownerPage->id === 1
				|| (int) $ownerPage->parent_id > 0
				|| (bool) $ownerPage->template->useRoles
				|| ($ownerPage->hasField('process') && (bool) $ownerPage->get('process'))
			);
			if ($hasAccessContext && !$ownerPage->viewable($file) && !($isCurrentAllowed && $ownerPage->editable())) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Serialize a `Pageimage` into a core `InertiaImage` associative array.
	 *
	 * @return array{
	 *     name: string,
	 *     url: string,
	 *     httpUrl: string,
	 *     description: string,
	 *     ext: string,
	 *     filesize: int,
	 *     width: int,
	 *     height: int,
	 *     focus: array{top: float, left: float, zoom: int}
	 * }
	 */
	public static function formatPageimage(Pageimage $image): array
	{
		$rawFocus = $image->focus();
		$focus = [
			'top' => is_array($rawFocus) && isset($rawFocus['top']) ? (float) $rawFocus['top'] : 50.0,
			'left' => is_array($rawFocus) && isset($rawFocus['left']) ? (float) $rawFocus['left'] : 50.0,
			'zoom' => is_array($rawFocus) && isset($rawFocus['zoom']) ? (int) $rawFocus['zoom'] : 0,
		];

		return [
			'name' => (string) $image->name,
			'url' => (string) $image->url,
			'httpUrl' => (string) $image->httpUrl,
			'description' => self::formatPagefileDescription($image),
			'ext' => (string) $image->ext,
			'filesize' => (int) $image->filesize,
			'width' => (int) $image->width,
			'height' => (int) $image->height,
			'focus' => $focus,
		];
	}

	/**
	 * @return array{name: string, url: string, httpUrl: string, description: string, ext: string, filesize: int}
	 */
	public static function formatPagefile(Pagefile $file): array
	{
		return [
			'name' => (string) $file->name,
			'url' => (string) $file->url,
			'httpUrl' => (string) $file->httpUrl,
			'description' => self::formatPagefileDescription($file),
			'ext' => (string) $file->ext,
			'filesize' => (int) $file->filesize,
		];
	}

	/**
	 * Format a `Pagefile` / `Pageimage` description according to the parent field's selected `Textformatter` modules:
	 * when `TextformatterEntities` (or `entityEncode`) is active on the file/image field, decode entities for SPA text/alt attributes
	 * while preserving any non-entity `Textformatter` modules.
	 */
	private static function formatPagefileDescription(Pagefile $file): string
	{
		$description = (string) $file->description;
		$field = $file->field;

		return $field instanceof Field
			? ScalarFieldDataProvider::decodeEntityEncodedText($description, $field)
			: $description;
	}

	private function isImageField(Field $field): bool
	{
		$typeClass = $field->type !== null ? $field->type->className() : '';

		return $typeClass === 'FieldtypeImage' || str_contains($typeClass, 'Image');
	}

	private function isSingleMedia(?Field $field): bool
	{
		return $field === null || (int) $field->get('maxFiles') === 1;
	}
}
