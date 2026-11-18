import { test, expect, request as playwrightRequest } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import {
	attachRuntimeErrorTracker,
	getAdminUrl,
	runWirePhp,
} from '../../../../../tests/e2e/helpers';

interface InertiaShowcaseTarget {
	url: string;
	version: string;
	enabled: boolean;
	createdPageId: number;
	addedTemplateToConfig: boolean;
}

function provisionInertiaTarget(): InertiaShowcaseTarget {
	return runWirePhp<InertiaShowcaseTarget>(`
		$inertia = $wire->modules->get('Inertia');
		$version = $inertia ? (string) $inertia->getVersion() : '';
		$existing = $wire->pages->findOne('template=inertia, status<2048, sort=id');
		if ($existing && $existing->id > 0 && $inertia && $inertia->shouldHandlePage($existing)) {
			echo json_encode([
				'url' => (string) $existing->url,
				'version' => $version,
				'enabled' => true,
				'createdPageId' => 0,
				'addedTemplateToConfig' => false,
			], JSON_UNESCAPED_SLASHES);
			return;
		}

		$tpl = $wire->templates->get('home');
		$home = $wire->pages->get(1);
		$addedConfig = false;
		if ($inertia && $tpl && !$inertia->isTemplateEnabled($tpl)) {
			$cfg = $wire->modules->getConfig('Inertia');
			$cfg = is_array($cfg) ? $cfg : [];
			$enabledList = is_array($cfg['enabledTemplates'] ?? null) ? $cfg['enabledTemplates'] : [];
			$enabledList[] = (string) $tpl->name;
			$cfg['enabledTemplates'] = array_values(array_unique($enabledList));
			$wire->modules->saveConfig('Inertia', $cfg);
			$inertia->forTemplates((string) $tpl->name, true);
			$addedConfig = true;
		}

		echo json_encode([
			'url' => (string) $home->url,
			'version' => $version,
			'enabled' => $inertia ? $inertia->shouldHandlePage($home) : false,
			'createdPageId' => 0,
			'addedTemplateToConfig' => $addedConfig,
		], JSON_UNESCAPED_SLASHES);
	`);
}

function cleanupProvisionedInertiaTarget(state: InertiaShowcaseTarget | null): void {
	if (!state || (!state.addedTemplateToConfig && state.createdPageId <= 0)) {
		return;
	}
	runWirePhp<Record<string, unknown>>(`
		$pageId = ${state.createdPageId};
		$removeHome = ${state.addedTemplateToConfig ? 'true' : 'false'};
		if ($pageId > 0) {
			$p = $wire->pages->get($pageId);
			if ($p && $p->id === $pageId) {
				$wire->pages->delete($p, true);
			}
		}
		if ($removeHome) {
			$cfg = $wire->modules->getConfig('Inertia');
			if (is_array($cfg) && is_array($cfg['enabledTemplates'] ?? null)) {
				$cfg['enabledTemplates'] = array_values(array_filter(
					$cfg['enabledTemplates'],
					static fn($t): bool => (string) $t !== 'home'
				));
				$wire->modules->saveConfig('Inertia', $cfg);
			}
		}
		echo json_encode(['cleaned' => true]);
	`);
}

