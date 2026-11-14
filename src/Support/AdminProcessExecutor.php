<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Support;

use Closure;
use ProcessWire\Breadcrumb;
use ProcessWire\Breadcrumbs;
use ProcessWire\HookEvent;
use ProcessWire\Inertia;
use ProcessWire\Page;
use ProcessWire\Process;
use ProcessWire\ProcessController;
use ProcessWire\Wire;
use ProcessWire\Wire404Exception;
use ProcessWire\WireCSRFException;
use ProcessWire\WirePermissionException;
use Totoglu\Inertia\PageResponse;
use Totoglu\Inertia\Response;

use function ProcessWire\__;

/**
 * Executes Inertia-enabled Admin `Process` modules during `Page::render` (`template=admin`),
 * bypassing classic `wire/core/admin.php` while enforcing ProcessWire RBAC, CSRF validation,
 * and clean `WireCSRFException` / `WirePermissionException` / `Wire404Exception` recovery.
 */
final class AdminProcessExecutor extends Wire
{
	public function __construct(private readonly Inertia $module)
	{
		parent::__construct();
	}

	/**
	 * @param Closure(): bool $checkVersionConflict
	 * @param Closure(string): bool $isSafeBackTarget
	 * @param Closure(?PageResponse): string $resolveOutput
	 */
	public function execute(
		HookEvent $event,
		Page $page,
		Closure $checkVersionConflict,
		Closure $isSafeBackTarget,
		Closure $resolveOutput,
		string $defaultCsrfErrorMessage
	): void {
		$wire = $event->wire() ?? $this->wire() ?? $this->module->wire();
		$request = $this->module->request;
		$response = $this->module->response;

		$response->setHeader('X-Frame-Options', 'SAMEORIGIN');
		if ($this->module->syncJsonToPost) {
			$request->syncJsonToPost();
		} else {
			$request->syncCsrfToPost();
		}
		$this->module->enforceDemoModeGuard();

		// Allow classic AdminThemeUikit flyout navigation requests (`navJSON`) to run through standard admin.php
		if (strtolower((string) $wire->input->urlSegment1) === 'navjson') {
			return;
		}

		if ($checkVersionConflict()) {
			$response->applyHeaders();
			$event->replace = true;
			$event->return = '';

			return;
		}

		if ($wire->user->isGuest()) {
			if ($request->isInertia()) {
				$adminUrl = (string) ($wire->config->urls->admin ?: ($wire->config->urls->get('admin') ?: '/processwire/'));
				$this->module->location(rtrim($adminUrl, '/') . '/login/');
				$response->applyHeaders();
				$event->replace = true;
				$event->return = '';
			}

			return;
		}

		if ($page->isTrash() || ($page->isUnpublished() && !$page->editable())) {
			return;
		}

		$prevOutputFormatting = $wire->pages->of();
		$wire->pages->of(false);

		try {
			if (!$wire->breadcrumbs instanceof Breadcrumbs) {
				$breadcrumbs = new Breadcrumbs();
				$wire->wire($breadcrumbs);
				$wire->wire('breadcrumbs', $breadcrumbs);
				foreach ($page->parents() as $parent) {
					if ($parent instanceof Page && $parent->id > 1) {
						$breadcrumbs->add(new Breadcrumb($parent->url, (string) $parent->get('title|name')));
					}
				}
			}

			$rawProcess = $page->getUnformatted('process') ?: $page->process;
			$controller = new ProcessController();
			$wire->wire($controller);
			if ($rawProcess instanceof Process) {
				$controller->setProcess($rawProcess);
			} else {
				$controller->setProcessName((string) $rawProcess);
			}

			try {
				$process = $controller->getProcess();
				if (!$process instanceof Process) {
					return;
				}

				if (
					method_exists($process, 'inertiaFullPageAdmin')
					&& !$process->inertiaFullPageAdmin()
				) {
					if ($request->isInertia()) {
						$this->module->location($wire->input->url(true));
						$response->applyHeaders();
						$event->replace = true;
						$event->return = '';
					}

					return;
				}

				if (
					!$request->isDemoMode()
					&& $request->isMutatingMethod()
					&& (bool) $wire->config->protectCSRF
					&& method_exists($process, 'shouldValidateCsrf')
					&& $process->shouldValidateCsrf()
				) {
					try {
						$request->validateCsrf(true);
					} catch (WirePermissionException $csrfEx) {
						throw new WireCSRFException($csrfEx->getMessage(), $csrfEx->getCode(), $csrfEx);
					}
				}

				$prevEntityEncode = function_exists('ProcessWire\\__') ? __(true, 'entityEncode', false) : null;
				try {
					$content = $controller->execute();
				} finally {
					if (function_exists('ProcessWire\\__')) {
						__(true, 'entityEncode', $prevEntityEncode ?? 1);
					}
				}
			} catch (WireCSRFException $csrfEx) {
				$adminUrl = (string) ($wire->config->urls->admin ?: ($wire->config->urls->get('admin') ?: '/processwire/'));
				if ($wire->user->isGuest()) {
					if ($request->isInertia()) {
						$this->module->location(rtrim($adminUrl, '/') . '/login/');
						$response->applyHeaders();
						$event->replace = true;
						$event->return = '';
					}

					return;
				}

				$csrfMessage = $csrfEx->getMessage() !== ''
					? $csrfEx->getMessage()
					: $defaultCsrfErrorMessage;
				$wire->error($csrfMessage);

				$referer = $request->header('Referer');
				$fallbackTarget = (string) ($wire->input->url(true) ?: ($page->url ?: $adminUrl));
				$backTarget = ($referer !== null && $isSafeBackTarget($referer)) ? $referer : $fallbackTarget;

				if ($request->isInertia()) {
					$this->module->location($backTarget);
				} else {
					$response->setStatusCode(303);
					$response->setHeader('Location', $response->sanitizeNavigationUrl($backTarget));
				}
				$response->applyHeaders();
				$event->replace = true;
				$event->return = '';

				return;
			} catch (WirePermissionException $permEx) {
				if ($request->isInertia()) {
					$adminUrl = (string) ($wire->config->urls->admin ?: ($wire->config->urls->get('admin') ?: '/processwire/'));
					if ($wire->user->isGuest()) {
						$this->module->location(rtrim($adminUrl, '/') . '/login/');
					} else {
						if ($permEx->getMessage() !== '') {
							$wire->error($permEx->getMessage());
						}
						$this->module->location($adminUrl);
					}
					$response->applyHeaders();
					$event->replace = true;
					$event->return = '';
				}

				return;
			} catch (Wire404Exception) {
				if ($request->isInertia()) {
					$response->setStatusCode(404);
					$response->applyHeaders();
					$event->replace = true;
					$event->return = '';
				}

				return;
			}

			if ($content instanceof PageResponse) {
				$event->replace = true;
				$event->return = $resolveOutput($content);

				return;
			}

			if ($this->module->getActiveResponse() !== null) {
				$event->replace = true;
				$event->return = $resolveOutput(null);

				return;
			}

			if ($content instanceof Response) {
				$content->applyHeaders();
				$event->replace = true;
				$event->return = '';

				return;
			}

			if (is_string($content)) {
				if ($this->isJsonString($content) && !headers_sent() && PHP_SAPI !== 'cli') {
					header('Content-Type: application/json; charset=utf-8');
				}
				$event->replace = true;
				$event->return = $content;
			}
		} finally {
			$wire->pages->of($prevOutputFormatting);
		}
	}

	private function isJsonString(string $value): bool
	{
		$trimmed = ltrim($value);
		if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
			return false;
		}

		return json_validate($trimmed);
	}
}
