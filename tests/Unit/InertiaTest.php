<?php

declare(strict_types=1);

use ProcessWire\Field;
use ProcessWire\Inertia;
use ProcessWire\InputfieldText;
use ProcessWire\InputfieldWrapper;
use ProcessWire\NoticeMessage;
use ProcessWire\Page;
use ProcessWire\PageArray;
use ProcessWire\Pageimage;
use ProcessWire\Pageimages;
use ProcessWire\Process;
use ProcessWire\Template;
use ProcessWire\User;
use Symfony\Component\Console\Tester\CommandTester;
use Totoglu\Inertia\Commands\InertiaTypesCommand;
use Totoglu\Inertia\Contracts\InertiaSerializable;
use Totoglu\Inertia\Contracts\SsrGatewayInterface;
use Totoglu\Inertia\InteractWithInertia;
use Totoglu\Inertia\PageResponse;
use Totoglu\Inertia\Provider\Field\MediaFieldDataProvider;
use Totoglu\Inertia\Provider\Field\PageReferenceFieldDataProvider;
use Totoglu\Inertia\Provider\Field\RepeaterFieldDataProvider;
use Totoglu\Inertia\Provider\Field\ScalarFieldDataProvider;
use Totoglu\Inertia\Provider\FieldDataProvider;
use Totoglu\Inertia\Provider\ProviderRegistry;
use Totoglu\Inertia\Provider\TemplateDataProvider;
use Totoglu\Inertia\Provider\UserDataProvider;
use Totoglu\Inertia\Ssr\HttpGateway;
use Totoglu\Inertia\Ssr\SsrResponse;
use Totoglu\Inertia\Support\PropResolver;
use Totoglu\Inertia\Support\TemplateMode;

use function ProcessWire\inertia;
use function ProcessWire\setting;
use function ProcessWire\wire;

beforeEach(function (): void {
	if (function_exists('ProcessWire\setting')) {
		setting('inertia', []);
	}
	$inertia = Inertia::instance();
	$inertia->resetState();
	$inertia->setConfigData($inertia->getDefaultConfig());
});

afterEach(function (): void {
	if (function_exists('ProcessWire\setting')) {
		setting('inertia', []);
	}
	wire()->pages?->setOutputFormatting(false);
	$inertia = Inertia::instance();
	$inertia->resetState();
	$savedConfig = wire()->modules->getConfig('Inertia');
	$inertia->setConfigData(array_merge(
		$inertia->getDefaultConfig(),
		is_array($savedConfig) ? $savedConfig : []
	));
});

it('resolves shared props, dot-notation, closures, and omits OptionalProp and DeferredProp on initial visit', function (): void {
	$inertia = inertia();
	$inertia->version('v1-hash');
	$inertia->share('auth.user', fn(): array => ['id' => 42, 'name' => 'Iskender']);
	$inertia->share('auth.role', 'superuser');

	$optionalEvaluated = false;
	$deferredEvaluated = false;

	$response = inertia('Dashboard/Index', [
		'title' => 'Overview',
		'optionalSecret' => $inertia->optional(function () use (&$optionalEvaluated): string {
			$optionalEvaluated = true;

			return 'only-on-partial';
		}),
		'heavyStats' => $inertia->defer(function () use (&$deferredEvaluated): array {
			$deferredEvaluated = true;

			return ['visits' => 9000];
		}, 'analytics'),
	])->url('/dashboard');

	$pageObject = $response->toPageObject();

	expect($optionalEvaluated)->toBeFalse()
		->and($deferredEvaluated)->toBeFalse()
		->and($pageObject['component'])->toBe('Dashboard/Index')
		->and($pageObject['url'])->toBe('/dashboard')
		->and($pageObject['version'])->toBe('v1-hash')
		->and($pageObject['props']['title'])->toBe('Overview')
		->and($pageObject['props']['auth'])->toBe([
			'user' => ['id' => 42, 'name' => 'Iskender'],
			'role' => 'superuser',
		])
		->and($pageObject['props'])->not->toHaveKey('optionalSecret')
		->and($pageObject['props'])->not->toHaveKey('heavyStats')
		->and($pageObject['deferredProps'])->toBe([
			'analytics' => ['heavyStats'],
		]);
});

it('evaluates DeferredProp and OptionalProp when requested via X-Inertia-Partial-Data and always includes AlwaysProp', function (): void {
	$inertia = inertia();
	$inertia->request->fake([
		'X-Inertia' => 'true',
		'X-Inertia-Partial-Component' => 'Dashboard/Index',
		'X-Inertia-Partial-Data' => 'heavyStats,optionalSecret',
	]);

	$response = inertia('Dashboard/Index', [
		'title' => 'Ignored on partial',
		'optionalSecret' => $inertia->optional(fn(): string => 'loaded-optional'),
		'heavyStats' => $inertia->defer(fn(): array => ['visits' => 9000], 'analytics'),
		'permissions' => $inertia->always(fn(): array => ['page-edit']),
	]);

	$pageObject = $response->toPageObject();

	expect($pageObject['props'])->toHaveKey('optionalSecret', 'loaded-optional')
		->and($pageObject['props'])->toHaveKey('heavyStats', ['visits' => 9000])
		->and($pageObject['props'])->toHaveKey('permissions', ['page-edit'])
		->and($pageObject['props'])->toHaveKey('errors')
		->and($pageObject['props'])->not->toHaveKey('title')
		->and($pageObject)->not->toHaveKey('deferredProps');
});

it('supports MergeProp append, prepend, deepMerge, matchOn, and X-Inertia-Reset', function (): void {
	$inertia = inertia();

	$response = inertia('Feed/Index', [
		'posts' => $inertia->merge([['id' => 1, 'title' => 'First']])->matchOn('id'),
		'notifications' => $inertia->prepend([['id' => 10, 'text' => 'Alert']])->matchOn('id'),
		'filters' => $inertia->deepMerge(['sort' => 'desc']),
		'lazyComments' => $inertia->defer(fn(): array => [['id' => 99]])->merge()->matchOn('id'),
	]);

	$pageObject = $response->toPageObject();

	expect($pageObject['mergeProps'])->toBe(['posts', 'lazyComments'])
		->and($pageObject['prependProps'])->toBe(['notifications'])
		->and($pageObject['deepMergeProps'])->toBe(['filters'])
		->and($pageObject['matchPropsOn'])->toBe(['posts.id', 'notifications.id', 'lazyComments.id']);

	// Now simulate X-Inertia-Reset for 'posts'
	$inertia->request->fake([
		'X-Inertia' => 'true',
		'X-Inertia-Reset' => 'posts',
	]);

	$resetResponse = inertia('Feed/Index', [
		'posts' => $inertia->merge([['id' => 1, 'title' => 'First']])->matchOn('id'),
	]);

	$resetObject = $resetResponse->toPageObject();

	expect($resetObject)->not->toHaveKey('mergeProps')
		->and($resetObject)->not->toHaveKey('matchPropsOn');
});

it('transforms ProcessWire Page objects and InertiaSerializable contracts automatically', function (): void {
	$home = wire()->pages->get('/');

	$customDto = new class implements InertiaSerializable {
		public function toInertiaProp(): array
		{
			return ['custom' => true, 'label' => 'Serializable DTO'];
		}
	};

	$pageObject = inertia('Home/Show', [
		'homePage' => $home,
		'dto' => $customDto,
	])->toPageObject();

	expect($pageObject['props']['homePage'])->toBeArray()
		->and($pageObject['props']['homePage']['id'])->toBe(1)
		->and($pageObject['props']['homePage']['url'])->toBe('/')
		->and($pageObject['props']['dto'])->toBe(['custom' => true, 'label' => 'Serializable DTO']);
});

it('flashes validation errors from arrays and InputfieldWrapper and scopes them to X-Inertia-Error-Bag', function (): void {
	$inertia = inertia();
	$inertia->request->fake([
		'X-Inertia' => 'true',
		'X-Inertia-Error-Bag' => 'updateProfile',
	]);

	/** @var InputfieldWrapper $form */
	$form = wire()->modules->get('InputfieldWrapper');
	/** @var InputfieldText $emailField */
	$emailField = wire()->modules->get('InputfieldText');
	$emailField->attr('name', 'email');
	$emailField->error('Email address is invalid.');
	$form->add($emailField);

	$inertia->withErrors($form);

	$pageObject = inertia('Profile/Edit')->toPageObject();

	expect($pageObject['props']['errors'])->toBe([
		'updateProfile' => [
			'email' => 'Email address is invalid.',
		],
	]);
});

it('triggers 409 Conflict with X-Inertia-Location on stale asset version during GET Inertia requests', function (): void {
	$inertia = inertia();
	$inertia->version('server-v3');
	$inertia->request->fake([
		'X-Inertia' => 'true',
		'X-Inertia-Version' => 'client-v1',
	], 'GET');

	$conflict = $inertia->checkVersionConflict();

	expect($conflict)->toBeTrue()
		->and($inertia->response->getStatusCode())->toBe(409)
		->and($inertia->response->getHeader('X-Inertia-Location'))->not->toBeNull();
});

it('parses JSON request bodies, supports _method spoofing, and downgrades PUT/PATCH/DELETE redirects to 303', function (): void {
	$inertia = inertia();
	$inertia->request->fake(
		['Content-Type' => 'application/json', 'X-Inertia' => 'true'],
		'POST',
		json_encode(['_method' => 'DELETE', 'confirm' => true, 'nested' => ['id' => 7]], JSON_THROW_ON_ERROR)
	);

	expect($inertia->request->isJson())->toBeTrue()
		->and($inertia->request->method())->toBe('DELETE')
		->and($inertia->request->input('confirm'))->toBeTrue()
		->and($inertia->request->input('nested.id'))->toBe(7);

	$inertia->back('/items');

	expect($inertia->response->getStatusCode())->toBe(303)
		->and($inertia->response->getHeader('Location'))->toBe('/items');
});

