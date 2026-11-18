<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Debug;

use Closure;
use ProcessWire\Wire;
use Throwable;
use Totoglu\Inertia\Request;
use Tracy\IBarPanel;

/**
 * TracyDebugger bar panel for ProcessWire Inertia v3 protocol inspection.
 */
final class InertiaTracyPanel extends Wire implements IBarPanel
{
	private readonly Closure $provider;

	/**
	 * @param callable():array<string, mixed> $provider
	 */
	public function __construct(callable $provider)
	{
		parent::__construct();
		$this->provider = Closure::fromCallable($provider);
	}

	public function getTab(): string
	{
		$badge = '';
		try {
			$data = (array) ($this->provider)();
			$component = Request::scalarString($data['component'] ?? '');
			if ($component !== '') {
				$badge = sprintf(' <small style="opacity:0.85;">(%s)</small>', self::escapeHtml($component));
			}
		} catch (Throwable $e) {
			$badge = sprintf(' <small title="%s">(!)</small>', self::escapeHtml($e->getMessage()));
		}

		$title = $this->_('Inertia.js v3 Protocol');

		return '<span title="' . self::escapeHtml($title) . '">Inertia' . $badge . '</span>';
	}

	public function getPanel(): string
	{
		$data = [];
		try {
			$data = (array) ($this->provider)();
		} catch (Throwable $e) {
			$data = ['error' => $e->getMessage()];
		}

		$rows = '';
		foreach ($data as $k => $v) {
			if (is_array($v) || is_object($v)) {
				$encoded = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
				$cell = '<pre style="margin:0; max-height:240px; overflow:auto; font-size:11px;">'
					. self::escapeHtml(is_string($encoded) ? $encoded : '')
					. '</pre>';
			} elseif (is_bool($v)) {
				$cell = $v ? 'true' : 'false';
			} else {
				$cell = self::escapeHtml(Request::scalarString($v));
			}

			$rows .= sprintf(
				'<tr><th style="text-align:left; padding:4px 8px; white-space:nowrap; vertical-align:top;">%s</th><td style="padding:4px 8px;">%s</td></tr>',
				self::escapeHtml((string) $k),
				$cell
			);
		}

		return '<div class="tracy-inner"><h1>Inertia.js v3</h1><table>' . $rows . '</table></div>';
	}

	private static function escapeHtml(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
