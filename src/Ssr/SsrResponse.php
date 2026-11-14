<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Ssr;

/**
 * Value object holding pre-rendered `<head>` tags and `<body>` markup returned by the SSR server.
 */
final readonly class SsrResponse
{
	/**
	 * @param list<string> $head
	 */
	public function __construct(
		public array $head,
		public string $body
	) {
	}

	/**
	 * Join all pre-rendered `<head>` tags into a single HTML string.
	 */
	public function headMarkup(): string
	{
		return implode(
			"\n",
			array_map(
				static fn (string $tag): string => (string) preg_replace('/<\/head\b/i', '&lt;/head', $tag),
				$this->head
			)
		);
	}
}