it('binds Custom Page Classes via InteractWithInertia and supports viewData / withViewData / shared and SsrGateway head merging', function (): void {
	$inertia = inertia();
	$inertia->version('v1');

	// 1. $inertia->shared() and $inertia->viewData() / $inertia->withViewData() (including lazy Closures) work globally
	$inertia->shared('locale', 'tr')
		->viewData('locale', 'tr')
		->withViewData('head', static fn(array $pageObj): string => '<title inertia>' . (string) ($pageObj['props']['title'] ?? '') . ' &mdash; ProcessWire</title>');

	expect($inertia->getShared('locale'))->toBe('tr')
		->and($inertia->getViewData('locale'))->toBe('tr')
		->and($inertia->getViewData('head'))->toBeInstanceOf(Closure::class);

	$response = inertia('Articles/Show', [
		'title' => 'Hello SPA',
	])
		->url('/articles/hello-spa')
		->encryptHistory();

	$html = $response->toOutput();

	expect($html)
		->toContain('<!DOCTYPE html>')
		->toContain('<html lang="tr"')
		->toContain('<title inertia>Hello SPA &mdash; ProcessWire</title>')
		->toContain('<div id="app"></div>')
		->toContain('<script type="application/json" data-page="app">')
		->toContain('"encryptHistory":true')
		->toContain('"locale":"tr"');

	// 2. Test with a mock SSR Gateway returning pre-rendered head and body (combines SSR head with viewData head)
	$mockGateway = new class implements SsrGatewayInterface {
		public function dispatch(array $pageObject): ?SsrResponse
		{
			return new SsrResponse(
				['<title inertia>SSR Title</title>'],
				'<div id="app" data-ssr="true"><h1>SSR Rendered Body</h1></div>'
			);
		}
	};

	$inertia->resetState();
	$inertia->setSsrGateway($mockGateway);
	$inertia->viewData('head', '<meta name="custom-ssr" content="1" />');
	$ssrResponse = inertia('Articles/Show', ['title' => 'Hello SSR']);
	$ssrHtml = $ssrResponse->toOutput();

	expect($ssrHtml)
		->toContain('<title inertia>SSR Title</title>')
		->toContain('<meta name="custom-ssr" content="1" />')
		->toContain('<div id="app" data-ssr="true"><h1>SSR Rendered Body</h1></div>')
		->toContain('<script type="application/json" data-page="app">');

	// 3. Verify InteractWithInertia trait on Custom Page Classes (inertiaRender, inertiaShared, inertiaWithData, inertiaViewData, inertiaWithViewData)
	$customPage = new class (wire()->templates->get('basic-page')) extends Page {
		use InteractWithInertia;

		public function inertiaShared(): array
		{
			return ['locale' => 'fr-FR'];
		}

		public function inertiaWithData(): array
		{
			return ['defaultProp' => 'fromPageClass', 'overriddenProp' => 'fromPageClass'];
		}

		public function inertiaViewData(array $pageObject = []): array
		{
			$title = (string) ($pageObject['props']['title'] ?? 'Custom');

			return [
				'locale' => 'fr-FR',
				'head' => '<title inertia>' . $this->wire()->sanitizer->entities($title) . '</title>',
			];
		}

		public function inertiaWithViewData(array $pageObject = []): array
		{
			return [
				'extraViewVar' => 'hello-view',
			];
		}
	};
	wire()->wire($customPage);

	$inertia->resetState();

	// Works even when $customPage !== $wire->page because inertiaRender() binds $customPage onto PageResponse::forPage()
	$pageResp = $customPage->inertiaRender('Articles/Show', [
		'title' => 'Trait Driven Head',
		'overriddenProp' => 'fromExplicitRender',
	])->viewData('inlineViewVar', 'fromResponse');
	$pageObj = $pageResp->toPageObject();

	expect($customPage->inertia())->toBe($inertia)
		->and($pageResp->getPage())->toBe($customPage)
		->and($pageResp->getViewData('inlineViewVar'))->toBe('fromResponse')
		->and($pageObj['props']['locale'] ?? null)->toBe('fr-FR')
		->and($pageObj['sharedProps'] ?? [])->toContain('locale')
		->and($pageObj['props']['defaultProp'] ?? null)->toBe('fromPageClass')
		->and($pageObj['props']['overriddenProp'] ?? null)->toBe('fromExplicitRender');

	$traitHtml = $pageResp->toOutput();
	expect($traitHtml)
		->toContain('<html lang="fr-FR"')
		->toContain('<title inertia>Trait Driven Head</title>');

	// 4. Verify InteractWithInertia trait on Process modules (forProcess, inertiaRootView, inertiaFullPageAdmin, isProcessInertiaEnabled)
	$customProcess = new class extends Process {
		use InteractWithInertia;

		public function inertiaShared(): array
		{
			return ['workspace' => 'admin-dashboard'];
		}

		public function inertiaWithData(): array
		{
			return ['defaultSection' => 'overview'];
		}

		public function inertiaViewData(array $pageObject = []): array
		{
			return [
				'locale' => 'de-DE',
				'head' => '<title inertia>Process Admin Workspace</title>',
			];
		}
	};
	wire()->wire($customProcess);

	$inertia->resetState();
	$processResp = $customProcess->inertiaRender('Admin/Workspace', [
		'activeTab' => 'migrations',
	]);
	$processObj = $processResp->toPageObject();

	expect($inertia->isProcessInertiaEnabled($customProcess))->toBeTrue()
		->and($inertia->isProcessInertiaEnabled('ProcessPageEdit'))->toBeFalse()
		->and($customProcess->inertiaFullPageAdmin())->toBeTrue()
		->and($processResp->getProcess())->toBe($customProcess)
		->and($processObj['props']['workspace'] ?? null)->toBe('admin-dashboard')
		->and($processObj['sharedProps'] ?? [])->toContain('workspace')
		->and($processObj['props']['defaultSection'] ?? null)->toBe('overview')
		->and($processObj['props']['activeTab'] ?? null)->toBe('migrations');

	$processHtml = $processResp->toOutput();
	expect($processHtml)
		->toContain('<html lang="de-DE"')
		->toContain('<title inertia>Process Admin Workspace</title>');
});

it('switches between full Root View HTML and X-Inertia JSON in hybrid Page::render lifecycle without breaking classic pages', function (): void {
	$inertia = inertia();
	$inertia->version('hybrid-v1');

	// 1. Classic page render (no inertia() call) -> returns standard HTML untouched
	$home = wire()->pages->get('/');
	$classicHtml = $home->render();
	expect($classicHtml)->toContain('<!DOCTYPE html>')
		->and($classicHtml)->toContain('id="html-body"');

	// 2. Simulate a template or hook registering an Inertia response on a full browser visit
	$inertia->render('Pages/Home', ['headline' => 'Hybrid SPA']);
	$fullInertiaHtml = $inertia->renderPageResponse($inertia->getActiveResponse());

	expect($fullInertiaHtml)
		->toContain('<div id="app"></div>')
		->toContain('<script type="application/json" data-page="app">')
		->toContain('"component":"Pages/Home"')
		->toContain('"headline":"Hybrid SPA"')
		->and($fullInertiaHtml)->not->toContain('id="topnav"');

	// 3. Simulate an X-Inertia: true visit -> returns pure JSON Page Object
	$inertia->request->fake([
		'X-Inertia' => 'true',
		'X-Inertia-Version' => 'hybrid-v1',
	], 'GET');

	$inertia->render('Pages/Home', ['headline' => 'Hybrid JSON']);
	$jsonOutput = $inertia->renderPageResponse($inertia->getActiveResponse());
	$decoded = json_decode($jsonOutput, true);

	expect($decoded)->toBeArray()
		->and($decoded['component'])->toBe('Pages/Home')
		->and($decoded['props']['headline'])->toBe('Hybrid JSON')
		->and($inertia->response->getHeader('X-Inertia'))->toBe('true')
		->and($inertia->response->getHeader('Vary'))->toBe('X-Inertia');
});

it('does not evaluate OptionalProp or DeferredProp when X-Inertia-Partial-Except is used without X-Inertia-Partial-Data', function (): void {
	$inertia = inertia();
	$inertia->request->fake([
		'X-Inertia' => 'true',
		'X-Inertia-Partial-Component' => 'Dashboard/Index',
		'X-Inertia-Partial-Except' => 'excludedKey',
	]);

	$optionalCalled = false;
	$deferredCalled = false;

	$pageObject = inertia('Dashboard/Index', [
		'includedKey' => 'visible',
		'excludedKey' => 'hidden',
		'opt' => $inertia->optional(function () use (&$optionalCalled): string {
			$optionalCalled = true;

			return 'should-not-run';
		}),
		'def' => $inertia->defer(function () use (&$deferredCalled): string {
			$deferredCalled = true;

			return 'should-not-run';
		})->merge()->once(),
	])->toPageObject();

	expect($optionalCalled)->toBeFalse()
		->and($deferredCalled)->toBeFalse()
		->and($pageObject['props'])->toHaveKey('includedKey', 'visible')
		->and($pageObject['props'])->not->toHaveKey('excludedKey')
		->and($pageObject['props'])->not->toHaveKey('opt')
		->and($pageObject['props'])->not->toHaveKey('def')
		->and($pageObject)->not->toHaveKey('mergeProps')
		->and($pageObject)->not->toHaveKey('onceProps');
});

it('preserves nested DeferredProp and MergeProp metadata when registered via dot-notation', function (): void {
	$inertia = inertia();
	$inertia->share('auth.permissions', $inertia->defer(fn(): array => ['edit', 'publish'], 'security'));
	$inertia->share('feed.items', $inertia->merge([['id' => 5]])->matchOn('id'));

	$pageObject = inertia('Nested/View')->toPageObject();

	expect($pageObject['deferredProps'])->toBe([
		'security' => ['auth.permissions'],
	])
		->and($pageObject['mergeProps'])->toBe(['feed.items'])
		->and($pageObject['matchPropsOn'])->toBe(['feed.items.id'])
		->and($pageObject['props']['auth'] ?? [])->not->toHaveKey('permissions')
		->and($pageObject['props']['feed']['items'])->toBe([['id' => 5]]);
});

