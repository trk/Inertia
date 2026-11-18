<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Provider\Field;

use ProcessWire\Field;
use ProcessWire\Page;
use ProcessWire\SelectableOption;
use ProcessWire\WireArray;
use ProcessWire\WireData;
use Totoglu\Inertia\Provider\FieldDataProvider;
use Totoglu\Inertia\Request;

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
		if ($value instanceof SelectableOption) {
			$extracted = self::extractOptionValue($value);

			return $extracted !== '' ? [$extracted] : [];
		}

		if ($value instanceof WireArray) {
			$out = [];
			foreach ($value as $opt) {
				if ($opt instanceof SelectableOption || $opt instanceof WireData) {
					$extracted = self::extractOptionValue($opt);
					if ($extracted !== '') {
						$out[] = $extracted;
					}
				}
			}

			return $out;
		}

		if (is_array($value)) {
			$out = [];
			foreach ($value as $item) {
				$scalar = trim(Request::scalarString($item));
				if ($scalar !== '') {
					$out[] = $scalar;
				}
			}

			return $out;
		}

		return [];
	}

	private static function extractOptionValue(SelectableOption|WireData $opt): string
	{
		if (
			$opt instanceof SelectableOption
			&& (!$opt->wire()->languages || $opt->wire()->user->language !== null)
		) {
			$prevOf = $opt->of();
			$opt->of(false);
			try {
				$val = trim((string) $opt->getValue());
				$title = trim((string) $opt->getTitle());
			} finally {
				$opt->of($prevOf);
			}

			return $val !== '' ? $val : $title;
		}

		$val = trim(Request::scalarString($opt->get('value')));
		$title = trim(Request::scalarString($opt->get('title')));

		return $val !== '' ? $val : $title;
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
