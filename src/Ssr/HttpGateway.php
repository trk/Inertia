<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Ssr;

use ProcessWire\Wire;
use Throwable;
use Totoglu\Inertia\Contracts\SsrGatewayInterface;

/**
 * Dispatches the Inertia Page Object JSON payload to a local Node/Bun SSR server
 * (default `http://127.0.0.1:13714/render`), falling back to `null` (CSR) on failure.
 */
class HttpGateway extends Wire implements SsrGatewayInterface
{
	public function __construct(
		private string $url = 'http://127.0.0.1:13714/render',
		private float $timeoutSeconds = 1.5,
		private bool $enabled = false
	) {
		$this->setUrl($url);
		$this->setTimeout($timeoutSeconds);
	}

	public function setUrl(string $url): self
	{
		$trimmed = rtrim(trim($url), '/');
		if ($trimmed !== '' && !str_ends_with($trimmed, '/render')) {
			$trimmed .= '/render';
		}

		$this->url = $trimmed !== '' ? $trimmed : 'http://127.0.0.1:13714/render';

		return $this;
	}

	public function setEnabled(bool $enabled): self
	{
		$this->enabled = $enabled;

		return $this;
	}

	public function setTimeout(float $seconds): self
	{
		$this->timeoutSeconds = max(0.1, $seconds);

		return $this;
	}

	/**
	 * Dispatch the Page Object to the SSR server.
	 *
	 * @param array<string, mixed> $pageObject
	 */
	public function dispatch(array $pageObject): ?SsrResponse
	{
		if (!$this->enabled || $this->url === '' || preg_match('#^https?://#i', $this->url) !== 1) {
			return null;
		}

		$json = json_encode($pageObject, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($json) || $json === '') {
			return null;
		}

		try {
			$context = stream_context_create([
				'http' => [
					'method' => 'POST',
					'header' => "Content-Type: application/json\r\nAccept: application/json\r\nContent-Length: " . strlen($json) . "\r\n",
					'content' => $json,
					'timeout' => $this->timeoutSeconds,
					'ignore_errors' => true,
					'follow_location' => 0,
					'max_redirects' => 0,
				],
			]);

			$raw = @file_get_contents($this->url, false, $context);
			$responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : null;
			$statusLine = is_array($responseHeaders) && isset($responseHeaders[0]) && is_string($responseHeaders[0]) ? $responseHeaders[0] : '';
			if ($statusLine !== '' && preg_match('#^HTTP/\S+\s+(\d{3})#i', $statusLine, $statusMatch) === 1) {
				$httpCode = (int) $statusMatch[1];
				if ($httpCode < 200 || $httpCode >= 300) {
					$this->logSsrFailure(sprintf($this->_('SSR server returned HTTP %1$d at %2$s'), $httpCode, $this->url));

					return null;
				}
			}

			if (!is_string($raw) || trim($raw) === '') {
				$this->logSsrFailure(sprintf($this->_('SSR server unreachable or returned empty response at %s'), $this->url));

				return null;
			}

			$decoded = json_decode($raw, true);
			if (!is_array($decoded) || !isset($decoded['body']) || !is_string($decoded['body'])) {
				$this->logSsrFailure($this->_('SSR server returned malformed JSON payload.'));

				return null;
			}

			$head = [];
			if (isset($decoded['head']) && is_array($decoded['head'])) {
				foreach ($decoded['head'] as $tag) {
					if (is_string($tag) && trim($tag) !== '') {
						$head[] = $tag;
					}
				}
			}

			return new SsrResponse($head, $decoded['body']);
		} catch (Throwable $e) {
			$this->logSsrFailure(sprintf($this->_('SSR dispatch exception: %s'), $e->getMessage()));

			return null;
		}
	}

	private function logSsrFailure(string $message): void
	{
		try {
			$this->wire()->log->save('inertia-ssr', $message);
		} catch (Throwable) {
			// Guard against recursive logging errors when filesystem log is unwritable
		}
	}
}