it('configures native template noAppendTemplateFile via forTemplates() and forAllTemplates() without mutating $config->useMarkupRegions', function (): void {
	$config = wire()->config;
	$originalMarkupRegions = (bool) $config->useMarkupRegions;

	$inertia = inertia();
	$home = wire()->pages->get('/');
	$homeTemplate = $home->template;
	$originalNoAppend = $homeTemplate->noAppendTemplateFile;

	try {
		expect($inertia->isTemplateEnabled('home'))->toBeFalse()
			->and($inertia->shouldHandlePage($home))->toBeFalse();

		$inertia->forTemplates(['home']);

		expect($inertia->isTemplateEnabled('home'))->toBeTrue()
			->and($inertia->isTemplateEnabled('admin'))->toBeFalse()
			->and($inertia->shouldHandlePage($home))->toBeTrue();

		$inertia->configurePageTemplateFiles($home);

		expect((bool) $homeTemplate->noAppendTemplateFile)->toBeTrue()
			->and((bool) $config->useMarkupRegions)->toBe($originalMarkupRegions);

		$inertia->forAllTemplates(true);
		expect($inertia->isTemplateEnabled('basic-page'))->toBeTrue()
			->and($inertia->isTemplateEnabled('admin'))->toBeFalse();
	} finally {
		$homeTemplate->noAppendTemplateFile = $originalNoAppend;
	}
});

it('applies setting("inertia", [...]) overrides lazily, confines rootView paths, and omits excluded MergeProp metadata on partial reloads', function (): void {
	$inertia = inertia();

	// 1. Late setting() override (simulating site/ready.php after module ready())
	setting('inertia', [
		'mode' => TemplateMode::Selected->value,
		'enabledTemplates' => ['home'],
	]);

	expect($inertia->isTemplateEnabled('home'))->toBeTrue()
		->and($inertia->isTemplateEnabled('basic-page'))->toBeFalse();

	// 2. Partial reload excluding a MergeProp must omit mergeProps metadata for that excluded key
	$inertia->request->fake([
		'X-Inertia' => 'true',
		'X-Inertia-Partial-Component' => 'Posts/Index',
		'X-Inertia-Partial-Data' => 'filters',
	]);

	$partialObject = inertia('Posts/Index', [
		'filters' => ['search' => 'pw'],
		'posts' => $inertia->merge([['id' => 1]])->matchOn('id'),
	])->toPageObject();

	expect($partialObject['props'])->toHaveKey('filters', ['search' => 'pw'])
		->and($partialObject['props'])->not->toHaveKey('posts')
		->and($partialObject)->not->toHaveKey('mergeProps')
		->and($partialObject)->not->toHaveKey('matchPropsOn');

	// 3. Response implements Stringable so returning $inertia->location() from a template or URL hook is safe
	$locationResponse = $inertia->location('https://processwire.com');
	expect((string) $locationResponse)->toBe('')
		->and($inertia->response->getStatusCode())->toBe(409)
		->and($inertia->response->getHeader('X-Inertia-Location'))->toBe('https://processwire.com');

	// 4. Attempting to set a rootView outside site/templates/ falls back safely to the stub template
	$inertia->resetState();
	$safeHtml = inertia('Pages/Safe', ['ok' => true])
		->rootView('/etc/passwd')
		->toOutput();

	expect($safeHtml)->toContain('<div id="app"></div>')
		->and($safeHtml)->toContain('<script type="application/json" data-page="app">')
		->and($safeHtml)->toContain('"component":"Pages/Safe"');
});

it('supports Inertia v3 OnceProp (onceProps metadata, custom as() key, until() expiration, X-Inertia-Except-Once-Props skipping, and fresh() override)', function (): void {
	$inertia = inertia();
	$plansEvaluated = 0;

	$makeResponse = function (bool $fresh = false) use ($inertia, &$plansEvaluated): PageResponse {
		return inertia('Billing/Plans', [
			'memberPlans' => $inertia->once(function () use (&$plansEvaluated): array {
				$plansEvaluated++;

				return ['starter', 'pro'];
			})->as('plans')->until(3600)->fresh($fresh),
			'lazyCatalog' => $inertia->optional(fn(): array => ['enterprise'])->once()->as('catalog'),
		]);
	};

	// 1. Initial visit evaluates OnceProp and emits onceProps metadata (including OptionalProp::once())
	$firstObject = $makeResponse()->toPageObject();
	expect($plansEvaluated)->toBe(1)
		->and($firstObject['props']['memberPlans'])->toBe(['starter', 'pro'])
		->and($firstObject['props'])->not->toHaveKey('lazyCatalog')
		->and($firstObject['onceProps'])->toHaveKey('plans')
		->and($firstObject['onceProps']['plans']['prop'])->toBe('memberPlans')
		->and($firstObject['onceProps']['plans']['expiresAt'])->toBeInt()
		->and($firstObject['onceProps'])->toHaveKey('catalog')
		->and($firstObject['onceProps']['catalog']['prop'])->toBe('lazyCatalog');

	// 2. Subsequent visit with X-Inertia-Except-Once-Props: plans skips callback & omits prop from props while retaining onceProps metadata
	$inertia->request->fake([
		'X-Inertia' => 'true',
		'X-Inertia-Except-Once-Props' => 'plans',
	]);

	$secondObject = $makeResponse()->toPageObject();
	expect($plansEvaluated)->toBe(1)
		->and($secondObject['props'])->not->toHaveKey('memberPlans')
		->and($secondObject['onceProps'])->toHaveKey('plans');

	// 3. Calling ->fresh(true) forces re-evaluation even when present in X-Inertia-Except-Once-Props
	$thirdObject = $makeResponse(true)->toPageObject();
	expect($plansEvaluated)->toBe(2)
		->and($thirdObject['props']['memberPlans'])->toBe(['starter', 'pro']);
});

it('supports Inertia v3 ScrollProp with ProcessWire PaginatedArray, X-Inertia-Infinite-Scroll-Merge-Intent, nested MergeProp targeting, withoutSsr(), preserveFragment(), and PHP Enums', function (): void {
	$inertia = inertia();

	// 1. ScrollProp with ProcessWire PageArray pagination
	$paginatedPages = wire()->pages->find('id>0, limit=1');
	$scrollObject = inertia(TemplateMode::Selected, [
		'modeEnum' => TemplateMode::All,
		'posts' => $inertia->scroll($paginatedPages)->matchOn('id'),
		'forum' => $inertia->merge([
			'topics' => [['id' => 10]],
			'announcements' => [['id' => 99]],
		])->append('topics', matchOn: 'id')->prepend('announcements'),
	])->preserveFragment()->toPageObject();

	expect($scrollObject['component'])->toBe('selected')
		->and($scrollObject['props']['modeEnum'])->toBe('all')
		->and($scrollObject['preserveFragment'])->toBeTrue()
		->and($scrollObject['scrollProps']['posts'])->toMatchArray([
			'pageName' => 'page',
			'previousPage' => null,
			'currentPage' => 1,
			'reset' => false,
		])
		->and($scrollObject['mergeProps'])->toContain('posts.data', 'forum.topics')
		->and($scrollObject['prependProps'])->toContain('forum.announcements')
		->and($scrollObject['matchPropsOn'])->toContain('posts.data.id', 'forum.topics.id');

	// 2. X-Inertia-Infinite-Scroll-Merge-Intent: prepend switches ScrollProp to prependProps and ?page=2 syncs with ProcessWire $input->pageNum()
	$_GET['page'] = '2';
	$inertia->request->fake([
		'X-Inertia' => 'true',
		'X-Inertia-Infinite-Scroll-Merge-Intent' => 'prepend',
	]);

	$prependScrollObject = inertia('Posts/Feed', [
		'posts' => $inertia->scroll(static fn() => wire()->pages->find('id>0, sort=id, limit=1')),
	])->toPageObject();
	unset($_GET['page']);

	expect($prependScrollObject['prependProps'])->toBe(['posts.data'])
		->and($prependScrollObject['scrollProps']['posts']['currentPage'])->toBe(2)
		->and($prependScrollObject['scrollProps']['posts']['previousPage'])->toBe(1);

	// Partial reload excluding child wrapper path (posts.data) omits posts.data from mergeProps/prependProps
	$inertia->request->fake([
		'X-Inertia' => 'true',
		'X-Inertia-Partial-Component' => 'Posts/Feed',
		'X-Inertia-Partial-Except' => 'posts.data',
	]);

	$excludedWrapperScroll = inertia('Posts/Feed', [
		'posts' => $inertia->scroll($paginatedPages),
	])->toPageObject();

	expect($excludedWrapperScroll)->not->toHaveKey('mergeProps')
		->and($excludedWrapperScroll)->not->toHaveKey('prependProps');

	// 3. withoutSsr() bypasses SSR Gateway per-response or by pattern
	$inertia->resetState();
	$ssrCalled = false;
	$inertia->setSsrGateway(new class($ssrCalled) implements SsrGatewayInterface {
		public function __construct(private bool &$called)
		{
		}

		public function dispatch(array $pageObject): ?SsrResponse
		{
			$this->called = true;

			return new SsrResponse(['<title>SSR</title>'], '<div id="app">SSR</div>');
		}
	});

	$noSsrHtml = inertia('Dashboard/ClientOnly', ['chart' => [1, 2, 3]])
		->withoutSsr()
		->toOutput();

	expect($ssrCalled)->toBeFalse()
		->and($noSsrHtml)->toContain('<script type="application/json" data-page="app">');

	// 4. Onceable chaining on DeferredProp, MergeProp, and OptionalProp + shareOnce() + nested X-Inertia-Reset
	$inertia->resetState();
	$inertia->shareOnce('sharedRoles', fn(): array => ['admin', 'editor'])->as('global-roles');

	$deferredOnceObject = inertia('Dashboard/Analytics', [
		'heavyReport' => $inertia->defer(fn(): array => ['rows' => 500])->once(as: 'report-cache', until: 1800),
		'cachedFeed' => $inertia->merge([['id' => 1]])->once(),
	])->toPageObject();

	expect($deferredOnceObject['onceProps'])->toHaveKey('report-cache')
		->and($deferredOnceObject['onceProps']['report-cache']['prop'])->toBe('heavyReport')
		->and($deferredOnceObject['onceProps'])->toHaveKey('cachedFeed')
		->and($deferredOnceObject['onceProps'])->toHaveKey('global-roles')
		->and($deferredOnceObject['props']['sharedRoles'])->toBe(['admin', 'editor'])
		->and($deferredOnceObject['deferredProps'])->toBe(['default' => ['heavyReport']])
		->and($deferredOnceObject['mergeProps'])->toBe(['cachedFeed']);

	// When client already holds cached 'report-cache' and 'cachedFeed' via X-Inertia-Except-Once-Props,
	// deferredProps and mergeProps metadata for those skipped once-props must not be emitted
	$inertia->request->fake([
		'X-Inertia' => 'true',
		'X-Inertia-Except-Once-Props' => 'report-cache,cachedFeed',
	]);

	$skippedOnceMetadataObject = inertia('Dashboard/Analytics', [
		'heavyReport' => $inertia->defer(fn(): array => ['rows' => 500])->once(as: 'report-cache', until: 1800),
		'cachedFeed' => $inertia->merge([['id' => 1]])->once(),
	])->toPageObject();

	expect($skippedOnceMetadataObject)->not->toHaveKey('deferredProps')
		->and($skippedOnceMetadataObject)->not->toHaveKey('mergeProps')
		->and($skippedOnceMetadataObject['onceProps'])->toHaveKey('report-cache')
		->and($skippedOnceMetadataObject['onceProps'])->toHaveKey('cachedFeed');

	$inertia->request->fake([
		'X-Inertia' => 'true',
		'X-Inertia-Reset' => 'forum.topics,users.data',
	]);

	$nestedResetObject = inertia('Forum/View', [
		'forum' => $inertia->merge([
			'topics' => [['id' => 10]],
			'announcements' => [['id' => 99]],
		])->append('topics')->matchOn('id')->prepend('announcements'),
		'users' => $inertia->merge([
			'data' => [['id' => 1]],
		])->append(['users.data' => 'id']),
	])->toPageObject();

	expect($nestedResetObject)->not->toHaveKey('mergeProps')
		->and($nestedResetObject['prependProps'])->toBe(['forum.announcements'])
		->and($nestedResetObject)->not->toHaveKey('matchPropsOn');
});

