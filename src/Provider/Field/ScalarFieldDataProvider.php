<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Provider\Field;

use ProcessWire\Field;
use ProcessWire\FieldtypeText;
use ProcessWire\FieldtypeTextarea;
use ProcessWire\Page;
use ProcessWire\Textformatter;
use Stringable;
use Totoglu\Inertia\Provider\FieldDataProvider;

/**
 * Core FieldDataProvider for scalar ProcessWire Fieldtypes (Text, Textarea, Title, Integer, Float, Checkbox/Toggle, URL, Email, Datetime).
 * Also serves as the `'*'` default fallback when no specialized FieldDataProvider is registered.
 */
final class ScalarFieldDataProvider extends FieldDataProvider
{
	/**
	 * HTML entity-encoding `Textformatter` modules skipped on plain-text fields to prevent double-encoding in JSON props.
	 *
	 * @var list<string>
	 */
	public const array ENTITY_ENCODER_FORMATTERS = [
		'TextformatterEntities',
	];

	/**
	 * @return list<string>
	 */
	public function supports(): array
	{
		return [
			'FieldtypeText',
			'FieldtypeTextLanguage',
			'FieldtypeTextarea',
			'FieldtypeTextareaLanguage',
			'FieldtypePageTitle',
			'FieldtypePageTitleLanguage',
			'FieldtypeURL',
			'FieldtypeEmail',
			'FieldtypeDatetime',
			'FieldtypeInteger',
			'FieldtypeFloat',
			'FieldtypeDecimal',
			'FieldtypeCheckbox',
			'FieldtypeToggle',
			'*',
		];
	}

	public function transform(mixed $value, ?Field $field = null, ?Page $page = null): mixed
	{
		$typeClass = $field !== null && $field->type !== null ? $field->type->className() : '';

		if ($typeClass === 'FieldtypeCheckbox' || $typeClass === 'FieldtypeToggle') {
			return (bool) $value;
		}

		if ($typeClass === 'FieldtypeInteger') {
			return $value !== null && $value !== '' ? (int) $value : 0;
		}

		if ($typeClass === 'FieldtypeFloat' || $typeClass === 'FieldtypeDecimal') {
			return $value !== null && $value !== '' ? (float) $value : 0.0;
		}

		if (is_scalar($value) || $value instanceof Stringable) {
			$stringValue = (string) $value;
			if ($field !== null && $page !== null) {
				return $this->formatFieldWithTextformatters($stringValue, $field, $page);
			}

			return $stringValue;
		}

		return $value ?? '';
	}

	/**
	 * Return the list of selected `Textformatter` module names on `$field` when its `Fieldtype` supports textformatters.
	 *
	 * @return list<string>
	 */
	public static function resolveSelectedTextformatters(?Field $field): array
	{
		if ($field === null) {
			return [];
		}

		$typeObj = $field->type;
		if ($typeObj !== null && !$typeObj instanceof FieldtypeText) {
			$className = $typeObj->className();
			if ($className !== 'FieldtypeFile' && $className !== 'FieldtypeImage' && !str_contains($className, 'Image')) {
				return [];
			}
		}

		$raw = $field->get('textformatters');
		$selected = [];

		if (is_array($raw)) {
			foreach ($raw as $item) {
				if (is_scalar($item)) {
					$name = trim((string) $item);
					if ($name !== '') {
						$selected[] = $name;
					}
				}
			}
		}

		if ($selected === [] && (bool) $field->get('entityEncode')) {
			$selected[] = 'TextformatterEntities';
		}

		return $selected;
	}

