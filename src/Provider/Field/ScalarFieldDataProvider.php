<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Provider\Field;

use ProcessWire\Field;
use ProcessWire\FieldtypeText;
use ProcessWire\FieldtypeTextarea;
use ProcessWire\Inputfield;
use ProcessWire\LanguagesValueInterface;
use ProcessWire\Page;
use ProcessWire\Textformatter;
use ProcessWire\Wire;
use Stringable;
use Totoglu\Inertia\Contracts\InertiaSerializable;
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
		if ($field !== null && self::isKeyValueField($field)) {
			$rawSource = $value;
			if ($page !== null && $page->id > 0 && is_string($value) && str_contains($value, '&quot;')) {
				$rawSource = $page->getUnformatted((string) $field->name);
			}

			return self::decodeKeyValueObject($rawSource, $field);
		}

		if ($field !== null && self::isLinkField($field)) {
			$rawSource = $value;
			if ($page !== null && $page->id > 0 && !is_array($value)) {
				$rawSource = $page->getUnformatted((string) $field->name);
			}

			return self::resolveLinkObject($rawSource, $field, $page);
		}

		if ($field !== null && self::isIconField($field)) {
			$rawSource = $value;
			if ($page !== null && $page->id > 0 && is_string($value) && str_contains($value, '&quot;')) {
				$rawSource = $page->getUnformatted((string) $field->name);
			}

			return self::resolveIconObject($rawSource, $field);
		}

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

		if ($value instanceof InertiaSerializable) {
			return $value->toInertiaProp();
		}

		if ($value instanceof \JsonSerializable) {
			return $value->jsonSerialize();
		}

		if ($value instanceof Wire && !$value instanceof LanguagesValueInterface) {
			return '';
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
			if (!$modules->isInstalled($formatterName)) {
				continue;
			}
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
				if ($page !== null && $fieldName !== '' && $page->id > 0) {
					if (!$page->of() && $page->template !== null && $page->hasField($fieldName)) {
						$rawCurrent = $page->getUnformatted($fieldName);
						if ((is_scalar($rawCurrent) || $rawCurrent instanceof Stringable) && (string) $rawCurrent === $formattedValue) {
							return $formattedValue;
						}
					}

					$unformatted = $page->of() ? $page->getUnformatted($fieldName) : null;
					if ((is_scalar($unformatted) || $unformatted instanceof Stringable) && (string) $unformatted !== '' && (string) $unformatted !== $formattedValue) {
						return (string) $unformatted;
					}
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
			if ($inputfieldClass !== '' && $inputfieldClass !== 'InputfieldTextarea') {
				if (in_array($inputfieldClass, ['InputfieldTinyMCE', 'InputfieldCKEditor'], true)) {
					return true;
				}
				if (self::resolveOptInInputfieldClass($field, 'INERTIA_IS_MARKUP_FIELD', false) !== null) {
					return true;
				}
			}

			return (int) $field->get('contentType') >= FieldtypeTextarea::contentTypeHTML;
		}

		return false;
	}

	/**
	 * Resolve a configured `Inputfield` FQCN on `$field` when that class defines `$flagConstant === true`.
	 *
	 * @return class-string|null
	 */
	private static function resolveOptInInputfieldClass(?Field $field, string $flagConstant, bool $requireFieldtypeText = true): ?string
	{
		if ($field === null || ($requireFieldtypeText && !$field->type instanceof FieldtypeText)) {
			return null;
		}

		$inputfieldClass = ltrim(trim((string) ($field->get('inputfieldClass') ?? '')), '\\');
		if ($inputfieldClass === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $inputfieldClass) !== 1) {
			return null;
		}

		$fqcn = str_contains($inputfieldClass, '\\') ? '\\' . $inputfieldClass : '\\ProcessWire\\' . $inputfieldClass;
		if (!class_exists($fqcn, false)) {
			$modules = $field->wire()->modules;
			if (str_contains($inputfieldClass, '\\') || $modules->isInstalled($inputfieldClass)) {
				class_exists($fqcn);
			}
		}

		if (
			class_exists($fqcn, false)
			&& defined($fqcn . '::' . $flagConstant)
			&& constant($fqcn . '::' . $flagConstant) === true
		) {
			$reflection = new \ReflectionClass($fqcn);
			if (
				!$reflection->isAbstract()
				&& !$reflection->isInterface()
				&& !$reflection->isTrait()
				&& $reflection->isSubclassOf(Inputfield::class)
			) {
				/** @var class-string<Inputfield> $fqcn */
				return $fqcn;
			}
		}

		return null;
	}

	/**
	 * Resolve the configured Inputfield FQCN on a `FieldtypeText` / `FieldtypeTextarea` field if it opts into
	 * the `INERTIA_IS_KEYVALUE_FIELD` contract.
	 *
	 * @return class-string|null
	 */
	private static function resolveKeyValueInputfieldClass(?Field $field): ?string
	{
		return self::resolveOptInInputfieldClass($field, 'INERTIA_IS_KEYVALUE_FIELD');
	}

	/**
	 * Determine whether `$field` is a `FieldtypeText` / `FieldtypeTextarea` field opting into `INERTIA_IS_KEYVALUE_FIELD`.
	 */
	public static function isKeyValueField(?Field $field): bool
	{
		return self::resolveKeyValueInputfieldClass($field) !== null;
	}

	/**
	 * Resolve the configured Inputfield FQCN on a `FieldtypeText` / `FieldtypeTextarea` field if it opts into
	 * the `INERTIA_IS_LINK_FIELD` contract.
	 *
	 * @return class-string|null
	 */
	private static function resolveLinkInputfieldClass(?Field $field): ?string
	{
		return self::resolveOptInInputfieldClass($field, 'INERTIA_IS_LINK_FIELD');
	}

	/**
	 * Determine whether `$field` is a `FieldtypeText` / `FieldtypeTextarea` field opting into `INERTIA_IS_LINK_FIELD`.
	 */
	public static function isLinkField(?Field $field): bool
	{
		return self::resolveLinkInputfieldClass($field) !== null;
	}

	/**
	 * Resolve the configured Inputfield FQCN on a `FieldtypeText` / `FieldtypeTextarea` field if it opts into
	 * the `INERTIA_IS_ICON_FIELD` contract.
	 *
	 * @return class-string|null
	 */
	private static function resolveIconInputfieldClass(?Field $field): ?string
	{
		return self::resolveOptInInputfieldClass($field, 'INERTIA_IS_ICON_FIELD');
	}

	/**
	 * Determine whether `$field` is a `FieldtypeText` / `FieldtypeTextarea` field opting into `INERTIA_IS_ICON_FIELD`.
	 */
	public static function isIconField(?Field $field): bool
	{
		return self::resolveIconInputfieldClass($field) !== null;
	}

	private static function unwrapLanguagesValue(mixed $raw): mixed
	{
		if (!$raw instanceof LanguagesValueInterface) {
			return $raw;
		}
		if ($raw instanceof Stringable) {
			return (string) $raw;
		}
		$wire = $raw instanceof Wire ? $raw->wire() : null;
		$userLang = $wire !== null && $wire->languages !== null ? $wire->user->language : null;
		if ($userLang instanceof \ProcessWire\Language) {
			$val = $raw->getLanguageValue($userLang);

			return is_scalar($val) ? (string) $val : $val;
		}

		return '';
	}

	/**
	 * Resolve a Page icon field into a structured icon array (or `null` when empty) for Inertia props.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function resolveIconObject(mixed $raw, Field $field): ?array
	{
		$raw = self::unwrapLanguagesValue($raw);
		if (is_object($raw)) {
			return self::serializeIconObjectValue($raw);
		}

		$fqcn = self::resolveIconInputfieldClass($field);
		if ($fqcn !== null && method_exists($fqcn, 'resolveValue')) {
			$resolved = $fqcn::resolveValue($raw);
			if (is_object($resolved)) {
				return self::serializeIconObjectValue($resolved);
			}
			if (is_array($resolved)) {
				return ($resolved['name'] ?? '') !== '' ? $resolved : null;
			}
		}

		return null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function serializeIconObjectValue(object $value): ?array
	{
		if (method_exists($value, 'isEmpty') && $value->isEmpty()) {
			return null;
		}
		if ($value instanceof InertiaSerializable) {
			$serialized = $value->toInertiaProp();

			return is_array($serialized) && $serialized !== [] ? $serialized : null;
		}
		if ($value instanceof \JsonSerializable) {
			$serialized = $value->jsonSerialize();

			return is_array($serialized) && $serialized !== [] ? $serialized : null;
		}

		return null;
	}

	/**
	 * Resolve a Page link field into a structured link array (or `null` when empty) for Inertia props.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function resolveLinkObject(mixed $raw, Field $field, ?Page $page = null): ?array
	{
		$raw = self::unwrapLanguagesValue($raw);
		$fqcn = self::resolveLinkInputfieldClass($field);
		if ($fqcn !== null && method_exists($fqcn, 'isEmptyValue') && method_exists($fqcn, 'resolveValue')) {
			if ($fqcn::isEmptyValue($raw)) {
				return null;
			}
			/** @var array<string, mixed> $resolved */
			$resolved = $fqcn::resolveValue($raw, $page, $field);

			return ($resolved['href'] ?? '') !== '' || ($resolved['file'] ?? '') !== '' ? $resolved : null;
		}

		return null;
	}

	/**
	 * Decode a Page key-value field into a `\stdClass` object so Inertia JSON always emits `{}` or `{"k":"v"}`.
	 */
	private static function decodeKeyValueObject(mixed $raw, Field $field): \stdClass
	{
		$raw = self::unwrapLanguagesValue($raw);
		$allowEmpty = $field->get('allowEmptyValues') !== null ? (bool) $field->get('allowEmptyValues') : true;
		$fqcn = self::resolveKeyValueInputfieldClass($field);
		if ($fqcn !== null && method_exists($fqcn, 'toJsonObject')) {
			/** @var \stdClass $converted */
			$converted = $fqcn::toJsonObject($raw, $allowEmpty);

			return $converted;
		}

		$decoded = $raw instanceof \stdClass
			? get_object_vars($raw)
			: (is_string($raw) && str_starts_with(trim($raw), '{')
				? json_decode(trim($raw), true, 16)
				: (is_array($raw) ? $raw : []));
		$obj = new \stdClass();
		if (is_array($decoded)) {
			foreach ($decoded as $k => $v) {
				$cleanK = trim(preg_replace('/[\x00-\x1F\x7F]+/', '', (string) $k) ?? '');
				if ($cleanK === '' || in_array(strtolower($cleanK), ['__proto__', 'constructor', 'prototype'], true)) {
					continue;
				}
				if (is_scalar($v) || $v === null) {
					$strV = is_string($v) ? (preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $v) ?? '') : ($v ?? '');
					if (!$allowEmpty && $strV === '') {
						continue;
					}
					$obj->{$cleanK} = $strV;
				}
			}
		}

		return $obj;
	}

	public function phpType(Field $field): string
	{
		if (self::isKeyValueField($field)) {
			return '\\stdClass';
		}
		if (self::isLinkField($field) || self::isIconField($field)) {
			return '?array';
		}

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
		if (self::isKeyValueField($field)) {
			return 'Record<string, string | number | boolean>';
		}
		if (self::isLinkField($field) || self::isIconField($field)) {
			return 'Record<string, unknown> | null';
		}

		$typeClass = $field->type !== null ? $field->type->className() : '';

		return match ($typeClass) {
			'FieldtypeCheckbox', 'FieldtypeToggle' => 'boolean',
			'FieldtypeInteger', 'FieldtypeFloat', 'FieldtypeDecimal' => 'number',
			default => 'string',
		};
	}
}