it('generates clean Custom Page Classes and TypeScript definitions via ProviderRegistry without overwriting existing PHP files unless forced', function (): void {
	$inertia = inertia();
	$typeGenerator = $inertia->typeGenerator();

	$homeTemplate = wire()->templates->get('home');
	expect($homeTemplate)->not->toBeNull();

	// 1. Inspect clean PHP Page Class generation for `home` template (no serializer boilerplate)
	$phpCode = $typeGenerator->buildPageClassCode($homeTemplate);
	expect($phpCode)->toContain('class HomePage extends Page')
		->and($phpCode)->toContain('use InteractWithInertia;')
		->and($phpCode)->toContain('public function inertiaWithData(): array')
		->and($phpCode)->not->toContain('toInertiaProp')
		->and($phpCode)->toContain('@property string $title');

	// 2. Inspect TypeScript (.d.ts) generation
	$tsBuilt = $typeGenerator->buildTypeScriptDefinitions([$homeTemplate]);
	expect($tsBuilt['interfaces'])->toContain('InertiaUser', 'HomePageData', 'InertiaPageProps')
		->and($tsBuilt['content'])->toContain('export interface InertiaUser {')
		->and($tsBuilt['content'])->toContain('export interface HomePageData extends InertiaPageBase {')
		->and($tsBuilt['content'])->toContain('title: string;');

	// 3. Existing PHP class protection: calling generatePageClass without $force skips existing file
	$skipResult = $typeGenerator->generatePageClass($homeTemplate, false);
	expect($skipResult['status'])->toBe('skipped')
		->and($skipResult['class'])->toBe('HomePage');
});

it('serializes Page, Field, and User through the 3-tier DataProvider architecture (ProviderRegistry, Inertia::registerProviders hook, and site/inertia/ auto-discovery)', function (): void {
	$inertia = inertia();
	$inertia->resetState();

	$homeTemplate = wire()->templates->get('home');
	expect($homeTemplate)->not->toBeNull();

	// 1. Default runtime Page serialization via TemplateDataProvider + FieldDataProvider
	$page = new Page($homeTemplate);
	$page->id = 777;
	$page->name = 'provider-demo';
	$page->title = 'Provider Demo Title';

	$pageObject = inertia('Home/Show', [
		'page' => $page,
		'auth' => [
			'user' => wire()->user,
		],
	])->toPageObject();

	expect($pageObject['props']['page']['id'])->toBe(777)
		->and($pageObject['props']['page']['title'])->toBe('Provider Demo Title')
		->and($pageObject['props']['auth']['user'])->toBeArray()
		->and($pageObject['props']['auth']['user'])->toHaveKeys(['id', 'name', 'email', 'roles', 'isLoggedin', 'isSuperuser'])
		->and($pageObject['props']['auth']['user'])->not->toHaveKey('pass');

	// 2. Custom TemplateDataProvider, FieldDataProvider, and UserDataProvider registration (even on a fresh unbooted instance)
	$inertia->resetState();

	$inertia->registerProvider(new class extends FieldDataProvider {
		public function supports(): array
		{
			return ['field:title'];
		}

		public function transform(mixed $value, ?Field $field = null, ?Page $page = null): mixed
		{
			return strtoupper((string) $value);
		}

		public function phpType(Field $field): string
		{
			return 'string';
		}

		public function tsType(Field $field): string
		{
			return 'Uppercase<string>';
		}
	});

	$inertia->registerProvider(new class extends TemplateDataProvider {
		public function templates(): array
		{
			return ['home'];
		}

		protected function extraData(Page $page): array
		{
			return [
				'readingTime' => 3,
			];
		}

		public function extraTsTypes(Template $template): array
		{
			return [
				'readingTime' => 'number',
			];
		}

		public function tsDefinitions(Template $template): array
		{
			return [
				'InertiaImage' => "export interface InertiaImage {\n\tname: string;\n\turl: string;\n\tsrcset?: string;\n}",
			];
		}
	});

	$inertia->registerProvider(new class extends UserDataProvider {
		protected function extraData(User $user): array
		{
			return [
				'displayLabel' => 'User:' . $user->name,
			];
		}

		public function extraTsTypes(): array
		{
			return [
				'displayLabel' => 'string',
			];
		}
	});

	$customObject = inertia('Home/Show', [
		'page' => $page,
		'user' => wire()->user,
	])->toPageObject();

	expect($customObject['props']['page']['title'])->toBe('PROVIDER DEMO TITLE')
		->and($customObject['props']['page']['readingTime'])->toBe(3)
		->and($customObject['props']['user']['displayLabel'])->toBe('User:' . wire()->user->name);

	$customTs = $inertia->typeGenerator()->buildTypeScriptDefinitions([$homeTemplate]);
	expect($customTs['content'])->toContain('title: Uppercase<string>;')
		->and($customTs['content'])->toContain('readingTime: number;')
		->and($customTs['content'])->toContain('srcset?: string;')
		->and($customTs['content'])->toContain('displayLabel: string;');

	// Verify standalone mediaTsDefinitions() respects custom FieldtypeImage provider even without template fields
	$inertia->registerProvider(new class extends FieldDataProvider {
		public function supports(): array
		{
			return ['FieldtypeImage'];
		}

		public function transform(mixed $value, ?Field $field = null, ?Page $page = null): mixed
		{
			return $value;
		}

		public function phpType(Field $field): string
		{
			return 'mixed';
		}

		public function tsType(Field $field): string
		{
			return 'InertiaImage';
		}

		public function tsDefinitions(?Field $field = null): array
		{
			return [
				'InertiaImage' => "export interface InertiaImage {\n\turl: string;\n\tlqip?: string;\n}",
			];
		}
	});

	$mediaDefs = $inertia->providers()->mediaTsDefinitions();
	expect($mediaDefs['InertiaImage'])->toContain('lqip?: string;');

	// 3. Auto-discovery of project providers from a directory (`discoverFromDirectory`)
	$tempDir = sys_get_temp_dir() . '/pw_inertia_providers_' . uniqid();
	@mkdir($tempDir, 0775, true);
	$providerFile = $tempDir . '/CustomUserDataProvider.php';
	file_put_contents($providerFile, <<<'PHP'
<?php
declare(strict_types=1);
namespace Totoglu\Inertia\Provider;
use ProcessWire\User;
class CustomUserDataProvider extends UserDataProvider {
	protected function extraData(User $user): array {
		return ['discoveredBadge' => 'Discovered: ' . $user->name];
	}
}
PHP);

	try {
		$inertia->providers()->discoverFromDirectory($tempDir);
		$userData = $inertia->providers()->provideUser(wire()->user);
		expect($userData['discoveredBadge'])->toBe('Discovered: ' . wire()->user->name);
	} finally {
		@unlink($providerFile);
		@rmdir($tempDir);
		$inertia->resetState();
	}
});

