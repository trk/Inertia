<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Debug;

use Closure;
use ProcessWire\Wire;
use Throwable;
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
		$this->provider = Closure::fromCallable($provider);
	}

	public function getTab(): string
	{
		$sanitizer = $this->wire()->sanitizer;
		$badge = '';
		try {
			$data = (array) ($this->provider)();
			$component = (string) ($data['component'] ?? '');
			if ($component !== '') {
				$badge = sprintf(' <small style="opacity:0.85;">(%s)</small>', $sanitizer->entities($component));
			}
		} catch (Throwable $e) {
			$badge = sprintf(' <small title="%s">(!)</small>', $sanitizer->entities($e->getMessage()));
		}

		return '<span title="' . $sanitizer->entities($this->_('Inertia.js v3 Protocol')) . '">Inertia' . $badge . '</span>';
	}

	public function getPanel(): string
	{
		$sanitizer = $this->wire()->sanitizer;
		$data = [];
		try {
			$data = (array) ($this->provider)();
		} catch (Throwable $e) {
			$data = ['error' => $e->getMessage()];
		}

		$rows = '';
		foreach ($data as $k => $v) {
			if (is_array($v) || is_object($v)) {
				$v = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
				$cell = '<pre style="margin:0; max-height:240px; overflow:auto; font-size:11px;">'
					. $sanitizer->entities((string) $v)
					. '</pre>';
			} elseif (is_bool($v)) {
				$cell = $v ? 'true' : 'false';
			} else {
				$cell = $sanitizer->entities((string) $v);
			}

			$rows .= sprintf(
				'<tr><th style="text-align:left; padding:4px 8px; white-space:nowrap; vertical-align:top;">%s</th><td style="padding:4px 8px;">%s</td></tr>',
				$sanitizer->entities((string) $k),
				$cell
			);
		}

		return '<div class="tracy-inner"><h1>Inertia.js v3</h1><table>' . $rows . '</table></div>';
	}
}