	/**
	 * Format a string field value using its configured `Textformatter` modules:
	 * plain-text fields skip `ENTITY_ENCODER_FORMATTERS` so JSON props contain unescaped UTF-8,
	 * whereas HTML/markup fields (`isMarkupField()`) run all configured formatters.
	 */
	public function formatFieldWithTextformatters(string $formattedValue, Field $field, Page $page): string
	{
		$selected = self::resolveSelectedTextformatters($field);
		if ($selected === []) {
			return $formattedValue;
		}

		$isMarkup = self::isMarkupField($field);
		if ($isMarkup) {
			return !$page->of()
				? $this->applyTextformatters($formattedValue, $selected, $field, $page)
				: $formattedValue;
		}

		$hasEntityEncoder = false;
		$nonEntityFormatters = [];

		foreach ($selected as $formatterName) {
			if (in_array($formatterName, self::ENTITY_ENCODER_FORMATTERS, true)) {
				$hasEntityEncoder = true;
			} else {
				$nonEntityFormatters[] = $formatterName;
			}
		}

		if (!$hasEntityEncoder) {
			return (!$page->of() && $nonEntityFormatters !== [])
				? $this->applyTextformatters($formattedValue, $nonEntityFormatters, $field, $page)
				: $formattedValue;
		}

		$value = self::decodeEntityEncodedText($formattedValue, $field, $page);

		return $nonEntityFormatters !== []
			? $this->applyTextformatters($value, $nonEntityFormatters, $field, $page)
			: $value;
	}

	/**
	 * @param list<string> $formatterNames
	 */
	private function applyTextformatters(string $value, array $formatterNames, Field $field, Page $page): string
	{
		$modules = $this->wire()->modules;
		foreach ($formatterNames as $formatterName) {
			$formatter = $modules->get($formatterName);
			if ($formatter instanceof Textformatter) {
				$formatter->formatValue($page, $field, $value);
			}
		}

		return $value;
	}

	/**
	 * Decode HTML entities produced by `TextformatterEntities` (or `entityEncode`) on plain-text fields.
	 */
	public static function decodeEntityEncodedText(string $formattedValue, ?Field $field, ?Page $page = null): string
	{
		if ($formattedValue === '' || $field === null) {
			return $formattedValue;
		}

		$selected = self::resolveSelectedTextformatters($field);
		if ($selected === []) {
			return $formattedValue;
		}

		foreach ($selected as $formatterName) {
			if (in_array($formatterName, self::ENTITY_ENCODER_FORMATTERS, true)) {
				$fieldName = trim((string) $field->name);
				$unformatted = $page !== null && $fieldName !== '' && $page->id > 0 && $page->of()
					? $page->getUnformatted($fieldName)
					: null;

				if ((is_scalar($unformatted) || $unformatted instanceof Stringable) && (string) $unformatted !== '' && (string) $unformatted !== $formattedValue) {
					return (string) $unformatted;
				}

				return html_entity_decode($formattedValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			}
		}

		return $formattedValue;
	}

	/**
	 * Determine whether `$field` stores HTML markup (`FieldtypeTextarea` with `contentType >= 1` or TinyMCE/CKEditor).
	 */
	public static function isMarkupField(?Field $field): bool
	{
		if ($field === null) {
			return false;
		}

		$typeClass = $field->type !== null ? $field->type->className() : '';

		if ($typeClass === 'FieldtypeTextarea' || $typeClass === 'FieldtypeTextareaLanguage' || $field->type instanceof FieldtypeTextarea) {
			$inputfieldClass = trim((string) $field->get('inputfieldClass'));
			if (in_array($inputfieldClass, ['InputfieldTinyMCE', 'InputfieldCKEditor'], true)) {
				return true;
			}

			return (int) $field->get('contentType') >= FieldtypeTextarea::contentTypeHTML;
		}

		return false;
	}

	public function phpType(Field $field): string
	{
		$typeClass = $field->type !== null ? $field->type->className() : '';

		return match ($typeClass) {
			'FieldtypeCheckbox', 'FieldtypeToggle' => 'bool',
			'FieldtypeInteger' => 'int',
			'FieldtypeFloat', 'FieldtypeDecimal' => 'float',
			default => 'string',
		};
	}

	public function tsType(Field $field): string
	{
		$typeClass = $field->type !== null ? $field->type->className() : '';

		return match ($typeClass) {
			'FieldtypeCheckbox', 'FieldtypeToggle' => 'boolean',
			'FieldtypeInteger', 'FieldtypeFloat', 'FieldtypeDecimal' => 'number',
			default => 'string',
		};
	}
}