it('resolves RepeaterFieldDataProvider, ScalarFieldDataProvider Textformatters, and executes InertiaTypesCommand', function (): void {
	$inertia = inertia();
	$registry = $inertia->providers();

	// 1. RepeaterFieldDataProvider resolves FieldtypeRepeater and serializes inner item fields + matrixType + tsDefinitions()
	$repeaterField = new Field();
	$repeaterField->name = 'slides';
	if (wire()->modules->isInstalled('FieldtypeRepeater')) {
		$repeaterField->type = wire()->modules->get('FieldtypeRepeater');
		$repeaterProvider = $registry->resolveFieldProvider($repeaterField);
		expect($repeaterProvider)->toBeInstanceOf(RepeaterFieldDataProvider::class);
	}

	$repeaterInstance = new RepeaterFieldDataProvider();
	wire()->wire($repeaterInstance);
	$repeaterInstance->setRegistry($registry);

	$homeTemplate = wire()->templates->get('home');
	$repeaterField->set('template_id', (int) $homeTemplate->id);

	$slidePage = new Page($homeTemplate);
	$slidePage->id = 9001;
	$slidePage->name = 'slide-1';
	$slidePage->title = 'First Slide';
	$slidePage->set('repeater_matrix_type', 2);

	$serializedSlides = $repeaterInstance->transform([$slidePage], $repeaterField);
	expect($serializedSlides)->toBeArray()
		->and($serializedSlides[0]['id'])->toBe(9001)
		->and($serializedSlides[0]['title'])->toBe('First Slide')
		->and($serializedSlides[0]['matrixType'])->toBe(2);

	$repeaterTsDefs = $repeaterInstance->tsDefinitions($repeaterField);
	expect($repeaterInstance->tsType($repeaterField))->toBe('SlidesRepeaterItem[]')
		->and($repeaterTsDefs)->toHaveKey('SlidesRepeaterItem')
		->and($repeaterTsDefs['SlidesRepeaterItem'])->toContain('export interface SlidesRepeaterItem extends InertiaPageBase')
		->and($repeaterTsDefs['SlidesRepeaterItem'])->toContain('title: string;');

	// 2. ScalarFieldDataProvider applies configured ProcessWire Textformatter modules (and decodes TextformatterEntities for plain text props)
	$scalarProvider = new ScalarFieldDataProvider();
	wire()->wire($scalarProvider);

	$textField = new Field();
	$textField->name = 'sample_text';
	$textField->type = wire()->modules->get('FieldtypeText');
	$textField->set('textformatters', ['TextformatterEntities']);

	$formattedText = $scalarProvider->transform('ProcessWire &amp; Inertia', $textField, $slidePage);
	expect($formattedText)->toBe('ProcessWire & Inertia');

	// 3. InertiaTypesCommand CLI execution (--ts-only --output=... --json)
	$tempTsPath = sys_get_temp_dir() . '/pw_inertia_test_' . uniqid() . '.d.ts';
	try {
		$command = new InertiaTypesCommand();
		$tester = new CommandTester($command);
		$exitCode = $tester->execute([
			'--template' => ['home'],
			'--ts-only' => true,
			'--output' => $tempTsPath,
			'--json' => true,
		]);

		$jsonResult = json_decode($tester->getDisplay(), true);
		expect($exitCode)->toBe(0)
			->and($jsonResult['ok'])->toBeTrue()
			->and($jsonResult['data']['templates'])->toContain('home')
			->and(is_file($tempTsPath))->toBeTrue();
	} finally {
		if (is_file($tempTsPath)) {
			@unlink($tempTsPath);
		}
	}
});

it('inherits $page->template cache settings for OnceProp when until() is omitted and invalidates WireCache according to $template->cacheExpire rules', function (): void {
	$inertia = inertia();
	$inertia->resetState();
	$wire = wire();
	$cache = $wire->cache;
	$cache->deleteFor(PropResolver::ONCE_CACHE_NAMESPACE);

	$rootPage = $wire->pages->get('/');
	$originalPage = $wire->page;
	$template = $rootPage->template;
	$origCacheTime = (int) $template->cache_time;
	$origUseCacheForUsers = (int) $template->useCacheForUsers;
	$origCacheExpire = (int) $template->cacheExpire;

	try {
		$template->cache_time = 3600;
		$template->useCacheForUsers = 1;
		$template->cacheExpire = Template::cacheExpireParents;
		$wire->wire('page', $rootPage);

		$evaluations = 0;
		$buildResponse = static function () use ($inertia, &$evaluations): array {
			return inertia('Home/Show', [
				'showcaseRoot' => $inertia->once(static function () use (&$evaluations): array {
					$evaluations++;

					return ['evaluations' => $evaluations];
				})->as('test-root-once'),
			])->toPageObject();
		};

		// 1. First render without ->until() inherits $template->cache_time (3600s) and caches in WireCache
		$first = $buildResponse();
		expect($evaluations)->toBe(1)
			->and($first['props']['showcaseRoot'])->toBe(['evaluations' => 1])
			->and($first['onceProps']['test-root-once']['expiresAt'])->toBeInt()
			->and($first['onceProps']['test-root-once']['expiresAt'])->toBeGreaterThan(time() * 1000);

		// 2. Second render serves cached value from WireCache without re-invoking closure
		$second = $buildResponse();
		expect($evaluations)->toBe(1)
			->and($second['props']['showcaseRoot'])->toBe(['evaluations' => 1]);

		// 3. Client sends X-Inertia-Except-Once-Props while server cache is still valid -> prop is skipped
		$inertia->request->fake([
			'X-Inertia' => 'true',
			'X-Inertia-Except-Once-Props' => 'test-root-once',
		]);
		$skipped = $buildResponse();
		expect($skipped['props'])->not->toHaveKey('showcaseRoot')
			->and($evaluations)->toBe(1);

		// 4. Child page save with Template::cacheExpireParents invalidates parent rootPage's WireCache entry,
		// so subsequent visits re-evaluate the closure and update WireCache
		$inertia->request->resetFake();
		$childPage = $rootPage->children('limit=1')->first();
		if ($childPage instanceof Page && $childPage->id > 0 && $childPage->template !== null) {
			$origChildExpire = (int) $childPage->template->cacheExpire;
			$childPage->template->cacheExpire = Template::cacheExpireParents;
			$inertia->clearOnceCacheForPage($childPage, 'saved');
			$childPage->template->cacheExpire = $origChildExpire;
		} else {
			$inertia->clearOnceCacheForPage($rootPage, 'saved');
		}

		$refreshedAfterInvalidate = $buildResponse();
		expect($evaluations)->toBe(2)
			->and($refreshedAfterInvalidate['props']['showcaseRoot'])->toBe(['evaluations' => 2]);

		// 5. URL Segments and Page Numbers isolate OnceProp WireCache entries on the same Page
		$wire->input->setUrlSegment(1, 'archive');
		$wire->input->setPageNum(2);
		$segmentVisit = $buildResponse();
		expect($evaluations)->toBe(3)
			->and($segmentVisit['props']['showcaseRoot'])->toBe(['evaluations' => 3]);
		$wire->input->setUrlSegment(1, null);
		$wire->input->setPageNum(1);

		// 6. Native PageRender::isCacheAllowed() disables template cache TTL when noCacheGetVars matches $_GET
		$origNoCacheGetVars = (string) $template->noCacheGetVars;
		$template->noCacheGetVars = 'nocache_filter';
		$_GET['nocache_filter'] = '1';
		$noCacheVisit = $buildResponse();
		expect($noCacheVisit['onceProps']['test-root-once']['expiresAt'])->toBeNull();
		unset($_GET['nocache_filter']);
		$template->noCacheGetVars = $origNoCacheGetVars;
	} finally {
		$wire->input->setUrlSegment(1, null);
		$wire->input->setPageNum(1);
		$template->cache_time = $origCacheTime;
		$template->useCacheForUsers = $origUseCacheForUsers;
		$template->cacheExpire = $origCacheExpire;
		$wire->wire('page', $originalPage);
		$cache->deleteFor(PropResolver::ONCE_CACHE_NAMESPACE);
		$inertia->resetState();
	}
});

it('emits Inertia v3 top-level flash, sharedProps for Instant Visits, and 409 X-Inertia-Redirect headers', function (): void {
	$inertia = inertia();

	// 1. Clean response without notices or custom flash omits top-level flash while listing baseline sharedProps
	$inertia->share('auth.user', ['id' => 41, 'name' => 'admin']);
	$cleanObject = inertia('Dashboard/Home', ['localItem' => 123])->toPageObject();

	expect($cleanObject)->not->toHaveKey('flash')
		->and($cleanObject['sharedProps'])->toContain('errors', 'flash', 'csrf', 'auth')
		->and($cleanObject['sharedProps'])->not->toContain('localItem');

	// 2. Flashing data via $inertia->flash() persists in session and emits top-level pageObject['flash']
	$inertia->flash('toast', 'Profile updated')
		->flash(['banner' => 'Welcome back']);

	$flashedObject = inertia('Dashboard/Settings')
		->flash('step', 2)
		->toPageObject();

	expect($flashedObject['flash'])->toBe([
		'toast' => 'Profile updated',
		'banner' => 'Welcome back',
		'step' => 2,
	])
		->and($flashedObject['props']['flash'])->toMatchArray([
			'toast' => 'Profile updated',
			'banner' => 'Welcome back',
			'step' => 2,
		]);

	// Subsequent response after session flash consumption omits top-level flash again
	$subsequentObject = inertia('Dashboard/Settings')->toPageObject();
	expect($subsequentObject)->not->toHaveKey('flash');

	// 3. ProcessWire $notices / $session->message() are consumed once and cleared via $session->removeNotices()
	wire()->session->message('Saved via ProcessWire session');
	wire()->notices->add(new NoticeMessage('Saved via ProcessWire notices'));

	$withPwNotice = inertia('Dashboard/Settings')->toPageObject();
	expect($withPwNotice['props']['flash']['messages'])->toContain('Saved via ProcessWire notices')
		->and($withPwNotice['flash']['messages'])->toContain('Saved via ProcessWire notices');

	$afterPwNotice = inertia('Dashboard/Settings')->toPageObject();
	expect($afterPwNotice['props']['flash']['messages'])->toBe([])
		->and($afterPwNotice)->not->toHaveKey('flash')
		->and(wire()->session->getFor('_notices', 'messages'))->toBeNull();

	// 4. Internal SPA redirect via $inertia->redirect() emits 409 + X-Inertia-Redirect on XHR visits
	$inertia->request->fake(['X-Inertia' => 'true']);
	$xhrRedirect = $inertia->redirect('/dashboard/complete');
	expect($xhrRedirect->getStatusCode())->toBe(409)
		->and($xhrRedirect->getHeader('X-Inertia-Redirect'))->toBe('/dashboard/complete');

	// On standard non-Inertia visits, $inertia->redirect() prepares a 303 See Other redirect
	$inertia->resetState();
	$standardRedirect = $inertia->redirect('/dashboard/complete');
	expect($standardRedirect->getStatusCode())->toBe(303)
		->and($standardRedirect->getHeader('Location'))->toBe('/dashboard/complete');

	// 5. Background prefetch requests (Purpose: prefetch) do not consume session flash, errors, or clearHistory flags
	$inertia->resetState();
	$inertia->flash('toast', 'Survives prefetch');
	$inertia->withErrors(['email' => 'Invalid email']);
	$inertia->clearHistory(true);
	wire()->notices->add(new NoticeMessage('Notice survives prefetch'));

	$inertia->request->fake([
		'X-Inertia' => 'true',
		'Purpose' => 'prefetch',
	]);
	expect($inertia->request->isPrefetch())->toBeTrue()
		->and($inertia->request->headers()['purpose'])->toBe('prefetch');

	$prefetchedObject = inertia('Dashboard/Prefetched')->toPageObject();
	expect($prefetchedObject)->not->toHaveKey('flash')
		->and($prefetchedObject)->not->toHaveKey('clearHistory')
		->and((array) $prefetchedObject['props']['errors'])->toBe([]);

	// Actual navigation visit immediately after prefetch receives and consumes the queued session state
	$inertia->request->fake(['X-Inertia' => 'true']);
	expect($inertia->request->isPrefetch())->toBeFalse();

	$navigatedObject = inertia('Dashboard/Prefetched')->toPageObject();
	expect($navigatedObject['flash']['toast'])->toBe('Survives prefetch')
		->and($navigatedObject['flash']['messages'])->toContain('Notice survives prefetch')
		->and($navigatedObject['clearHistory'])->toBeTrue()
		->and($navigatedObject['props']['errors']['email'])->toBe('Invalid email');
});

