<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Provider\Field;

use ProcessWire\Field;
use ProcessWire\Page;
use ProcessWire\Pagefile;
use ProcessWire\Pageimage;
use ProcessWire\ProcessWire;
use Throwable;
use Totoglu\Inertia\Provider\FieldDataProvider;
use function ProcessWire\wire;

/**
 * Core FieldDataProvider for `FieldtypeImage` and `FieldtypeFile`.
 *
 * Normalizes single (`maxFiles === 1`) and multi-file/image fields consistently
 * regardless of `$page->of()` output formatting state, and automatically enriches
 * `Pageimage` payloads and TypeScript definitions with responsive `srcset`, `sizes`,
 * `sources`, `lqip`, `dominantColor`, `palette`, `colorTheme`, and `imageHash` metadata
 * when the `InterventionImage` module is installed.
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
	 * Determine whether the `InterventionImage` module is installed in ProcessWire.
	 */
	public static function isInterventionImageInstalled(?ProcessWire $wire = null): bool
	{
		try {
			$wireInstance = $wire ?? wire();
			if (!$wireInstance instanceof ProcessWire) {
				return false;
			}

			return $wireInstance->modules->isInstalled('InterventionImage');
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * Canonical TypeScript interface definitions for `InertiaImage` (including `InterventionImage`
	 * responsive/color/hash types when installed) and `InertiaFile`.
	 *
	 * @return array<string, string>
	 */
	public static function defaultTsDefinitions(?bool $withIntervention = null): array
	{
		$useIntervention = $withIntervention ?? self::isInterventionImageInstalled();

		$definitions = [
			'InertiaImageFocus' => <<<'TS'
export interface InertiaImageFocus {
	top: number;
	left: number;
	zoom: number;
}
TS,
		];

		if ($useIntervention) {
			$definitions['InertiaImageSource'] = <<<'TS'
export interface InertiaImageSource {
	type: string;
	srcset: string;
}
TS;
			$definitions['InertiaImageColorTheme'] = <<<'TS'
export interface InertiaImageColorTheme {
	vibrant: string | null;
	muted: string | null;
	darkVibrant: string | null;
	darkMuted: string | null;
	lightVibrant: string | null;
	lightMuted: string | null;
}
TS;
			$definitions['InertiaImage'] = <<<'TS'
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
	srcset: string;
	sizes: string;
	sources: InertiaImageSource[];
	lqip: string;
	dominantColor: string;
	palette: string[];
	colorTheme: InertiaImageColorTheme | null;
	imageHash: string;
}
TS;
		} else {
			$definitions['InertiaImage'] = <<<'TS'
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
TS;
		}

		$definitions['InertiaFile'] = <<<'TS'
export interface InertiaFile {
	name: string;
	url: string;
	httpUrl: string;
	description: string;
	ext: string;
	filesize: number;
}
TS;

		return $definitions;
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
		return !$file->isTemp() && !$file->hidden();
	}

	/**
	 * Serialize a `Pageimage` into an `InertiaImage` associative array, enriching with
	 * `InterventionImage` responsive (`srcset`, `sizes`, `sources`), `lqip`, `dominantColor`,
	 * `palette`, `colorTheme`, and `imageHash` properties when `InterventionImage` is installed.
	 *
	 * @return array<string, mixed>
	 */
	public static function formatPageimage(Pageimage $image): array
	{
		$rawFocus = $image->focus();
		$focus = [
			'top' => is_array($rawFocus) && isset($rawFocus['top']) ? (float) $rawFocus['top'] : 50.0,
			'left' => is_array($rawFocus) && isset($rawFocus['left']) ? (float) $rawFocus['left'] : 50.0,
			'zoom' => is_array($rawFocus) && isset($rawFocus['zoom']) ? (int) $rawFocus['zoom'] : 0,
		];

		$data = [
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

		$wire = $image->wire();
		if (self::isInterventionImageInstalled($wire)) {
			$data = array_merge($data, self::extractInterventionImageData($image));
		}

		return $data;
	}

	/**
	 * Extract responsive attributes, LQIP, color metadata, and perceptual hash from `InterventionImage` hooks on `Pageimage`.
	 *
	 * @return array{
	 *     srcset: string,
	 *     sizes: string,
	 *     sources: list<array{type: string, srcset: string}>,
	 *     lqip: string,
	 *     dominantColor: string,
	 *     palette: list<string>,
	 *     colorTheme: array{vibrant: string|null, muted: string|null, darkVibrant: string|null, darkMuted: string|null, lightVibrant: string|null, lightMuted: string|null}|null,
	 *     imageHash: string
	 * }
	 */
	private static function extractInterventionImageData(Pageimage $image): array
	{
		$result = [
			'srcset' => '',
			'sizes' => '',
			'sources' => [],
			'lqip' => '',
			'dominantColor' => '',
			'palette' => [],
			'colorTheme' => null,
			'imageHash' => '',
		];

		try {
			$wire = $image->wire();
			$wire->modules->get('InterventionImage');
		} catch (Throwable) {
			return $result;
		}

		$filename = (string) $image->filename;
		if ($filename === '' || !is_file($filename) || filesize($filename) === 0) {
			return $result;
		}

		try {
			$rawAttrs = $image->attrs();
			if (is_array($rawAttrs)) {
				if (isset($rawAttrs['srcset']) && is_string($rawAttrs['srcset'])) {
					$result['srcset'] = $rawAttrs['srcset'];
				}
				if (isset($rawAttrs['sizes']) && is_string($rawAttrs['sizes'])) {
					$result['sizes'] = $rawAttrs['sizes'];
				}
				if (isset($rawAttrs['sources']) && is_array($rawAttrs['sources'])) {
					$sources = [];
					foreach ($rawAttrs['sources'] as $sourceItem) {
						if (is_array($sourceItem) && isset($sourceItem['type'], $sourceItem['srcset'])) {
							$type = trim((string) $sourceItem['type']);
							$srcset = trim((string) $sourceItem['srcset']);
							if ($type !== '' && $srcset !== '') {
								$sources[] = [
									'type' => $type,
									'srcset' => $srcset,
								];
							}
						}
					}
					$result['sources'] = $sources;
				}
			}
		} catch (Throwable) {
		}

		try {
			$rawLqip = $image->lqip();
			if (is_string($rawLqip)) {
				$result['lqip'] = $rawLqip;
			}
		} catch (Throwable) {
		}

		try {
			// Call palette() first so InterventionImage caches dominant, palette, and theme in .colors.json in a single pass
			$rawPalette = $image->palette(6, true);
			if (is_array($rawPalette)) {
				$result['palette'] = array_values(array_filter(
					array_map(static fn(mixed $hex): string => is_string($hex) ? trim($hex) : '', $rawPalette),
					static fn(string $hex): bool => $hex !== ''
				));
			}

			$rawDominant = $image->dominantColor(true);
			if (is_string($rawDominant)) {
				$result['dominantColor'] = $rawDominant;
			}

			$rawTheme = $image->colorTheme(true);
			if (is_array($rawTheme) && $rawTheme !== []) {
				$result['colorTheme'] = [
					'vibrant' => isset($rawTheme['vibrant']) && is_string($rawTheme['vibrant']) && $rawTheme['vibrant'] !== '' ? $rawTheme['vibrant'] : null,
					'muted' => isset($rawTheme['muted']) && is_string($rawTheme['muted']) && $rawTheme['muted'] !== '' ? $rawTheme['muted'] : null,
					'darkVibrant' => isset($rawTheme['darkVibrant']) && is_string($rawTheme['darkVibrant']) && $rawTheme['darkVibrant'] !== '' ? $rawTheme['darkVibrant'] : null,
					'darkMuted' => isset($rawTheme['darkMuted']) && is_string($rawTheme['darkMuted']) && $rawTheme['darkMuted'] !== '' ? $rawTheme['darkMuted'] : null,
					'lightVibrant' => isset($rawTheme['lightVibrant']) && is_string($rawTheme['lightVibrant']) && $rawTheme['lightVibrant'] !== '' ? $rawTheme['lightVibrant'] : null,
					'lightMuted' => isset($rawTheme['lightMuted']) && is_string($rawTheme['lightMuted']) && $rawTheme['lightMuted'] !== '' ? $rawTheme['lightMuted'] : null,
				];
			}
		} catch (Throwable) {
		}

		try {
			$rawHash = $image->imageHash('dhash');
			if (is_string($rawHash)) {
				$result['imageHash'] = $rawHash;
			}
		} catch (Throwable) {
		}

		return $result;
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