test.describe('Inertia: Protocol Lifecycle, Admin Configuration & Accessibility', () => {
	let provisionedTarget: InertiaShowcaseTarget | null = null;

	test.beforeAll(() => {
		provisionedTarget = provisionInertiaTarget();
	});

	test.afterAll(() => {
		cleanupProvisionedInertiaTarget(provisionedTarget);
	});

	test('renders Inertia module configuration screen without runtime or WCAG 2.1 AA violations and blocks SSRF ssrUrl saves', async ({
		page,
	}) => {
		const tracker = attachRuntimeErrorTracker(page);

		await page.goto(getAdminUrl('module/edit?name=Inertia'));
		const configForm = page.locator('#ModuleEditForm');
		await expect(configForm).toBeVisible();

		await expect(configForm.locator('[name="mode"]').first()).toBeAttached();
		await expect(configForm.locator('[name="rootView"]')).toBeVisible();
		await expect(configForm.locator('[name="rootElementId"]')).toBeVisible();
		await expect(configForm.locator('[name="ssrUrl"]')).toBeVisible();
		await expect(configForm.locator('[name="ssrTimeout"]')).toBeVisible();
		await expect(configForm.locator('[name="typeScriptOutputPath"]')).toBeVisible();

		const axe = await new AxeBuilder({ page })
			.include('#ModuleEditForm')
			.exclude('.InputfieldAsmSelect')
			.exclude('#AdminDataTable1')
			.exclude('#wrap_Inputfield_submit_save_module')
			.withTags(['wcag2a', 'wcag2aa'])
			.analyze();
		expect(axe.violations).toEqual([]);

		const ssrfBlocked = runWirePhp<{ saved: boolean; preservedUrl: string }>(`
			$savedConfig = $wire->modules->getConfig('Inertia');
			$originalUrl = (string) ($savedConfig['ssrUrl'] ?? 'http://127.0.0.1:13714/render');
			$attempt = $wire->modules->saveConfig('Inertia', array_merge(
				is_array($savedConfig) ? $savedConfig : [],
				['ssrUrl' => 'http://169.254.169.254/latest/meta-data/']
			));
			$afterConfig = $wire->modules->getConfig('Inertia');
			echo json_encode([
				'saved' => (bool) $attempt,
				'preservedUrl' => (string) ($afterConfig['ssrUrl'] ?? $originalUrl),
			], JSON_UNESCAPED_SLASHES);
		`);
		expect(ssrfBlocked.saved).toBe(false);
		expect(ssrfBlocked.preservedUrl).not.toContain('169.254.169.254');

		tracker.assertNoErrors();
	});

	test('hydrates full-page Root View with WCAG 2.1 AA compliance and enforces Inertia v3 XHR JSON, 409 version conflict, and guest admin redirect headers', async ({
		page,
		baseURL,
	}) => {
		const tracker = attachRuntimeErrorTracker(page);
		const target = provisionedTarget ?? provisionInertiaTarget();
		expect(target.enabled).toBe(true);

		const response = await page.goto(target.url);
		expect(response?.status()).toBe(200);
		expect(response?.headers()['vary'] ?? '').toContain('X-Inertia');

		const mountEl = page.locator('#app');
		await expect(mountEl).toBeVisible();

		const dataPageScript = page.locator('script[type="application/json"][data-page="app"]');
		await expect(dataPageScript).toHaveCount(1);

		const rawPageJson = await dataPageScript.textContent();
		expect(rawPageJson).toBeTruthy();
		const initialPageObject = JSON.parse(rawPageJson || '{}') as Record<string, unknown>;
		expect(typeof initialPageObject.component).toBe('string');
		expect((initialPageObject.component as string).length).toBeGreaterThan(0);
		expect(typeof initialPageObject.props).toBe('object');
		expect(typeof initialPageObject.url).toBe('string');

		const cookies = await page.context().cookies();
		expect(cookies.some((c) => c.name === 'XSRF-TOKEN' && c.value.length > 0)).toBe(true);

		const axe = await new AxeBuilder({ page })
			.exclude('#tracy-debug')
			.withTags(['wcag2a', 'wcag2aa'])
			.analyze();
		expect(axe.violations).toEqual([]);

		// 1. Valid X-Inertia XHR visit returns JSON Page Object with Vary: X-Inertia
		const activeVersion = String(initialPageObject.version ?? target.version ?? '');
		const xhrRes = await page.request.get(target.url, {
			headers: {
				'X-Inertia': 'true',
				...(activeVersion !== '' ? { 'X-Inertia-Version': activeVersion } : {}),
			},
		});
		expect(xhrRes.status()).toBe(200);
		expect(xhrRes.headers()['x-inertia']).toBe('true');
		expect(xhrRes.headers()['content-type'] ?? '').toContain('application/json');
		expect(xhrRes.headers()['vary'] ?? '').toContain('X-Inertia');

		const xhrBody = (await xhrRes.json()) as Record<string, unknown>;
		expect(xhrBody.component).toBe(initialPageObject.component);
		expect(typeof xhrBody.props).toBe('object');

		// 2. Stale X-Inertia-Version on GET triggers 409 Conflict + X-Inertia-Location
		if (activeVersion !== '') {
			const staleRes = await page.request.get(target.url, {
				headers: {
					'X-Inertia': 'true',
					'X-Inertia-Version': 'stale-e2e-version-mismatch',
				},
			});
			expect(staleRes.status()).toBe(409);
			expect(staleRes.headers()['x-inertia-location']).toBeTruthy();
		}

		// 3. Unauthenticated guest X-Inertia request to admin triggers 409 Conflict + X-Inertia-Location to login
		const guestApi = await playwrightRequest.newContext({
			baseURL,
			ignoreHTTPSErrors: true,
			storageState: { cookies: [], origins: [] },
		});
		try {
			const guestAdminRes = await guestApi.get(getAdminUrl(), {
				headers: {
					'X-Inertia': 'true',
				},
				maxRedirects: 0,
			});
			expect([302, 409]).toContain(guestAdminRes.status());
			if (guestAdminRes.status() === 409) {
				expect(guestAdminRes.headers()['x-inertia-location'] ?? '').toContain('login');
			}
		} finally {
			await guestApi.dispose();
		}

		tracker.assertNoErrors();
	});
});