it('guards against circular Page graphs, unpublished/trashed Page references, untrusted Referer Open Redirects, and non-HTTP SSR URLs', function (): void {
	$inertia = inertia();
	$home = wire()->pages->get('/');

	// 1. Circular Page reference protection in ProviderRegistry::providePage()
	$cyclicTemplateProvider = new class extends TemplateDataProvider {
		public function templates(): array
		{
			return [(string) wire()->pages->get('/')->template->name];
		}

		protected function extraData(Page $page): array
		{
			// Deliberately return the same Page inside extraData() to trigger a recursive cycle
			return [
				'selfCycle' => $this->wire()->inertia->providers()->providePage($page),
			];
		}
	};
	$inertia->registerProvider($cyclicTemplateProvider);

	$cyclicObject = inertia('Home/Cycle', ['page' => $home])->toPageObject();
	expect($cyclicObject['props']['page']['id'])->toBe((int) $home->id)
		->and($cyclicObject['props']['page']['selfCycle']['id'])->toBe((int) $home->id)
		->and($cyclicObject['props']['page']['selfCycle'])->not->toHaveKey('selfCycle');

	// 2. PageReferenceFieldDataProvider filters out unpublished and trashed Page references
	$pageRefProvider = new PageReferenceFieldDataProvider();
	wire()->wire($pageRefProvider);

	$publishedMock = new Page();
	wire()->wire($publishedMock);
	$publishedMock->id = 999001;
	$publishedMock->name = 'published-ref';
	$publishedMock->title = 'Published Ref';

	$unpublishedMock = new Page();
	wire()->wire($unpublishedMock);
	$unpublishedMock->id = 999002;
	$unpublishedMock->name = 'unpublished-ref';
	$unpublishedMock->addStatus(Page::statusUnpublished);

	$trashedMock = new Page();
	wire()->wire($trashedMock);
	$trashedMock->id = 999003;
	$trashedMock->name = 'trashed-ref';
	$trashedMock->addStatus(Page::statusTrash);

	$refArray = new PageArray();
	wire()->wire($refArray);
	$refArray->add($publishedMock);
	$refArray->add($unpublishedMock);
	$refArray->add($trashedMock);

	$transformedRefs = $pageRefProvider->transform($refArray);
	expect($transformedRefs)->toBeArray()
		->and(count($transformedRefs))->toBe(1)
		->and($transformedRefs[0]['id'])->toBe(999001)
		->and($pageRefProvider->transform($unpublishedMock))->toBeNull();

	// Direct PageArray and ScrollProp(PageArray) props also filter out unpublished and trashed pages
	$directCollectionObj = $inertia->render('Collection/Demo', [
		'items' => $refArray,
		'scrollItems' => $inertia->scroll($refArray),
	])->toPageObject();
	expect($directCollectionObj['props']['items'])->toHaveCount(1)
		->and($directCollectionObj['props']['items'][0]['id'])->toBe(999001)
		->and($directCollectionObj['props']['scrollItems']['data'])->toHaveCount(1)
		->and($directCollectionObj['props']['scrollItems']['data'][0]['id'])->toBe(999001);

	// 3. Open Redirect protection in $inertia->back() against untrusted external or protocol-relative Referer headers
	$inertia->request->fake(['Referer' => 'https://evil.example.com/phish']);
	$blockedExternal = $inertia->back('/safe-fallback');
	expect($blockedExternal->getHeader('Location'))->toBe('/safe-fallback');

	$inertia->request->fake(['Referer' => '//evil.example.com/phish']);
	$blockedProtocolRelative = $inertia->back('/safe-fallback');
	expect($blockedProtocolRelative->getHeader('Location'))->toBe('/safe-fallback');

	$inertia->request->fake(['Referer' => '/internal/dashboard?tab=profile']);
	$allowedInternal = $inertia->back('/safe-fallback');
	expect($allowedInternal->getHeader('Location'))->toBe('/internal/dashboard?tab=profile');

	// 4. Non-HTTP scheme rejection in HttpGateway::dispatch() and withoutSsr() URL query/trailing-slash matching
	$gateway = new HttpGateway('file:///etc/passwd', 1.0, true);
	wire()->wire($gateway);
	expect($gateway->dispatch(['component' => 'Test']))->toBeNull();

	$ssrCalls = 0;
	$inertia->setSsrGateway(new class ($ssrCalls) implements SsrGatewayInterface {
		public function __construct(private int &$calls)
		{
		}

		public function dispatch(array $pageObject): ?SsrResponse
		{
			$this->calls++;

			return new SsrResponse(['<title inertia>SSR</title>'], '<div id="app">SSR</div>');
		}
	});
	$inertia->withoutSsr(['/dashboard/*']);
	$skippedSsrResp = $inertia->render('Dashboard/Index')->url('/dashboard/?tab=settings');
	expect($skippedSsrResp->toOutput())->not->toContain('<title inertia>SSR</title>')
		->and($ssrCalls)->toBe(0);
	$inertia->resetState();

	// 5. RepeaterFieldDataProvider filters out hidden, unpublished, and trashed repeater items and enforces repeaterMaxItems
	$repeaterProvider = new RepeaterFieldDataProvider();
	wire()->wire($repeaterProvider);
	$repeaterProvider->setRegistry($inertia->providers());

	$repeaterField = new Field();
	$repeaterField->name = 'slides';
	$repeaterField->set('repeaterMaxItems', 1);

	$publishedItem = new Page();
	$publishedItem->id = 9101;
	$publishedItem->name = 'slide-1';
	$publishedItem->title = 'Slide 1';

	$secondPublishedItem = new Page();
	$secondPublishedItem->id = 9102;
	$secondPublishedItem->name = 'slide-2';
	$secondPublishedItem->title = 'Slide 2';

	$hiddenItem = new Page();
	$hiddenItem->id = 9103;
	$hiddenItem->name = 'slide-hidden';
	$hiddenItem->addStatus(Page::statusHidden);

	$unpublishedReadyItem = new Page();
	$unpublishedReadyItem->id = 9104;
	$unpublishedReadyItem->name = 'slide-ready';
	$unpublishedReadyItem->addStatus(Page::statusUnpublished);

	$repeaterTransformed = $repeaterProvider->transform([
		$hiddenItem,
		$unpublishedReadyItem,
		$publishedItem,
		$secondPublishedItem,
	], $repeaterField);

	expect($repeaterTransformed)->toHaveCount(1)
		->and($repeaterTransformed[0]['id'])->toBe(9101);

	// 6. ScrollProp memoizes per page number and re-evaluates when page number changes
	$scrollCalls = 0;
	$scrollProp = $inertia->scroll(function () use (&$scrollCalls): array {
		$scrollCalls++;

		return ['item-' . $scrollCalls];
	});

	$_GET['page'] = '1';
	$firstScrollObj = $inertia->render('Scroll/Demo', ['items' => $scrollProp])->toPageObject();
	expect($scrollCalls)->toBe(1)
		->and($firstScrollObj['scrollProps']['items']['currentPage'])->toBe(1)
		->and($firstScrollObj['props']['items']['data'])->toBe(['item-1']);

	$_GET['page'] = '2';
	$secondScrollObj = $inertia->render('Scroll/Demo', ['items' => $scrollProp])->toPageObject();
	expect($scrollCalls)->toBe(2)
		->and($secondScrollObj['scrollProps']['items']['currentPage'])->toBe(2)
		->and($secondScrollObj['props']['items']['data'])->toBe(['item-2']);
	unset($_GET['page']);

	// 7. TemplateDataProvider::basePageData() decodes TextformatterEntities on title when configured on the title field
	$titleField = wire()->fields->get('title');
	$prevTitleFormatters = $titleField !== null ? $titleField->get('textformatters') : null;
	if ($titleField !== null) {
		$titleField->set('textformatters', ['TextformatterEntities']);
	}
	try {
		$encodedRefPage = new Page();
		wire()->wire($encodedRefPage);
		$encodedRefPage->id = 9201;
		$encodedRefPage->name = 'rnd';
		$encodedRefPage->setQuietly('title', 'R&amp;D &mdash; Labs');
		$baseRefData = TemplateDataProvider::basePageData($encodedRefPage);
		expect($baseRefData['title'])->toBe('R&D — Labs');
	} finally {
		if ($titleField !== null) {
			$titleField->set('textformatters', is_array($prevTitleFormatters) ? $prevTitleFormatters : []);
		}
	}

	// 8. ProcessWire Demo Mode ($config->demo = true) blocks state-mutating requests, purges POST/JSON/FILES, and emits flash warning
	$config = wire()->config;
	$prevDemo = (bool) $config->demo;
	$config->demo = true;

	try {
		$_POST['title'] = 'Mutated via POST';
		wire()->input->post->set('title', 'Mutated via WireInput');

		$inertia->request->fake(
			['X-Inertia' => 'true', 'Content-Type' => 'application/json'],
			'POST',
			json_encode(['_method' => 'PATCH', 'title' => 'Mutated via JSON', 'secret' => '123']) ?: '{}'
		);
		$inertia->request->syncJsonToPost();

		expect($inertia->isDemoMode())->toBeTrue()
			->and($inertia->request->isDemoMode())->toBeTrue()
			->and($inertia->request->isDemoModeBlocked())->toBeTrue()
			->and($inertia->request->rawMethod())->toBe('PATCH')
			->and($inertia->request->method())->toBe('GET')
			->and($inertia->request->json())->toBe([])
			->and($inertia->request->input('title'))->toBeNull()
			->and($inertia->request->input('secret'))->toBeNull();

		$demoPageObject = $inertia->render('Demo/Form', [
			'effectiveMethod' => $inertia->request->method(),
		])->toPageObject();

		expect($_POST)->toBe([])
			->and(wire()->input->post->get('title'))->toBeNull()
			->and($demoPageObject['props']['effectiveMethod'])->toBe('GET')
			->and($demoPageObject['flash']['warnings'] ?? [])->toContain('Changes cannot be saved in demo mode.')
			->and($demoPageObject['props']['flash']['warnings'] ?? [])->toContain('Changes cannot be saved in demo mode.');
	} finally {
		$config->demo = $prevDemo;
		$_POST = [];
		wire()->input->post->removeAll();
		$inertia->resetState();
	}
});

