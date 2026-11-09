<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Provider\Field;

use ProcessWire\Field;
use ProcessWire\Page;
use ProcessWire\WireArray;
use Totoglu\Inertia\Provider\FieldDataProvider;

/**
 * Core FieldDataProvider for `FieldtypeOptions`.
 */
final class OptionsFieldDataProvider extends FieldDataProvider
{
	/**
	 * @return list<string>
	 */
	public function supports(): array
	{
		return ['FieldtypeOptions'];
	}

	/**
	 * @return list<string>
	 */
	public function transform(mixed $value, ?Field $field = null, ?Page $page = null): array
	{
		if ($value instanceof WireArray) {
			$out = [];
			foreach ($value as $opt) {
				if (is_object($opt)) {
					$val = trim((string) $opt->value);
					$title = trim((string) $opt->title);
					$out[] = $val !== '' ? $val : $title;
				}
			}

			return array_values(array_filter($out, static fn(string $s): bool => $s !== ''));
		}

		if (is_array($value)) {
			return array_values(array_map('strval', $value));
		}

		return [];
	}

	public function phpType(Field $field): string
	{
		return 'WireArray';
	}

	public function tsType(Field $field): string
	{
		return 'string[]';
	}
}