it('hardens SSR hydration script injection, header name sanitization, nested ErrorBag merging, and TypeGenerator output paths', function (): void {
	$inertia = inertia();

	// 1. Custom SSR gateway returning <div id="app"> without <script type="application/json" data-page="app">
	$inertia->set('ssrEnabled', true);
	$inertia->setSsrGateway(new class implements SsrGatewayInterface {
		public function dispatch(array $page): ?SsrResponse
		{
			return new SsrResponse(
				head: ['<title>SSR Custom</title>'],
				body: '<div id="app" data-server-rendered="true"><h1>SSR Markup</h1></div>'
			);
		}
	});
	$inertia->render('Dashboard/Index', ['status' => 'ok']);
	$bodyHtml = $inertia->app();
	expect($bodyHtml)->toContain('<div id="app" data-server-rendered="true"><h1>SSR Markup</h1></div>')
		->and($bodyHtml)->toContain('<script type="application/json" data-page="app">');

	// 2. Response::setHeader() sanitizes CRLF and colons in header names and values
	$inertia->response->setHeader("X-Custom\r\n:Injected", "val\r\nue", false);
	$headers = $inertia->response->getHeaders();
	expect($headers)->toHaveKey('X-CustomInjected')
		->and($headers['X-CustomInjected'])->toBe('value');

	// 3. ErrorBag preserves sibling nested bag keys across multiple flash() calls and session storage
	$inertia->withErrors(['address' => ['city' => 'City is required']], 'shipping');
	$inertia->withErrors(['address' => ['zip' => 'ZIP is required']], 'shipping');
	$resolvedErrors = (array) $inertia->errors->resolve();
	expect($resolvedErrors['shipping']['address'] ?? [])->toBe([
		'city' => 'City is required',
		'zip' => 'ZIP is required',
	]);

	// 4. TypeGenerator rejects path traversal and non-.ts output paths
	$templatesPath = rtrim((string) wire()->config->paths->templates, '/\\');
	$defaultTsPath = $templatesPath . '/assets/js/types/inertia.d.ts';

	$traversalGen = $inertia->typeGenerator()->generate(
		templateNames: ['home'],
		force: false,
		generatePhp: false,
		generateTs: true,
		tsOutputPath: '../../evil.php'
	);
	expect($traversalGen['typescript']['path'] ?? null)->toBe($defaultTsPath);

	$phpExtGen = $inertia->typeGenerator()->generate(
		templateNames: ['home'],
		force: false,
		generatePhp: false,
		generateTs: true,
		tsOutputPath: 'assets/js/types/evil.php'
	);
	expect($phpExtGen['typescript']['path'] ?? null)->toBe($defaultTsPath);

	// 5. Standalone trashed/unpublished Page prop filtering & ProviderRegistry identifier safety
	$trashedPage = new Page();
	$trashedPage->id = 9501;
	$trashedPage->name = 'trashed-post';
	$trashedPage->addStatus(Page::statusTrash);

	$unpublishedPage = new Page();
	$unpublishedPage->id = 9502;
	$unpublishedPage->name = 'unpublished-post';
	$unpublishedPage->addStatus(Page::statusUnpublished);

	$pageObj = $inertia->render('Posts/Show', [
		'trashed' => $trashedPage,
		'unpublished' => $unpublishedPage,
	])->toPageObject();

	expect($pageObj['props']['trashed'])->toBeNull()
		->and($pageObj['props']['unpublished'])->toBeNull()
		->and(ProviderRegistry::studly('404-error'))->toBe('T404Error')
		->and(ProviderRegistry::formatTsKey("item's\\key"))->toBe("'item\\'s\\\\key'");

	// 6. Inertia::app() rejects event-handler and malformed attribute names & Request::validateCsrf() ignores $_GET tokens
	$inertia->withoutSsr();
	$csrHtml = $inertia->app($pageObj, null, [
		'class' => 'min-h-screen',
		'data-theme' => 'dark',
		'onclick' => 'alert(1)',
		'bad attr' => 'evil',
	]);
	expect($csrHtml)->toContain('class="min-h-screen"')
		->and($csrHtml)->toContain('data-theme="dark"')
		->and($csrHtml)->not->toContain('onclick=')
		->and($csrHtml)->not->toContain('bad attr');

	$expectedCsrf = (string) wire()->session->CSRF->getTokenValue();
	$_GET['_token'] = $expectedCsrf;
	$inertia->request->fake(['X-Inertia' => 'true'], 'POST', '');
	expect($inertia->request->validateCsrf(false))->toBeFalse();
	unset($_GET['_token']);

	// 7. Partial reload of a parent group does not trigger nested OptionalProp or bypass X-Inertia-Except-Once-Props,
	// and excluding an element from a list preserves sequential list indexing
	$inertia->request->fake([
		'X-Inertia' => 'true',
		'X-Inertia-Partial-Component' => 'Dashboard/Home',
		'X-Inertia-Partial-Data' => 'auth,list',
		'X-Inertia-Partial-Except' => 'list.0',
		'X-Inertia-Except-Once-Props' => 'roles',
	]);

	$partialParentObj = $inertia->render('Dashboard/Home', [
		'auth' => [
			'profile' => ['name' => 'Ada'],
			'auditLog' => $inertia->optional(fn () => ['events' => 42]),
			'roles' => $inertia->once(fn () => ['admin'])->as('roles'),
		],
		'list' => ['first', 'second', 'third'],
	])->toPageObject();

	expect($partialParentObj['props']['auth'])->toHaveKey('profile')
		->and($partialParentObj['props']['auth'])->not->toHaveKey('auditLog')
		->and($partialParentObj['props']['auth'])->not->toHaveKey('roles')
		->and($partialParentObj['props']['list'])->toBe(['second', 'third']);
});

it('preserves HTML on Notice::allowMarkup flash notices while escaping plain-text notices', function (): void {
	$inertia = inertia();
	$inertia->resetState();
	$notices = wire()->notices;
	if ($notices !== null) {
		$notices->removeAll();
	}

	wire()->warning(
		"Please check <u>nginx.txt</u><div><small class='ui-priority-secondary'>Required version: 301</small></div>",
		\ProcessWire\Notice::allowMarkup
	);
	wire()->error('Plain error with <script>alert(1)</script> tags');

	$pageObj = $inertia->render('Admin/Notices', [])->toPageObject();
	$warnings = $pageObj['flash']['warnings'] ?? [];
	$errors = $pageObj['flash']['errors'] ?? [];

	expect($warnings)->toContain("Please check <u>nginx.txt</u><div><small class='ui-priority-secondary'>Required version: 301</small></div>")
		->and($errors)->toContain('Plain error with &lt;script&gt;alert(1)&lt;/script&gt; tags');
});

it('supports ProcessInertia, MarkupInertia, InputfieldInertia dual-mode rendering, InertiaFieldtypeInterface, and core ProcessWire collections', function (): void {
	$inertia = inertia();
	$inertia->resetState();

	// 1. MarkupInertia dual-mode (as Inertia prop + as classic render() island)
	$markup = new class extends \Totoglu\Inertia\MarkupInertia {
		public function inertiaComponent(): string
		{
			return 'Custom/DataTable';
		}

		public function inertiaWithData(): array
		{
			return ['pageSize' => 25];
		}

		public function inertiaShared(): array
		{
			return ['tableLocale' => 'en-US'];
		}

		public function inertiaViewData(array $pageObject = []): array
		{
			return [
				'scripts' => ['/site/assets/table.js'],
				'styles' => ['/site/assets/table.css'],
			];
		}
	};
	wire()->wire($markup);
	$markup->with(['columns' => ['id', 'title']]);

	$classicMarkupHtml = $markup->render();
	expect($classicMarkupHtml)
		->toContain('class="pw-inertia-island"')
		->toContain('data-inertia-island="Custom/DataTable"')
		->toContain('&quot;pageSize&quot;:25')
		->toContain('&quot;columns&quot;:[&quot;id&quot;,&quot;title&quot;]');

	// 2. InputfieldInertia dual-mode (as Inertia prop + classic render() + processInput() with JSON depth guard)
	$inputfield = new class extends \Totoglu\Inertia\InputfieldInertia {
		public function inertiaComponent(): string
		{
			return 'Custom/KeyValue';
		}

		public function inertiaWithData(): array
		{
			return ['allowReorder' => true];
		}

		public function inertiaViewData(array $pageObject = []): array
		{
			return [
				'scripts' => ['/site/assets/keyvalue.js'],
			];
		}
	};
	wire()->wire($inputfield);
	$inputfield->attr('id', 'Inputfield_meta');
	$inputfield->attr('name', 'meta_pairs');
	$inputfield->label = 'Metadata Pairs';
	$inputfield->attr('value', ['env' => 'prod']);

	$classicInputHtml = $inputfield->render();
	expect($classicInputHtml)
		->toContain('type="hidden" id="Inputfield_meta" name="meta_pairs"')
		->toContain('class="pw-inertia-inputfield-island"')
		->toContain('data-inertia-island="Custom/KeyValue"')
		->toContain('data-input-id="Inputfield_meta"')
		->toContain('data-input-name="meta_pairs"');

	$rawPost = ['meta_pairs' => '{"env":"staging","region":"eu"}'];
	$postData = new \ProcessWire\WireInputData($rawPost);
	$inputfield->processInput($postData);
	expect($inputfield->attr('value'))->toBe(['env' => 'staging', 'region' => 'eu'])
		->and($inputfield->isChanged('value'))->toBeTrue();

	// Deeply nested JSON (> Request::MAX_JSON_DEPTH) is rejected by sanitizeInertiaInput() without crashing
	$deepJson = str_repeat('[', 70) . '1' . str_repeat(']', 70);
	$rawDeep = ['meta_pairs' => $deepJson];
	$deepPost = new \ProcessWire\WireInputData($rawDeep);
	$inputfield->processInput($deepPost);
	expect($inputfield->attr('value'))->toBe($deepJson);
	$inputfield->attr('value', ['env' => 'staging', 'region' => 'eu']);

	// 3. InputfieldWrapper, InputfieldPassword masking, WireArray, Role, Field, Template in PageResponse + viewData/shared merging
	$passField = wire()->modules->get('InputfieldPassword') ?: new \ProcessWire\Inputfield();
	wire()->wire($passField);
	$passField->attr('name', 'pass');
	$passField->attr('type', 'password');
	$passField->attr('value', 'super-secret-password');

	$wrapper = new \ProcessWire\InputfieldWrapper();
	wire()->wire($wrapper);
	$wrapper->attr('name', 'settings_form');
	$wrapper->add($inputfield);
	$wrapper->add($passField);

	$wireArray = new \ProcessWire\WireArray();
	$wireArray->add(new \ProcessWire\WireData());
	$wireArray->first()->set('code', 'A1');

	$role = wire()->roles->get('guest');
	$titleField = wire()->fields->get('title');
	$homeTemplate = wire()->templates->get('home');

	$response = $inertia->render('Admin/Composite', [
		'table' => $markup,
		'form' => $wrapper,
		'items' => $wireArray,
		'guestRole' => $role,
		'titleField' => $titleField,
		'homeTemplate' => $homeTemplate,
	])->withViewData(['scripts' => ['/site/assets/app.js']]);

	$pageObj = $response->toPageObject();

	expect($pageObj['props']['tableLocale'] ?? null)->toBe('en-US')
		->and($pageObj['sharedProps'] ?? [])->toContain('tableLocale')
		->and($pageObj['props']['table']['component'] ?? null)->toBe('Custom/DataTable')
		->and($pageObj['props']['table']['props']['pageSize'] ?? null)->toBe(25)
		->and($pageObj['props']['form']['component'] ?? null)->toBe('InputfieldWrapper')
		->and($pageObj['props']['form']['fields'][0]['component'] ?? null)->toBe('Custom/KeyValue')
		->and($pageObj['props']['form']['fields'][0]['value'] ?? null)->toBe(['env' => 'staging', 'region' => 'eu'])
		->and($pageObj['props']['form']['fields'][1]['name'] ?? null)->toBe('pass')
		->and($pageObj['props']['form']['fields'][1]['value'] ?? null)->toBe('')
		->and($pageObj['props']['items'])->toBe([['code' => 'A1']])
		->and($pageObj['props']['guestRole']['name'] ?? null)->toBe('guest')
		->and($pageObj['props']['titleField']['name'] ?? null)->toBe('title')
		->and($pageObj['props']['homeTemplate']['name'] ?? null)->toBe('home')
		->and($pageObj['props']['homeTemplate'])->toHaveKey('pageClass')
		->and($response->getViewData('scripts'))->toBe([
			'/site/assets/app.js',
			'/site/assets/table.js',
			'/site/assets/keyvalue.js',
		]);
});

it('validates LanguagePorter CSV files in languages/ across en, tr, de, es, fr, ru in chronological order', function (): void {
	$moduleRoot = dirname(__DIR__, 2);
	$languagesDir = $moduleRoot . '/languages';

	$defaultLang = wire('languages')->getDefault();
	$exportedCsv = $defaultLang->porter->exportCsv([
		'source' => 'site/modules/Inertia/',
		'scope' => 'all',
		'exportTo' => 'string',
	]);

	$fp = fopen('php://temp', 'r+');
	fwrite($fp, $exportedCsv);
	rewind($fp);
	$exportHeader = fgetcsv($fp, 0, ',', '"', '\\');
	$expectedRows = [];
	while (($row = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
		$expectedRows[] = [
			'original' => $row[0],
			'file' => $row[3],
			'hash' => $row[4],
		];
	}
	fclose($fp);

	expect($exportHeader)->toBeArray()
		->and($expectedRows)->toHaveCount(54);

	$expectedLocales = ['en', 'tr', 'de', 'es', 'fr', 'ru'];
	foreach ($expectedLocales as $locale) {
		$csvPath = $languagesDir . '/' . $locale . '.csv';
		expect(is_file($csvPath))->toBeTrue();

		$handle = fopen($csvPath, 'r');
		$header = fgetcsv($handle, 0, ',', '"', '\\');
		$expectedFirstCol = $locale === 'en' ? 'default' : 'en';
		expect($header)->toBe([$expectedFirstCol, $locale, 'description', 'file', 'hash']);

		$rows = [];
		while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
			expect($row[1])->not->toBe('');
			$rows[] = [
				'original' => $row[0],
				'file' => $row[3],
				'hash' => $row[4],
			];
		}
		fclose($handle);

		expect($rows)->toBe($expectedRows);
	}
});

it('enriches Pageimage serialization and TypeScript definitions with InterventionImage responsive, LQIP, color, and hash metadata', function (): void {
	expect(MediaFieldDataProvider::isInterventionImageInstalled())->toBeTrue();

	$tsWithIntervention = MediaFieldDataProvider::defaultTsDefinitions(true);
	$tsWithoutIntervention = MediaFieldDataProvider::defaultTsDefinitions(false);

	expect($tsWithIntervention)->toHaveKeys(['InertiaImageFocus', 'InertiaImageSource', 'InertiaImageColorTheme', 'InertiaImage', 'InertiaFile'])
		->and($tsWithIntervention['InertiaImage'])->toContain('focus: InertiaImageFocus;')
		->and($tsWithIntervention['InertiaImage'])->toContain('srcset: string;')
		->and($tsWithIntervention['InertiaImage'])->toContain('sources: InertiaImageSource[];')
		->and($tsWithIntervention['InertiaImage'])->toContain('lqip: string;')
		->and($tsWithIntervention['InertiaImage'])->toContain('dominantColor: string;')
		->and($tsWithIntervention['InertiaImage'])->toContain('palette: string[];')
		->and($tsWithIntervention['InertiaImage'])->toContain('colorTheme: InertiaImageColorTheme | null;')
		->and($tsWithIntervention['InertiaImage'])->toContain('imageHash: string;')
		->and($tsWithoutIntervention)->toHaveKeys(['InertiaImageFocus', 'InertiaImage', 'InertiaFile'])
		->and($tsWithoutIntervention)->not->toHaveKey('InertiaImageSource')
		->and($tsWithoutIntervention['InertiaImage'])->toContain('focus: InertiaImageFocus;')
		->and($tsWithoutIntervention['InertiaImage'])->not->toContain('srcset: string;');

	$homePage = wire('pages')->get(1);
	$pageimages = new Pageimages($homePage);
	$filesPath = $pageimages->path();
	if (!is_dir($filesPath)) {
		wire('files')->mkdir($filesPath, true);
	}

	$imgFilename = 'inertia-intervention-test-' . uniqid() . '.png';
	$fullPath = rtrim($filesPath, '/') . '/' . $imgFilename;

	$gd = imagecreatetruecolor(64, 48);
	$blue = imagecolorallocate($gd, 30, 64, 175);
	$cyan = imagecolorallocate($gd, 56, 189, 248);
	imagefilledrectangle($gd, 0, 0, 32, 48, $blue);
	imagefilledrectangle($gd, 32, 0, 64, 48, $cyan);
	imagepng($gd, $fullPath);
	unset($gd);

	try {
		$pageimage = new Pageimage($pageimages, $fullPath);
		$pageimage->focus(30, 70, 10);

		$serialized = MediaFieldDataProvider::formatPageimage($pageimage);

		expect($serialized['name'])->toBe($imgFilename)
			->and($serialized['width'])->toBe(64)
			->and($serialized['height'])->toBe(48)
			->and($serialized['focus'])->toBe([
				'top' => 30.0,
				'left' => 70.0,
				'zoom' => 10,
			])
			->and($serialized['srcset'])->toBeString()->not->toBe('')
			->and($serialized['sizes'])->toBeString()->not->toBe('')
			->and($serialized['sources'])->toBeArray()
			->and($serialized['lqip'])->toStartWith('data:image/webp;base64,')
			->and($serialized['dominantColor'])->toMatch('/^#[0-9a-fA-F]{6}$/')
			->and($serialized['palette'])->toBeArray()->not->toBeEmpty()
			->and($serialized['colorTheme'])->toBeArray()->toHaveKeys([
				'vibrant',
				'muted',
				'darkVibrant',
				'darkMuted',
				'lightVibrant',
				'lightMuted',
			])
			->and($serialized['imageHash'])->toMatch('/^[0-9a-f]{16}$/');
	} finally {
		if (isset($pageimage) && $pageimage instanceof Pageimage) {
			$pageimage->removeVariations();
		}
		$baseName = pathinfo($imgFilename, PATHINFO_FILENAME);
		foreach (glob(rtrim($filesPath, '/') . '/' . $baseName . '*') ?: [] as $cleanupFile) {
			@unlink($cleanupFile);
		}
	}
});

