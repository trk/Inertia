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

	// 1. $inertia->share() and $inertia->viewData() (including lazy Closures) work globally
	$inertia->share('locale', 'tr')
		->viewData('locale', 'tr')
		->viewData('head', static fn(array $pageObj): string => '<title inertia>' . (string) ($pageObj['props']['title'] ?? '') . ' &mdash; ProcessWire</title>');

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
	$customPage = new class (wire()->templates->get('page')) extends Page {
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
	])->withViewData('inlineViewVar', 'fromResponse');
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
	wire()->input->get->set('page', '2');
	$inertia->request->fake([
		'X-Inertia' => 'true',
		'X-Inertia-Infinite-Scroll-Merge-Intent' => 'prepend',
	]);

	$prependScrollObject = inertia('Posts/Feed', [
		'posts' => $inertia->scroll(static fn() => wire()->pages->find('id>0, sort=id, limit=1')),
	])->toPageObject();
	wire()->input->get->remove('page');

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
			$inertia->resolver->clearOnceCacheForPage($childPage, 'saved');
			$childPage->template->cacheExpire = $origChildExpire;
		} else {
			$inertia->resolver->clearOnceCacheForPage($rootPage, 'saved');
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

	// 1. Clean response without notices or custom flash emits empty top-level flash object while listing baseline sharedProps
	$inertia->share('auth.user', ['id' => 41, 'name' => 'admin']);
	$cleanObject = inertia('Dashboard/Home', ['localItem' => 123])->toPageObject();

	expect($cleanObject)->toHaveKey('flash')
		->and($cleanObject['flash'])->toBeInstanceOf(\stdClass::class)
		->and((array) $cleanObject['flash'])->toBe([])
		->and($cleanObject['sharedProps'])->toContain('errors', 'flash', 'csrf', 'auth')
		->and($cleanObject['sharedProps'])->not->toContain('localItem');

	// 2. Flashing data via $inertia->flash() persists in session and emits top-level pageObject['flash']
	$inertia->flash('toast', 'Profile updated')
		->flash(['banner' => 'Welcome back']);

	$flashedObject = inertia('Dashboard/Settings')
		->flash('step', 2)
		->toPageObject();

	expect((array) $flashedObject['flash'])->toBe([
		'toast' => 'Profile updated',
		'banner' => 'Welcome back',
		'step' => 2,
	])
		->and($flashedObject['props']['flash'])->toMatchArray([
			'toast' => 'Profile updated',
			'banner' => 'Welcome back',
			'step' => 2,
		]);

	// Subsequent response after session flash consumption emits an empty top-level flash object again
	$subsequentObject = inertia('Dashboard/Settings')->toPageObject();
	expect((array) $subsequentObject['flash'])->toBe([]);

	// 3. ProcessWire $notices / $session->message() are consumed once and cleared via $session->removeNotices()
	wire()->session->message('Saved via ProcessWire session');
	wire()->notices->add(new NoticeMessage('Saved via ProcessWire notices'));

	$withPwNotice = inertia('Dashboard/Settings')->toPageObject();
	$withPwFlash = (array) $withPwNotice['flash'];
	expect($withPwNotice['props']['flash']['messages'])->toContain('Saved via ProcessWire notices')
		->and($withPwFlash['messages'])->toContain('Saved via ProcessWire notices');

	$afterPwNotice = inertia('Dashboard/Settings')->toPageObject();
	expect($afterPwNotice['props']['flash']['messages'])->toBe([])
		->and((array) $afterPwNotice['flash'])->toBe([])
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
	expect((array) $prefetchedObject['flash'])->toBe([])
		->and($prefetchedObject)->not->toHaveKey('clearHistory')
		->and((array) $prefetchedObject['props']['errors'])->toBe([]);

	// Actual navigation visit immediately after prefetch receives and consumes the queued session state
	$inertia->request->fake(['X-Inertia' => 'true']);
	expect($inertia->request->isPrefetch())->toBeFalse();

	$navigatedObject = inertia('Dashboard/Prefetched')->toPageObject();
	$navigatedFlash = (array) $navigatedObject['flash'];
	expect($navigatedFlash['toast'])->toBe('Survives prefetch')
		->and($navigatedFlash['messages'])->toContain('Notice survives prefetch')
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

	wire()->input->get->set('page', '1');
	$firstScrollObj = $inertia->render('Scroll/Demo', ['items' => $scrollProp])->toPageObject();
	expect($scrollCalls)->toBe(1)
		->and($firstScrollObj['scrollProps']['items']['currentPage'])->toBe(1)
		->and($firstScrollObj['props']['items']['data'])->toBe(['item-1']);

	wire()->input->get->set('page', '2');
	$secondScrollObj = $inertia->render('Scroll/Demo', ['items' => $scrollProp])->toPageObject();
	expect($scrollCalls)->toBe(2)
		->and($secondScrollObj['scrollProps']['items']['currentPage'])->toBe(2)
		->and($secondScrollObj['props']['items']['data'])->toBe(['item-2']);
	wire()->input->get->remove('page');

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
		wire()->input->post->set('title', 'Mutated via WireInput');

		$inertia->request->fake(
			['X-Inertia' => 'true', 'Content-Type' => 'application/json'],
			'POST',
			json_encode(['_method' => 'PATCH', 'title' => 'Mutated via JSON', 'secret' => '123']) ?: '{}'
		);
		$inertia->request->syncJsonToPost();

		expect($inertia->request->isDemoMode())->toBeTrue()
			->and($inertia->request->isDemoModeBlocked())->toBeTrue()
			->and($inertia->request->rawMethod())->toBe('PATCH')
			->and($inertia->request->method())->toBe('GET')
			->and($inertia->request->json())->toBe([])
			->and($inertia->request->input('title'))->toBeNull()
			->and($inertia->request->input('secret'))->toBeNull();

		$demoPageObject = $inertia->render('Demo/Form', [
			'effectiveMethod' => $inertia->request->method(),
		])->toPageObject();

		$demoFlash = (array) $demoPageObject['flash'];
		expect(wire()->input->post->getArray())->toBe([])
			->and(wire()->input->post->get('title'))->toBeNull()
			->and($demoPageObject['props']['effectiveMethod'])->toBe('GET')
			->and($demoFlash['warnings'] ?? [])->toContain('Changes cannot be saved in demo mode.')
			->and($demoPageObject['props']['flash']['warnings'] ?? [])->toContain('Changes cannot be saved in demo mode.');
	} finally {
		$config->demo = $prevDemo;
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

	$csrfName = (string) wire()->session->CSRF->getTokenName();
	$expectedCsrf = (string) wire()->session->CSRF->getTokenValue();
	$prevProtectCsrf = (bool) wire()->config->protectCSRF;
	wire()->config->protectCSRF = true;
	try {
		wire()->input->post->removeAll();
		wire()->input->get->set('_token', $expectedCsrf);
		wire()->input->get->set('filter_tab', '<b>active</b>');
		$inertia->request->fake(['X-Inertia' => 'true'], 'POST', '');
		expect($inertia->request->validateCsrf(false))->toBeFalse()
			->and($inertia->request->get('filter_tab', 'text'))->toBe('active')
			->and(wire()->session->CSRF->hasValidToken('', false))->toBeFalse();
		wire()->input->get->remove('_token');
		wire()->input->get->remove('filter_tab');

		// Re-read token after failed validation rotated it
		$csrfName = (string) wire()->session->CSRF->getTokenName();
		$expectedCsrf = (string) wire()->session->CSRF->getTokenValue();

		// End-to-end public seam: Inertia::ready() bridges verified X-XSRF-TOKEN (or _token when header is stale)
		// into native $input->post so $session->CSRF->validate() succeeds automatically
		$inertia->request->fake(
			['X-Inertia' => 'true', 'X-XSRF-TOKEN' => 'stale-token-value'],
			'POST',
			json_encode([
				'_token' => $expectedCsrf,
				'action' => 'refresh',
				'title' => '<b>Clean Title</b>',
				'nested' => [
					'email' => '  USER@EXAMPLE.COM ',
					'toggle' => '1',
					'tags' => ['<b>first</b>', 'second'],
					'__proto__' => ['polluted' => true],
				],
				'GLOBALS' => 'blocked',
				'__proto__' => 'blocked',
				"bad\nkey" => 'blocked',
			]) ?: '{}'
		);
		$inertia->ready();
		expect($inertia->request->isMethod('post'))->toBeTrue()
			->and($inertia->request->post('action', ['refresh', 'save']))->toBe('refresh')
			->and($inertia->request->post('title', 'text'))->toBe('Clean Title')
			->and($inertia->request->input('nested.email', null, 'email'))->toBe('USER@EXAMPLE.COM')
			->and($inertia->request->input('nested.toggle', 0, 1))->toBe(1)
			->and($inertia->request->input('nested.tags', null, 'text'))->toBe('first')
			->and($inertia->request->input('GLOBALS'))->toBeNull()
			->and($inertia->request->input('__proto__'))->toBeNull()
			->and($inertia->request->input('nested.__proto__'))->toBeNull()
			->and(wire()->input->post->get('GLOBALS'))->toBeNull()
			->and(wire()->input->post->get('__proto__'))->toBeNull()
			->and(wire()->input->post->get("bad\nkey"))->toBeNull()
			->and(wire()->input->post->get($csrfName))->toBe($expectedCsrf)
			->and(wire()->session->CSRF->hasValidToken('', false))->toBeTrue()
			->and(wire()->session->CSRF->validate())->toBeTrue();
	} finally {
		wire()->config->protectCSRF = $prevProtectCsrf;
		wire()->input->post->removeAll();
	}

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
	$topFlash = (array) $pageObj['flash'];
	$warnings = $topFlash['warnings'] ?? [];
	$errors = $topFlash['errors'] ?? [];

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
		->and($expectedRows)->toHaveCount(56);

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

it('serializes core Pageimage metadata and focus coordinates without coupling to InterventionImage', function (): void {
	$tsDefaults = MediaFieldDataProvider::defaultTsDefinitions();

	expect($tsDefaults)->toHaveKeys(['InertiaImageFocus', 'InertiaImage', 'InertiaFile'])
		->and($tsDefaults)->not->toHaveKey('InertiaImageSource')
		->and($tsDefaults)->not->toHaveKey('InertiaImageColorTheme')
		->and($tsDefaults['InertiaImage'])->toContain('focus: InertiaImageFocus;')
		->and($tsDefaults['InertiaImage'])->not->toContain('srcset: string;');

	$homePage = wire('pages')->get(1);
	$pageimages = new Pageimages($homePage);
	$filesPath = $pageimages->path();
	if (!is_dir($filesPath)) {
		wire('files')->mkdir($filesPath, true);
	}

	$imgFilename = 'inertia-media-test-' . uniqid() . '.png';
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

		expect($serialized)->toHaveKeys(['name', 'url', 'httpUrl', 'description', 'ext', 'filesize', 'width', 'height', 'focus'])
			->and($serialized)->not->toHaveKey('srcset')
			->and($serialized)->not->toHaveKey('lqip')
			->and($serialized['name'])->toBe($imgFilename)
			->and($serialized['width'])->toBe(64)
			->and($serialized['height'])->toBe(48)
			->and($serialized['focus'])->toBe([
				'top' => 30.0,
				'left' => 70.0,
				'zoom' => 10,
			]);
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

it('sanitizes dangerous navigation URI schemes, enforces Page::viewable(false) RBAC on serialized pages, and blocks demo-mode mutations in InputfieldInertia and CLI commands', function (): void {
	$inertia = inertia();
	$inertia->resetState();

	// 1. Dangerous navigation URI schemes (javascript:, data:, vbscript:) are neutralized to '/'
	expect($inertia->response->sanitizeNavigationUrl('javascript:alert(1)'))->toBe('/')
		->and($inertia->response->sanitizeNavigationUrl("  \tJaVaScRiPt:alert(1)"))->toBe('/')
		->and($inertia->response->sanitizeNavigationUrl('data:text/html,<script>alert(1)</script>'))->toBe('/')
		->and($inertia->response->sanitizeNavigationUrl('vbscript:msgbox(1)'))->toBe('/')
		->and($inertia->response->sanitizeNavigationUrl('/dashboard?tab=1'))->toBe('/dashboard?tab=1');

	$inertia->request->fake(['X-Inertia' => 'true']);
	$inertia->location('javascript:alert(1)');
	expect($inertia->response->getStatusCode())->toBe(409)
		->and($inertia->response->getHeader('X-Inertia-Location'))->toBe('/');

	$inertia->response->reset();
	$inertia->redirect('data:text/html,<h1>x</h1>');
	expect($inertia->response->getStatusCode())->toBe(409)
		->and($inertia->response->getHeader('X-Inertia-Redirect'))->toBe('/');

	// 2. TemplateDataProvider::isAccessiblePage() enforces Page::viewable(false) for unauthorized users
	$wire = wire();
	$originalUser = $wire->user;
	$guestUser = $wire->users->getGuestUser();
	$adminPage = $wire->pages->get(2); // Admin root page (/processwire/)

	try {
		$wire->users->setCurrentUser($guestUser);
		expect($adminPage->id)->toBe(2)
			->and(TemplateDataProvider::isAccessiblePage($adminPage))->toBeFalse();

		$pageRefProvider = new PageReferenceFieldDataProvider();
		$wire->wire($pageRefProvider);
		$homePage = $wire->pages->get(1);
		$refResult = $pageRefProvider->transform([$homePage, $adminPage]);
		expect($refResult)->toBeArray()
			->and($refResult)->toHaveCount(1)
			->and($refResult[0]['id'])->toBe(1);
	} finally {
		if ($originalUser instanceof User) {
			$wire->users->setCurrentUser($originalUser);
		}
	}

	// 3. InputfieldInertia strips blocked JSON keys and ignores processInput() when $config->demo is enabled
	$inputfield = new class extends \Totoglu\Inertia\InputfieldInertia {
	};
	$wire->wire($inputfield);
	$inputfield->attr('name', 'widget_state');
	$inputfield->setAttribute('value', ['safe' => 'initial']);

	$rawPost = [
		'widget_state' => '{"safe":"updated","__proto__":{"polluted":true},"_SERVER":"leak"}',
	];
	$inputData = new \ProcessWire\WireInputData($rawPost);
	$inputfield->processInput($inputData);
	expect($inputfield->attr('value'))->toBe(['safe' => 'updated']);

	$config = $wire->config;
	$prevDemo = $config->demo ?? false;
	try {
		$config->demo = true;
		$rawDemoPost = [
			'widget_state' => '{"safe":"mutated_in_demo"}',
		];
		$demoInputData = new \ProcessWire\WireInputData($rawDemoPost);
		$inputfield->processInput($demoInputData);
		expect($inputfield->attr('value'))->toBe(['safe' => 'updated']);

		// 4. InertiaTypesCommand and InertiaInstallCommand reject writes in demo mode
		$typesTester = new CommandTester(new InertiaTypesCommand());
		$typesExit = $typesTester->execute(['--ts-only' => true, '--json' => true]);
		$typesJson = json_decode($typesTester->getDisplay(), true);
		expect($typesExit)->toBe(1)
			->and($typesJson['ok'] ?? null)->toBeFalse()
			->and($typesJson['error']['code'] ?? null)->toBe('DEMO_MODE');

		$installTester = new CommandTester(new \Totoglu\Inertia\Commands\InertiaInstallCommand());
		$installExit = $installTester->execute(['--json' => true]);
		$installJson = json_decode($installTester->getDisplay(), true);
		expect($installExit)->toBe(1)
			->and($installJson['ok'] ?? null)->toBeFalse()
			->and($installJson['error']['code'] ?? null)->toBe('DEMO_MODE');
	} finally {
		$config->demo = $prevDemo;
	}

	// 5. HttpGateway rejects SSR URLs containing embedded user:pass@ credentials or control characters
	$gateway = new \Totoglu\Inertia\Ssr\HttpGateway('http://user:secret@127.0.0.1:13714/render', 0.2, true);
	$wire->wire($gateway);
	expect($gateway->dispatch(['component' => 'Home', 'props' => [], 'url' => '/', 'version' => '1']))->toBeNull();

	$gatewayCtl = new \Totoglu\Inertia\Ssr\HttpGateway("http://127.0.0.1:13714/render\r\nX-Injected: 1", 0.2, true);
	$wire->wire($gatewayCtl);
	expect($gatewayCtl->dispatch(['component' => 'Home', 'props' => [], 'url' => '/', 'version' => '1']))->toBeNull();

	// 6. Request::header() prioritizes CGI CONTENT_TYPE over spoofed HTTP_CONTENT_TYPE, and validateCsrf(true) fails closed
	$req = new \Totoglu\Inertia\Request();
	$wire->wire($req);
	$prevContentType = $_SERVER['CONTENT_TYPE'] ?? null;
	$prevHttpContentType = $_SERVER['HTTP_CONTENT_TYPE'] ?? null;
	try {
		$_SERVER['CONTENT_TYPE'] = 'multipart/form-data';
		$_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';
		expect($req->header('Content-Type'))->toBe('multipart/form-data')
			->and($req->isJson())->toBeFalse();
	} finally {
		if ($prevContentType === null) {
			unset($_SERVER['CONTENT_TYPE']);
		} else {
			$_SERVER['CONTENT_TYPE'] = $prevContentType;
		}
		if ($prevHttpContentType === null) {
			unset($_SERVER['HTTP_CONTENT_TYPE']);
		} else {
			$_SERVER['HTTP_CONTENT_TYPE'] = $prevHttpContentType;
		}
	}

	$sessionlessReq = new class extends \Totoglu\Inertia\Request {
		public function wire($name = '', $value = null, $lock = false)
		{
			if ($name === '') {
				return (object) [
					'config' => (object) ['protectCSRF' => true, 'demo' => false],
					'session' => null,
					'input' => null,
				];
			}

			return parent::wire($name, $value, $lock);
		}

		public function _($text): string
		{
			return (string) $text;
		}
	};
	expect(fn () => $sessionlessReq->validateCsrf(true))->toThrow(\ProcessWire\WirePermissionException::class)
		->and($sessionlessReq->validateCsrf(false))->toBeFalse();

	$island = new class extends \Totoglu\Inertia\MarkupInertia {
		public function inertiaViewData(array $pageObject = []): array
		{
			return [
				'scripts' => ['javascript:alert(1)', 'data:text/javascript,evil()', '/site/templates/scripts/safe-island.js'],
				'styles' => ['vbscript:evil()', '/site/templates/styles/safe-island.css'],
			];
		}
	};
	wire()->wire($island);
	$island->renderReady();
	$scripts = array_values(iterator_to_array(wire()->config->scripts));
	$styles = array_values(iterator_to_array(wire()->config->styles));
	expect($scripts)->toContain('/site/templates/scripts/safe-island.js')
		->and($scripts)->not->toContain('javascript:alert(1)')
		->and($scripts)->not->toContain('data:text/javascript,evil()')
		->and($styles)->toContain('/site/templates/styles/safe-island.css')
		->and($styles)->not->toContain('vbscript:evil()');
});

it('resolves native SAPI PATCH requests via rawMethod(), enforces demo mode on real PATCH verbs, blocks WHATWG Referer open-redirect differentials, and isolates non-Inertia JSON during init()', function (): void {
	$inertia = inertia();
	$wire = wire();
	$config = $wire->config;
	$input = $wire->input;

	$prevDemo = (bool) $config->demo;
	$prevMethod = $_SERVER['REQUEST_METHOD'] ?? null;
	$prevHttpHost = $config->httpHost;
	$prevHttpHosts = $config->httpHosts;

	try {
		// 1. Native SAPI PATCH request (without fake() methodOverride, where WireInput::requestMethod() returns '')
		$inertia->resetState();
		$input->post->removeAll();
		$_SERVER['REQUEST_METHOD'] = 'PATCH';

		expect($inertia->request->rawMethod())->toBe('PATCH')
			->and($inertia->request->method())->toBe('PATCH')
			->and($inertia->request->isMutatingMethod())->toBeTrue();

		// 2. Demo mode blocks native SAPI PATCH and preserves preDemoMethod after purgeMutatingGlobals()
		$config->demo = true;
		$input->post->set('bio', 'Should be purged on native PATCH');
		expect($inertia->request->isDemoModeBlocked())->toBeTrue()
			->and($inertia->enforceDemoModeGuard())->toBeTrue()
			->and($inertia->request->rawMethod())->toBe('PATCH')
			->and($inertia->request->method())->toBe('GET')
			->and($input->post('bio'))->toBeNull();

		$config->demo = false;

		// 3. WHATWG vs PHP parse_url() Referer open-redirect differentials (backslash & userinfo) are rejected
		$config->httpHost = 'trusted.example.com';
		$config->httpHosts = ['trusted.example.com'];

		$probeBackTarget = static function (string $referer) use ($inertia): ?string {
			$inertia->request->fake(['Referer' => $referer], 'POST');

			return $inertia->back('/fallback')->getHeader('Location');
		};

		expect($probeBackTarget('https://trusted.example.com/dashboard'))->toBe('https://trusted.example.com/dashboard')
			->and($probeBackTarget('/account/settings'))->toBe('/account/settings')
			->and($probeBackTarget('https://evil.example\@trusted.example.com/pwn'))->toBe('/fallback')
			->and($probeBackTarget('https://evil.example@trusted.example.com/pwn'))->toBe('/fallback')
			->and($probeBackTarget('https://user:pass@trusted.example.com/pwn'))->toBe('/fallback')
			->and($probeBackTarget('http://localhost/admin'))->toBe('/fallback')
			->and($probeBackTarget('/\\evil.example/pwn'))->toBe('/fallback');

		// 4. Non-Inertia JSON POST during init() does not pollute $input->post globally
		$inertia->resetState();
		$input->post->removeAll();
		$inertia->request->fake(
			['Content-Type' => 'application/json'],
			'POST',
			(string) json_encode(['webhookSecret' => 'do-not-pollute-global-post'])
		);
		$inertia->init();
		expect($input->post('webhookSecret'))->toBeNull();

		// Whereas an actual X-Inertia: true JSON POST during init() syncs into $input->post
		$inertia->request->fake(
			['X-Inertia' => 'true', 'Content-Type' => 'application/json'],
			'POST',
			(string) json_encode(['inertiaField' => 'synced-cleanly'])
		);
		$inertia->init();
		expect($input->post('inertiaField'))->toBe('synced-cleanly');
	} finally {
		$config->demo = $prevDemo;
		$config->httpHost = $prevHttpHost;
		$config->httpHosts = $prevHttpHosts;
		$input->post->removeAll();
		$inertia->resetState();
		if ($prevMethod === null) {
			unset($_SERVER['REQUEST_METHOD']);
		} else {
			$_SERVER['REQUEST_METHOD'] = $prevMethod;
		}
	}
});

it('purges flashed data and errors on Session::logout, hardens ErrorBag against prototype-pollution keys, and syncs non-POST URL-encoded bodies + X-{tokenName} CSRF headers', function (): void {
	$inertia = inertia();
	$wire = wire();
	$config = $wire->config;
	$input = $wire->input;
	$session = $wire->session;
	$csrf = $session->CSRF;

	$prevDemo = (bool) $config->demo;

	try {
		// 1. Session::logout hook purges pre-logout flashed data, validation errors, and preserveFragment while setting clearHistory
		$inertia->resetState();
		$inertia->flash('secretToken', 'pre-logout-secret');
		$inertia->withErrors(['email' => 'Pre-logout validation error']);
		$inertia->preserveFragment(true);

		$logoutHook = new ReflectionMethod($inertia, 'hookSessionLogout');
		$logoutHook->invoke($inertia, new \ProcessWire\HookEvent());

		$postLogoutPage = $inertia->render('Auth/Login', [])->toPageObject();
		$postLogoutFlash = (array) $postLogoutPage['flash'];
		expect($postLogoutPage['clearHistory'] ?? false)->toBeTrue()
			->and($postLogoutPage['preserveFragment'] ?? null)->toBeNull()
			->and($postLogoutFlash['secretToken'] ?? null)->toBeNull()
			->and((array) ($postLogoutPage['props']['errors'] ?? []))->toBe([]);

		// 2. ErrorBag rejects __proto__, constructor, control chars, and oversized X-Inertia-Error-Bag headers while allowing valid bag names
		$inertia->resetState();
		$inertia->withErrors([
			'email' => 'Invalid email',
			'__proto__' => 'polluted',
			'_POST' => 'polluted-superglobal',
			"bad\x7Fkey" => 'del-char',
		]);
		$resolvedProtoBag = $inertia->errors->resolve('__proto__');
		expect($resolvedProtoBag)->toBeArray()
			->and($resolvedProtoBag)->toHaveKey('email')
			->and($resolvedProtoBag)->not->toHaveKey('__proto__')
			->and($resolvedProtoBag)->not->toHaveKey('_POST')
			->and($resolvedProtoBag)->not->toHaveKey("bad\x7Fkey");

		$inertia->withErrors(['password' => 'Too short']);
		$resolvedOversizedBag = $inertia->errors->resolve(str_repeat('a', 80));
		expect($resolvedOversizedBag)->toBe(['password' => 'Too short']);

		$inertia->withErrors(['password' => 'Too short']);
		$resolvedValidBag = $inertia->errors->resolve('loginForm');
		expect($resolvedValidBag)->toBe(['loginForm' => ['password' => 'Too short']]);

		// 3. Non-POST (PUT / PATCH) application/x-www-form-urlencoded body syncs to $input->post and supports X-{tokenName} CSRF header
		$config->demo = false;
		$inertia->resetState();
		$input->post->removeAll();

		$tokenName = (string) $csrf->getTokenName();
		$expectedToken = (string) $csrf->getTokenValue();

		$inertia->request->fake(
			[
				'X-Inertia' => 'true',
				'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
				'X-' . $tokenName => $expectedToken,
			],
			'PUT',
			"headline=Updated+Via+Put&__proto__=ignored&bad%7Fkey=ignored"
		);

		expect($inertia->request->validateCsrf(true))->toBeTrue()
			->and($inertia->request->post('headline'))->toBe('Updated Via Put')
			->and($inertia->request->input('headline'))->toBe('Updated Via Put')
			->and($inertia->request->input('__proto__'))->toBeNull()
			->and($inertia->request->input("bad\x7Fkey"))->toBeNull()
			->and($input->post('headline'))->toBe('Updated Via Put');

		// Demo mode blocks non-POST URL-encoded PUT body completely
		$config->demo = true;
		$input->post->removeAll();
		$inertia->request->fake(
			[
				'X-Inertia' => 'true',
				'Content-Type' => 'application/x-www-form-urlencoded',
			],
			'PUT',
			'headline=Blocked+In+Demo'
		);
		expect($inertia->request->isDemoModeBlocked())->toBeTrue()
			->and($inertia->request->post('headline'))->toBeNull()
			->and($inertia->request->input('headline'))->toBeNull()
			->and($input->post('headline'))->toBeNull();
	} finally {
		$config->demo = $prevDemo;
		$input->post->removeAll();
		$inertia->resetState();
	}
});


it('hardens string sanitizer callables, auth OnceProp clearHistory transitions, third-party User PII, back() host/port/subdir checks, SSRF metadata IPs, and URL-hook demo mode', function (): void {
	$inertia = inertia();
	$config = wire()->config;
	$input = wire()->input;

	$prevDemo = (bool) $config->demo;
	$prevHttpHost = $config->httpHost;
	$prevHttpHosts = $config->httpHosts;
	$prevRootUrl = $config->urls->root;

	try {
		$config->demo = false;
		$inertia->resetState();

		// 1. applyInputSanitizer must never invoke global PHP string functions (e.g. 'strtoupper') and must short-circuit null in CSV chains
		$inertia->request->fake(
			['X-Inertia' => 'true', 'Content-Type' => 'application/json'],
			'POST',
			(string) json_encode(['cmd' => 'hello', 'num' => 'not-an-email'])
		);
		expect($inertia->request->input('cmd', 'fallback', 'strtoupper'))->toBe('fallback')
			->and($inertia->request->input('cmd', 'fallback', 'text'))->toBe('hello')
			->and($inertia->request->input('cmd', 'fallback', static fn (mixed $v): string => strtoupper((string) $v)))->toBe('HELLO');

		// 2. Request::header() blocks non-HTTP $_SERVER env leaks, supports $default, validateCsrf() rejects GET, and %5B brackets are bounded
		$_SERVER['INERTIA_TEST_SECRET_ENV'] = 'do-not-leak';
		$inertia->request->fake(['X-Inertia' => 'true'], 'GET');
		expect($inertia->request->header('INERTIA_TEST_SECRET_ENV'))->toBeNull()
			->and($inertia->request->header('X-Missing-Header', 'def'))->toBe('def')
			->and($inertia->request->validateCsrf(false))->toBeFalse();
		unset($_SERVER['INERTIA_TEST_SECRET_ENV']);

		$encodedBrackets = 'a' . str_repeat('%5B0%5D', 260) . '=1';
		$inertia->request->fake(
			['X-Inertia' => 'true', 'Content-Type' => 'application/x-www-form-urlencoded'],
			'PUT',
			$encodedBrackets
		);
		expect($inertia->request->input('a'))->toBeNull();

		// 3. Session::loginSuccess & Session::logout force OnceProp re-evaluation even when client sends X-Inertia-Except-Once-Props
		$inertia->resetState();
		$onceEvalCount = 0;
		$loginHook = new \ReflectionMethod($inertia, 'hookSessionLoginSuccess');
		$loginHook->invoke($inertia, new \ProcessWire\HookEvent());

		$inertia->request->fake([
			'X-Inertia' => 'true',
			'X-Inertia-Except-Once-Props' => 'permissions',
		], 'GET');

		$postLoginObj = inertia('Dashboard/Index', [
			'permissions' => $inertia->once(static function () use (&$onceEvalCount): array {
				$onceEvalCount++;
				return ['edit-profile'];
			}),
		])->toPageObject();

		expect($postLoginObj['clearHistory'] ?? false)->toBeTrue()
			->and($onceEvalCount)->toBe(1)
			->and($postLoginObj['props']['permissions'] ?? null)->toBe(['edit-profile']);

		// 4. Third-party User serialization redacts email/roles/isSuperuser for non-admin viewers & omits unpublished/trashed Users
		$inertia->resetState();
		$userTpl = wire()->templates->get('user');
		$otherUser = new User($userTpl);
		$otherUser->id = 999991;
		$otherUser->name = 'author-jane';
		$otherUser->set('email', 'jane-private@example.com');

		$unpubUser = new User($userTpl);
		$unpubUser->id = 999992;
		$unpubUser->name = 'suspended-user';
		$unpubUser->addStatus(Page::statusUnpublished);

		$serializedUsers = inertia('Blog/Show', [
			'author' => $otherUser,
			'suspended' => $unpubUser,
			'self' => wire()->user,
		])->toPageObject();

		if (!wire()->user->isSuperuser() && !wire()->user->hasPermission('user-admin')) {
			expect($serializedUsers['props']['author']['name'])->toBe('author-jane')
				->and($serializedUsers['props']['author']['email'])->toBe('')
				->and($serializedUsers['props']['author']['roles'])->toBe([])
				->and($serializedUsers['props']['author']['isSuperuser'])->toBeFalse();
		}
		expect($serializedUsers['props']['suspended'])->toBeNull()
			->and($serializedUsers['props']['self']['id'])->toBe((int) wire()->user->id);

		// Sensitive user field name filter blocks totp_*, recovery_*, *_salt, *_key, *_codes
		$sensRef = new \ReflectionMethod(UserDataProvider::class, 'isSensitiveFieldName');
		expect($sensRef->invoke(null, 'totp_secret'))->toBeTrue()
			->and($sensRef->invoke(null, 'recovery_codes'))->toBeTrue()
			->and($sensRef->invoke(null, 'password_salt'))->toBeTrue()
			->and($sensRef->invoke(null, 'signing_key'))->toBeTrue()
			->and($sensRef->invoke(null, 'public_bio'))->toBeFalse();

		// 5. back() rejects Host-header poisoning, cross-port redirects, and subdirectory escapes
		$config->httpHost = '';
		$config->httpHosts = [];
		$inertia->request->fake(['Host' => 'evil.example', 'Referer' => 'https://evil.example/steal'], 'POST');
		expect($inertia->back('/safe-home')->getHeader('Location'))->toBe('/safe-home');

		$config->httpHost = 'app.example.com:8443';
		$config->httpHosts = ['app.example.com:8443'];
		$inertia->request->fake(['Referer' => 'https://app.example.com:8443/dashboard'], 'POST');
		expect($inertia->back('/safe-home')->getHeader('Location'))->toBe('https://app.example.com:8443/dashboard');
		$inertia->request->fake(['Referer' => 'https://app.example.com:9000/dashboard'], 'POST');
		expect($inertia->back('/safe-home')->getHeader('Location'))->toBe('/safe-home');

		$config->urls->set('root', '/cms/');
		$inertia->request->fake(['Referer' => '/cms/articles'], 'POST');
		expect($inertia->back('/cms/')->getHeader('Location'))->toBe('/cms/articles');
		$inertia->request->fake(['Referer' => '/other-app/phish'], 'POST');
		expect($inertia->back('/cms/')->getHeader('Location'))->toBe('/cms/');
		$config->urls->set('root', $prevRootUrl);

		// 6. HttpGateway blocks cloud metadata / link-local SSRF targets while allowing localhost SSR daemon
		$gw = new HttpGateway('http://127.0.0.1:13714/render', 1.5, true);
		wire()->wire($gw);
		expect($gw->isSafeSsrUrl('http://127.0.0.1:13714/render'))->toBeTrue()
			->and($gw->isSafeSsrUrl('http://169.254.169.254/latest/meta-data/'))->toBeFalse()
			->and($gw->isSafeSsrUrl('http://metadata.google.internal/computeMetadata/v1/'))->toBeFalse()
			->and($gw->isSafeSsrUrl('http://[fe80::1]:13714/render'))->toBeFalse()
			->and($gw->isSafeSsrUrl('http://[fd00:ec2::254]/latest/api/token'))->toBeFalse();

		// 7. SSR <script data-page> replacement & JSON_INVALID_UTF8_SUBSTITUTE resilience
		$inertia->resetState();
		$prevSsrEnabled = (bool) $inertia->ssrEnabled;
		$inertia->set('ssrEnabled', true);
		$inertia->setSsrGateway(new class implements SsrGatewayInterface {
			public function dispatch(array $pageObject): ?SsrResponse
			{
				return new SsrResponse(
					[],
					'<div id="app">User wrote type="application/json" and data-page="fake"</div>'
					. '<script type="application/json" data-page="app">{"raw":"}</script><script>alert(1)</script>"}</script>'
				);
			}
		});
		$ssrAppHtml = $inertia->app(inertia('Docs/Page', ['badUtf8' => "prefix-\xFF-suffix"]));
		$inertia->set('ssrEnabled', $prevSsrEnabled);
		expect($ssrAppHtml)->not->toContain('<script>alert(1)</script>')
			->and($ssrAppHtml)->toContain('prefix-')
			->and(substr_count($ssrAppHtml, '<script type="application/json" data-page="app">'))->toBe(1);

		// 8. Demo mode guard runs during init() for virtual URL hooks ($page->id === 0)
		$inertia->resetState();
		$config->demo = true;
		$input->post->set('mutating_hook_param', 'should-be-purged');
		$inertia->request->fake(['X-Inertia' => 'true'], 'POST', '{"json_hook_param":"blocked"}');
		$inertia->init();
		expect($input->post('mutating_hook_param'))->toBeNull()
			->and($inertia->request->input('json_hook_param'))->toBeNull()
			->and($inertia->request->method())->toBe('GET');
	} finally {
		$config->demo = $prevDemo;
		$config->httpHost = $prevHttpHost;
		$config->httpHosts = $prevHttpHosts;
		$config->urls->set('root', $prevRootUrl);
		$input->post->removeAll();
		$inertia->resetState();
	}
});


it('enforces Pass-9 SSRF IP normalization, head/viewData confinement, non-X-Inertia cache-poisoning & demo guards, Field RBAC, and syncJsonToPost=false CSRF bridging', function (): void {
	$inertia = inertia();
	$config = wire()->config;
	$input = wire()->input;
	$csrf = wire()->session->CSRF;

	$prevDemo = (bool) $config->demo;
	$prevMaxPageNum = $config->maxPageNum;
	$prevSyncJson = (bool) $inertia->syncJsonToPost;
	$prevProtectCsrf = $config->protectCSRF;
	$prevHttpHost = (string) $config->httpHost;
	$prevHttpHosts = $config->httpHosts;
	$prevRequestSuperglobal = $_REQUEST;

	try {
		// 1. HttpGateway::isSafeSsrUrl blocks IPv4-mapped IPv6, expanded AWS IPv6 IMDS, [::], and non-dotted IPv4 literals
		$gw = new HttpGateway('http://127.0.0.1:13714/render', 1.5, true);
		wire()->wire($gw);
		expect($gw->isSafeSsrUrl('http://[::ffff:169.254.169.254]:13714/latest/meta-data'))->toBeFalse()
			->and($gw->isSafeSsrUrl('http://[::ffff:0.0.0.0]:13714/render'))->toBeFalse()
			->and($gw->isSafeSsrUrl('http://[fd00:0ec2:0:0:0:0:0:254]/latest/api/token'))->toBeFalse()
			->and($gw->isSafeSsrUrl('http://[::]:13714/render'))->toBeFalse()
			->and($gw->isSafeSsrUrl('http://2852039166/latest/meta-data/'))->toBeFalse()
			->and($gw->isSafeSsrUrl('http://0251.0376.0251.0376/latest/meta-data/'))->toBeFalse()
			->and($gw->isSafeSsrUrl('http://0xa9fea9fe/latest/meta-data/'))->toBeFalse()
			->and($gw->isSafeSsrUrl('http://[::1]:13714/render'))->toBeTrue();

		// 2. SsrResponse::headMarkup() neutralizes </head> breakouts & Inertia::app() replaces malformed invalid-UTF8 + </script> SSR script tags
		$ssrRes = new SsrResponse(['<title>Safe</title>', '<meta name="x" content="</head><script>alert(1)</script>">'], '<div id="app">ok</div>');
		$headHtml = $ssrRes->headMarkup();
		expect($headHtml)->not->toContain('</head>')
			->and($headHtml)->toContain('&lt;/head>');

		$inertia->resetState();
		$inertia->set('ssrEnabled', true);
		$inertia->setSsrGateway(new class implements SsrGatewayInterface {
			public function dispatch(array $pageObject): ?SsrResponse
			{
				return new SsrResponse(
					[],
					'<script type="application/json" data-page="app">{"bad":"' . "\xFF" . '</script><script>evil()</script>"}</script>'
				);
			}
		});
		$replacedBody = $inertia->app(['component' => 'Clean', 'props' => [], 'url' => '/', 'version' => '1']);
		$inertia->set('ssrEnabled', false);
		expect($replacedBody)->not->toContain('<script>evil()</script>')
			->and($replacedBody)->toContain('"component":"Clean"')
			->and(substr_count($replacedBody, '</script>'))->toBe(1);

		// 3. RootViewRenderer via PageResponse::toOutput() filters superglobal/ProcessWire fuel clobbering in viewData and neutralizes </head> in custom head
		$tempViewPath = wire()->config->paths->templates . '_inertia_pass9_probe.php';
		file_put_contents(
			$tempViewPath,
			'<?php echo ($sanitizer instanceof \ProcessWire\Sanitizer ? "FUEL_OK" : "CLOBBERED") . "|" . ($_SERVER["PASS9_MARKER"] ?? "MISSING") . "|" . ($customSafe ?? "") . "|" . ($head ?? "");'
		);
		$prevMarker = $_SERVER['PASS9_MARKER'] ?? null;
		$_SERVER['PASS9_MARKER'] = 'INTACT';
		try {
			$inertia->resetState();
			$inertia->request->fake([], 'GET');
			$pageResp = inertia('Probe/View')
				->rootView('_inertia_pass9_probe.php')
				->withViewData([
					'sanitizer' => 'HACKED_FUEL',
					'_SERVER' => ['PASS9_MARKER' => 'HIJACKED'],
					'user' => 'HACKED_USER',
					'customSafe' => 'ALLOWED_VAR',
					'head' => '<meta name="probe" content="</head><script>pwn()</script>">',
				]);
			$renderedHtml = $pageResp->toOutput();
			expect($renderedHtml)->toContain('FUEL_OK|INTACT|ALLOWED_VAR|')
				->and($renderedHtml)->not->toContain('</head><script>pwn()');
		} finally {
			@unlink($tempViewPath);
			if ($prevMarker === null) {
				unset($_SERVER['PASS9_MARKER']);
			} else {
				$_SERVER['PASS9_MARKER'] = $prevMarker;
			}
		}

		// 4. Non-X-Inertia mutating POST requests on Inertia-enabled pages in demo mode are blocked & $_REQUEST is purged during ready(),
		// while non-Inertia pages/requests are left untouched by init()
		$inertia->resetState();
		$prevMode = (string) $inertia->mode;
		$prevEnabledTpls = $inertia->enabledTemplates;
		$prevFuelPage = wire()->page;
		try {
			$config->demo = true;
			$inertia->forTemplates([]);
			$homePage = wire()->pages->get(1);
			wire()->fuel->set('page', $homePage);
			$input->post->set('plain_form_mutation', 'untouched_for_non_inertia');
			$_POST['plain_form_mutation'] = 'untouched_for_non_inertia';
			$_REQUEST['plain_form_mutation'] = 'untouched_for_non_inertia';
			$inertia->request->fake(['Content-Type' => 'application/x-www-form-urlencoded'], 'POST');
			$inertia->init();
			$inertia->ready();
			expect($input->post('plain_form_mutation'))->toBe('untouched_for_non_inertia');

			$inertia->forAllTemplates(true);
			$inertia->ready();
			expect($input->post('plain_form_mutation'))->toBeNull()
				->and($_REQUEST)->not->toHaveKey('plain_form_mutation')
				->and($inertia->request->method())->toBe('GET');
		} finally {
			$inertia->set('mode', $prevMode);
			$inertia->set('enabledTemplates', $prevEnabledTpls);
			if ($prevFuelPage instanceof Page) {
				wire()->fuel->set('page', $prevFuelPage);
			}
			$config->demo = false;
		}

		// 5. Forged X-Inertia-* sub-protocol headers are ignored when X-Inertia: true is absent (HTML cache-poisoning protection)
		$inertia->resetState();
		$inertia->withErrors(['email' => 'Required']);
		$inertia->request->fake([
			'X-Inertia-Partial-Component' => 'Catalog/Index',
			'X-Inertia-Partial-Data' => 'onlyMe',
			'X-Inertia-Except-Once-Props' => 'plans',
			'X-Inertia-Reset' => 'items',
			'X-Inertia-Infinite-Scroll-Merge-Intent' => 'prepend',
			'X-Inertia-Error-Bag' => 'forgedBag',
		], 'GET');

		$htmlPageObj = inertia('Catalog/Index', [
			'fullTitle' => 'Visible On HTML',
			'onlyMe' => 'Also Visible',
			'plans' => $inertia->once(static fn(): array => ['pro']),
			'items' => $inertia->merge([['id' => 1]]),
			'feed' => $inertia->scroll([['id' => 10]]),
		])->toPageObject();

		expect($inertia->request->isPartial('Catalog/Index'))->toBeFalse()
			->and($htmlPageObj['props'])->toHaveKey('fullTitle', 'Visible On HTML')
			->and($htmlPageObj['props'])->toHaveKey('plans', ['pro'])
			->and($htmlPageObj['mergeProps'] ?? [])->toContain('items', 'feed.data')
			->and($htmlPageObj['prependProps'] ?? [])->toBe([])
			->and($htmlPageObj['props']['errors'])->toBe(['email' => 'Required']);

		// 6. Field::useRoles RBAC blocks unauthorized field serialization in TemplateDataProvider & ProviderRegistry
		$homePage = wire()->pages->get(1);
		$restrictedField = new Field();
		wire()->wire($restrictedField);
		$restrictedField->name = 'pass9_restricted_secret';
		$restrictedField->type = wire()->modules->get('FieldtypePageTitle');
		$restrictedField->useRoles = true;
		$restrictedField->viewRoles = [999999]; // Non-existent role; note: superuser bypasses viewable, so verify helper & guest behavior
		if (!wire()->user->isSuperuser()) {
			expect(TemplateDataProvider::isFieldViewableOnPage($restrictedField, $homePage))->toBeFalse()
				->and($inertia->providers()->transformField($restrictedField, $homePage))->toBeNull();
		} else {
			expect(TemplateDataProvider::isFieldViewableOnPage($restrictedField, $homePage))->toBeTrue();
		}

		// 7. Inertia::redirect() & back() fallback reject cross-host / protocol-relative open redirects
		$inertia->resetState();
		$config->httpHost = 'example.org';
		$config->httpHosts = ['example.org'];
		$inertia->request->fake(['X-Inertia' => 'true'], 'GET');
		$blockedRedirect = $inertia->redirect('//evil.example/phish');
		expect($blockedRedirect->getHeader('X-Inertia-Redirect'))->toBe('/');

		$blockedBack = $inertia->back('https://evil.example/phish', false);
		expect($blockedBack->getHeader('X-Inertia-Redirect') ?? $blockedBack->getRedirectUrl())->not->toContain('evil.example');

		// 8. Request::pageNumber() clamps scientific notation / huge ints, and syncJsonToPost=false still validates CSRF without leaking JSON keys to $input->post
		$inertia->resetState();
		$config->maxPageNum = 25;
		$input->get->set('page', '1e30');
		expect($inertia->request->pageNumber())->toBe(1);
		$input->get->set('page', '50000');
		expect($inertia->request->pageNumber())->toBe(25);
		$input->get->remove('page');

		$inertia->resetState();
		$config->protectCSRF = true;
		$inertia->set('syncJsonToPost', false);
		$input->post->removeAll();
		$tokenValue = (string) $csrf->getTokenValue();
		$inertia->request->fake(
			[
				'X-Inertia' => 'true',
				'Content-Type' => 'application/json',
				'X-CSRF-TOKEN' => $tokenValue,
			],
			'POST',
			'{"unsynced_secret":"stay-out-of-post","_method":"  patch  "}'
		);
		expect($inertia->request->method())->toBe('PATCH')
			->and($inertia->request->isPost())->toBeFalse()
			->and($inertia->request->validateCsrf())->toBeTrue()
			->and($input->post('unsynced_secret'))->toBeNull()
			->and($inertia->request->input('unsynced_secret'))->toBe('stay-out-of-post');
	} finally {
		$config->demo = $prevDemo;
		$config->maxPageNum = $prevMaxPageNum;
		$config->protectCSRF = $prevProtectCsrf;
		$config->httpHost = $prevHttpHost;
		$config->httpHosts = $prevHttpHosts;
		$inertia->set('syncJsonToPost', $prevSyncJson);
		$_REQUEST = $prevRequestSuperglobal;
		$input->get->removeAll();
		$input->post->removeAll();
		$inertia->resetState();
	}
});


it('enforces Pass-10 HTMX demo coexistence, 303 demo redirects, cyclic PropResolver safety, OnceProp dynamic query bypass, superuser UserDataProvider RBAC, and Vary/Cache-Control headers', function (): void {
	$inertia = inertia();
	$config = wire()->config;
	$input = wire()->input;
	$csrf = wire()->session->CSRF;

	$prevDemo = (bool) $config->demo;
	$prevMaxPageNum = $config->maxPageNum;
	$prevHttpHost = (string) $config->httpHost;
	$prevHttpHosts = $config->httpHosts;
	$prevAdminUrl = (string) $config->urls->admin;
	$prevGet = $_GET;

	try {
		// 1. Cross-module coexistence: non-Inertia HX-Request in $config->demo defers POST purging to Htmx when Htmx is installed
		$config->demo = true;
		$input->post->removeAll();
		$input->post->set('__classic_action', 'querySummary');
		$inertia->request->fake(['X-Requested-With' => 'XMLHttpRequest'], 'POST');
		$inertia->init();
		expect($input->post('__classic_action'))->toBe('querySummary');
		$input->post->removeAll();

		// Modules::saveConfig('Inertia', ...) and TypeGenerator disk writes are blocked in $config->demo
		expect(wire()->modules->saveConfig('Inertia', ['rootView' => '_demo_hijack.php']))->toBeFalse();
		$tmpTsPath = sys_get_temp_dir() . '/inertia-pass10-demo-' . uniqid('', true) . '.ts';
		$genRes = $inertia->typeGenerator()->generate(['basic-page'], true, true, true, true, $tmpTsPath);
		expect($genRes['typescript'])->toBeNull()
			->and($genRes['phpClasses'])->toBe([])
			->and(is_file($tmpTsPath))->toBeFalse();

		// 2. hookSessionRedirect forces 303 See Other even when a PUT/PATCH/DELETE Inertia request was blocked by demo mode
		$inertia->resetState();
		$inertia->request->fake(['X-Inertia' => 'true'], 'DELETE');
		$inertia->enforceDemoModeGuard();
		$redirectEvent = new \ProcessWire\HookEvent();
		wire()->wire($redirectEvent);
		$redirectEvent->setArgument(0, '/items/');
		$redirectEvent->setArgument(1, true);
		$redirectHookRef = new \ReflectionMethod($inertia, 'hookSessionRedirect');
		$redirectHookRef->invoke($inertia, $redirectEvent);
		expect($redirectEvent->arguments(1))->toBe(303)
			->and($inertia->response->getStatusCode())->toBe(303);
		$config->demo = false;

		// 3. inAdmin() enforces strict slash boundary (rejects /processwire-docs/ prefix match)
		$inAdminRef = new \ReflectionMethod($inertia, 'inAdmin');
		$prevWirePage = wire()->page;
		try {
			wire()->fuel->set('page', wire()->pages->get(1));
			$config->urls->set('admin', '/processwire/');
			$prevUri = $_SERVER['REQUEST_URI'] ?? null;
			$_SERVER['REQUEST_URI'] = '/processwire-docs/guide/';
			expect($inAdminRef->invoke($inertia))->toBeFalse();
			if ($prevUri === null) {
				unset($_SERVER['REQUEST_URI']);
			} else {
				$_SERVER['REQUEST_URI'] = $prevUri;
			}
		} finally {
			$config->urls->set('admin', $prevAdminUrl);
			if ($prevWirePage !== null) {
				wire()->fuel->set('page', $prevWirePage);
			}
		}

		// 4. Response::vary() merges tokens additively, 409 Conflict emits no-store + Vary: X-Inertia, and renderPageResponse emits private, no-cache
		$inertia->resetState();
		$inertia->response->vary('Accept-Encoding')->vary('X-Inertia, Accept-Encoding');
		expect($inertia->response->getHeader('Vary'))->toBe('Accept-Encoding, X-Inertia');

		$inertia->resetState();
		$conflictRes = $inertia->response->conflictLocation('/dashboard');
		expect($conflictRes->getStatusCode())->toBe(409)
			->and($conflictRes->getHeader('Cache-Control'))->toBe('no-store, private')
			->and($conflictRes->getHeader('Vary'))->toContain('X-Inertia');

		$config->httpHost = 'example.com';
		$config->httpHosts = ['example.com'];
		expect($inertia->response->sanitizeNavigationUrl('//evil.example/open'))->toBe('/')
			->and($inertia->response->sanitizeNavigationUrl('\\evil.example\\open'))->toBe('/');

		$inertia->resetState();
		$inertia->request->fake(['X-Inertia' => 'true'], 'GET');
		$renderedJson = $inertia->renderPageResponse(inertia('Dashboard/Home', ['ok' => true]));
		expect($renderedJson)->toContain('"component":"Dashboard/Home"')
			->and($inertia->response->getHeader('Cache-Control'))->toBe('no-cache, private')
			->and($inertia->response->getHeader('Vary'))->toContain('X-Inertia');

		// 5. Request::getArray() / postArray() parity, empty/non-string _method fallback, non-POST URL-encoded _token CSRF, and pageNumber() clamping
		$inertia->resetState();
		$input->get->removeAll();
		$input->post->removeAll();
		$input->get->set('filter', 'active');
		$input->get->set('__proto__', 'blocked');
		$inertia->request->fake(
			['X-Inertia' => 'true', 'Content-Type' => 'application/json'],
			'POST',
			'{"_method":"","title":"Hello","constructor":"blocked"}'
		);
		expect($inertia->request->method())->toBe('POST')
			->and($inertia->request->getArray())->toBe(['filter' => 'active'])
			->and($inertia->request->postArray())->toHaveKey('title', 'Hello')
			->and($inertia->request->postArray())->not->toHaveKey('constructor');

		$inertia->resetState();
		$tokenValue = (string) $csrf->getTokenValue();
		$input->post->removeAll();
		$inertia->request->fake(
			['X-Inertia' => 'true', 'Content-Type' => 'application/x-www-form-urlencoded'],
			'PUT',
			'_token=' . rawurlencode($tokenValue) . '&name=updated'
		);
		expect($inertia->request->validateCsrf())->toBeTrue();

		$input->get->set('page', '0');
		expect($inertia->request->pageNumber())->toBe(1);
		$input->get->set('page', '-9');
		expect($inertia->request->pageNumber())->toBe(1);
		$config->maxPageNum = 40;
		$input->get->set('page', '999999999999999');
		expect($inertia->request->pageNumber())->toBe(40);
		$input->get->remove('page');

		// 6. PropResolver cyclic JsonSerializable guard, dot-notation __proto__ filtering, and OnceProp dynamic query-string WireCache bypass
		$inertia->resetState();
		$cyclicNode = new class implements \JsonSerializable {
			public mixed $self = null;

			public function jsonSerialize(): mixed
			{
				return ['label' => 'node', 'child' => $this->self];
			}
		};
		$cyclicNode->self = $cyclicNode;

		$cyclicPageObj = inertia('Graph/View', [
			'node' => $cyclicNode,
			'sibling' => $cyclicNode,
			'safe.__proto__.polluted' => 'dropped',
			'safe.clean' => 'kept',
		])->toPageObject();

		expect($cyclicPageObj['props']['node'])->toBe(['label' => 'node', 'child' => null])
			->and($cyclicPageObj['props']['sibling'])->toBe(['label' => 'node', 'child' => null])
			->and($cyclicPageObj['props']['safe'])->toBe(['clean' => 'kept']);

		wire()->cache->deleteFor(PropResolver::ONCE_CACHE_NAMESPACE);
		$queryRuns = 0;
		$input->get->set('q', 'secret-search');
		$_GET['q'] = 'secret-search';
		inertia('Search/Index', [
			'results' => $inertia->once(function () use (&$queryRuns): string {
				$queryRuns++;

				return 'run-' . $queryRuns . '-' . (string) ($_GET['q'] ?? 'none');
			})->until(120),
		])->toPageObject();

		$input->get->remove('q');
		unset($_GET['q']);
		$cleanOnceObj = inertia('Search/Index', [
			'results' => $inertia->once(function () use (&$queryRuns): string {
				$queryRuns++;

				return 'run-' . $queryRuns . '-' . (string) ($_GET['q'] ?? 'none');
			})->until(120),
		])->toPageObject();
		expect($queryRuns)->toBe(2)
			->and($cleanOnceObj['props']['results'])->toBe('run-2-none');
		wire()->cache->deleteFor(PropResolver::ONCE_CACHE_NAMESPACE);

		// 7. UserDataProvider blocks non-superuser user-admin from reading superuser email/roles; ScalarFieldDataProvider blocks Wire Stringable leaks; Tracy redacts Cookie/CSRF headers
		$userTpl = wire()->templates->get('user');
		$targetSuper = new User($userTpl);
		wire()->wire($targetSuper);
		$targetSuper->id = 999993;
		$targetSuper->name = 'root-owner';
		$targetSuper->set('email', 'root-secret@example.com');
		$superRole = wire()->roles->get('superuser');
		if ($superRole instanceof \ProcessWire\Role && $superRole->id > 0) {
			$targetSuper->roles->add($superRole);
		}

		$delegatedAdmin = new User($userTpl);
		wire()->wire($delegatedAdmin);
		$delegatedAdmin->id = 999994;
		$delegatedAdmin->name = 'delegated-user-admin';

		$prevUser = wire()->user;
		try {
			wire()->fuel->set('user', $delegatedAdmin);
			$providedSuper = $inertia->providers()->provideUser($targetSuper);
			expect($providedSuper['email'])->toBe('')
				->and($providedSuper['roles'])->toBe([])
				->and($providedSuper['isSuperuser'])->toBeFalse();
		} finally {
			if ($prevUser !== null) {
				wire()->fuel->set('user', $prevUser);
			}
		}

		$scalarProvider = new ScalarFieldDataProvider();
		wire()->wire($scalarProvider);
		$wireDataProbe = new \ProcessWire\WireData();
		$wireDataProbe->set('secret', 'internal');
		expect($scalarProvider->transform($wireDataProbe))->toBe('')
			->and((new RepeaterFieldDataProvider())->supports())->toContain('FieldtypeFieldsetPage')
			->and((new PageReferenceFieldDataProvider())->supports())->toContain('FieldtypePageTable');

		$inertia->resetState();
		$inertia->request->fake([
			'X-Inertia' => 'true',
			'Cookie' => 'wires_challenge=supersecret',
			'Authorization' => 'Bearer top-secret',
			'X-CSRF-TOKEN' => 'csrf-secret',
		], 'GET');
		$inertia->response->setHeader('Set-Cookie', 'XSRF-TOKEN=secret');
		$diagRef = new \ReflectionMethod($inertia, 'recordTracyDiagnostics');
		$diagRef->invoke($inertia, ['component' => 'Diag/View', 'url' => '/', 'version' => '1', 'props' => []]);
		$tracyPropRef = new \ReflectionProperty($inertia, 'tracyDebug');
		$diag = $tracyPropRef->getValue($inertia);
		$redactRef = new \ReflectionMethod($inertia, 'redactDiagnosticHeaders');
		$redactedProbe = $redactRef->invoke(null, [
			'Cookie' => 'wires=secret',
			'Authorization' => 'Bearer secret',
			'X-CSRF-TOKEN' => 'token',
			'X-Inertia' => 'true',
		]);
		expect($diag['inboundHeaders'])->not->toHaveKey('Cookie')
			->and($diag['outgoingHeaders']['Set-Cookie'] ?? null)->toBe('[REDACTED]')
			->and($redactedProbe)->toBe([
				'Cookie' => '[REDACTED]',
				'Authorization' => '[REDACTED]',
				'X-CSRF-TOKEN' => '[REDACTED]',
				'X-Inertia' => 'true',
			]);
	} finally {
		$config->demo = $prevDemo;
		$config->maxPageNum = $prevMaxPageNum;
		$config->httpHost = $prevHttpHost;
		$config->httpHosts = $prevHttpHosts;
		$config->urls->set('admin', $prevAdminUrl);
		$_GET = $prevGet;
		$input->get->removeAll();
		$input->post->removeAll();
		$inertia->resetState();
	}
});


it('executes full-page admin Process modules via executeAdminProcess and restores Pages::of() state', function (): void {
	$wire = wire();
	$inertia = inertia();
	$inertia->resetState();

	$prevDisableUnknown = (bool) $wire->config->disableUnknownMethodException;
	$prevOf = $wire->pages->of();
	$prevUser = $wire->user;
	$prevPage = $wire->page;
	$observedOfDuringExecute = null;

	try {
		$wire->config->disableUnknownMethodException = false;
		$wire->pages->of(true);

		$superuser = $wire->users->get($wire->config->superUserPageID);
		if ($superuser instanceof User && $superuser->id > 0) {
			$wire->fuel->set('user', $superuser);
		}

		$adminProcess = new class($observedOfDuringExecute) extends \Totoglu\Inertia\ProcessInertia {
			private mixed $observedRef;

			public function __construct(mixed &$observedRef)
			{
				parent::__construct();
				$this->observedRef = &$observedRef;
			}

			public function className($options = null): string
			{
				return 'ProcessHome';
			}

			public function ___execute(): \Totoglu\Inertia\PageResponse
			{
				$this->observedRef = $this->wire()->pages->of();

				return $this->inertiaRender('Admin/WorkspaceProbe', ['ready' => true]);
			}
		};
		$wire->wire($adminProcess);

		$adminTpl = $wire->templates->get('admin');
		$adminPage = new Page($adminTpl);
		$wire->wire($adminPage);
		$adminPage->id = 999995;
		$adminPage->name = 'workspace-probe';
		$adminPage->setQuietly('process', $adminProcess);
		$wire->fuel->set('page', $adminPage);

		$inertia->request->fake(['X-Inertia' => 'true'], 'GET');
		$inertia->ready();
		$renderedOutput = (string) $adminPage->render();

		expect($observedOfDuringExecute)->toBeFalse()
			->and($wire->pages->of())->toBeTrue()
			->and($renderedOutput)->toContain('"component":"Admin/WorkspaceProbe"')
			->and($inertia->response->getHeader('X-Frame-Options'))->toBe('SAMEORIGIN');
	} finally {
		$wire->config->disableUnknownMethodException = $prevDisableUnknown;
		$wire->pages->of($prevOf);
		if ($prevUser instanceof User) {
			$wire->fuel->set('user', $prevUser);
		}
		if ($prevPage instanceof Page) {
			$wire->fuel->set('page', $prevPage);
		}
		$inertia->resetState();
	}
});


it('enforces Pass-11 cross-module demo method preservation, GET body blocking, WireInput array-mode parity, prefetch encryptHistory no-store, User Pagefile RBAC, SSRF NAT64/trailing-dot guards, and admin permission redirects', function (): void {
	$wire = wire();
	$inertia = inertia();
	$config = $wire->config;
	$input = $wire->input;

	$prevDemo = (bool) $config->demo;
	$prevHttps = (bool) $config->https;
	$prevHttpHost = $config->httpHost;
	$prevHttpHosts = $config->httpHosts;
	$prevSyncJson = (bool) $inertia->syncJsonToPost;
	$prevUser = $wire->user;
	$prevPage = $wire->page;
	$prevMethod = $_SERVER['REQUEST_METHOD'] ?? null;

	try {
		// 1. Cross-module PW_DEMO_ORIGINAL_METHOD preservation (e.g. when Htmx purges REQUEST_METHOD to GET first) & HX-Request: false spoofing defense
		$inertia->resetState();
		$config->demo = true;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['PW_DEMO_ORIGINAL_METHOD'] = 'POST';
		expect($inertia->request->rawMethod())->toBe('POST')
			->and($inertia->request->method())->toBe('GET')
			->and($inertia->request->isDemoModeBlocked())->toBeTrue();

		$inertia->resetState();
		$input->post->set('spoofedWrite', 'must-purge');
		$inertia->request->fake(['X-Inertia' => 'true'], 'POST');
		$inertia->init();
		expect($input->post('spoofedWrite'))->toBeNull();

		// ProcessLogin exemption in demo mode vs non-login admin Process blocking
		$inertia->resetState();
		$adminTpl = $wire->templates->get('admin');
		$loginPage = new Page($adminTpl);
		$wire->wire($loginPage);
		$loginPage->id = 999996;
		$loginPage->name = 'login';
		$loginPage->setQuietly('process', 'ProcessLogin');
		$wire->fuel->set('page', $loginPage);
		$inertia->request->fake(['X-Inertia' => 'true'], 'POST');
		expect($inertia->enforceDemoModeGuard())->toBeFalse();

		// inAdmin() returns false when $wire->page is resolved to a non-admin page even if URL starts with /processwire/
		$homePage = $wire->pages->get(1);
		if ($homePage instanceof Page && $homePage->id > 0) {
			$wire->fuel->set('page', $homePage);
			$inAdminRef = new \ReflectionMethod($inertia, 'inAdmin');
			expect($inAdminRef->invoke($inertia))->toBeFalse();
		}

		// 2. GET JSON/form body injection blocked; WireInput array-mode (tags[] & default=[]); syncJsonToPost=false post() fallback; numeric key preservation
		$config->demo = false;
		$inertia->resetState();
		$input->get->removeAll();
		$input->post->removeAll();

		$inertia->request->fake(
			['X-Inertia' => 'true', 'Content-Type' => 'application/json'],
			'GET',
			'{"injected":"via-get-body"}'
		);
		expect($inertia->request->json())->toBe([])
			->and($inertia->request->input('injected'))->toBeNull();

		$inertia->syncJsonToPost = false;
		$inertia->request->fake(
			['X-Inertia' => 'true', 'Content-Type' => 'application/json'],
			'POST',
			'{"0":"json-zero","tags":["10","20","bad"],"headline":"Clean Title","__proto__":"polluted"}'
		);
		$input->get->set('0', 'get-zero');
		expect($inertia->request->input('tags[]', null, 'int'))->toBe([10, 20, 0])
			->and($inertia->request->input('tags', [], 'int'))->toBe([10, 20, 0])
			->and($inertia->request->post('headline', 'text'))->toBe('Clean Title')
			->and($inertia->request->post('__proto__', null, 'safe'))->toBe('safe')
			->and($inertia->request->get('__proto__', null, 'safe'))->toBe('safe')
			->and($inertia->request->all()[0] ?? $inertia->request->all()['0'] ?? null)->toBe('json-zero');
		$inertia->syncJsonToPost = $prevSyncJson;

		// 3. Cache-Control & conditional Vary headers (encryptHistory on prefetch = no-store, standard visit = Vary: X-Inertia, partial/prefetch = extra Vary)
		$inertia->resetState();
		$inertia->encryptHistory(true);
		$inertia->request->fake(['X-Inertia' => 'true', 'Purpose' => 'prefetch'], 'GET');
		$inertia->renderPageResponse($inertia->render('Vault/Secret', ['secret' => 42]));
		expect($inertia->response->getHeader('Cache-Control'))->toBe('no-store, private')
			->and($inertia->response->getHeader('Vary'))->toContain('X-Inertia')
			->and($inertia->response->getHeader('Vary'))->toContain('Purpose');

		$inertia->resetState();
		$inertia->request->fake(['X-Inertia' => 'true'], 'GET');
		$inertia->renderPageResponse($inertia->render('Standard/Visit', ['ok' => true]));
		expect($inertia->response->getHeader('Vary'))->toBe('X-Inertia');

		expect($inertia->response->sanitizeNavigationUrl('blob:https://example.com/123'))->toBe('/')
			->and($inertia->response->sanitizeNavigationUrl('file:///etc/passwd'))->toBe('/')
			->and($inertia->response->sanitizeNavigationUrl('js:alert(1)'))->toBe('/')
			->and($inertia->response->sanitizeNavigationUrl('/safe\\evil?ok=1'))->toBe('/');

		// OnceProp cache key isolates by scheme://httpHost via public OnceProp evaluation
		if ($homePage instanceof Page && $homePage->id > 0) {
			$wire->fuel->set('page', $homePage);
			$wire->cache->deleteFor(PropResolver::ONCE_CACHE_NAMESPACE);
			$_GET = [];
			$input->get->removeAll();
			$hostEvalRuns = 0;
			$config->https = true;
			$config->httpHost = 'one.example.com';
			$inertia->request->fake(['X-Inertia' => 'true'], 'GET');
			inertia('Host/Probe', [
				'nav' => $inertia->once(function () use (&$hostEvalRuns): string {
					$hostEvalRuns++;

					return 'host-' . $hostEvalRuns;
				})->until(120),
			])->toPageObject();

			// Same host hits cache (no extra evaluation)
			inertia('Host/Probe', [
				'nav' => $inertia->once(function () use (&$hostEvalRuns): string {
					$hostEvalRuns++;

					return 'host-' . $hostEvalRuns;
				})->until(120),
			])->toPageObject();
			expect($hostEvalRuns)->toBe(1);

			// Different host misses cache and evaluates fresh
			$config->httpHost = 'two.example.com';
			inertia('Host/Probe', [
				'nav' => $inertia->once(function () use (&$hostEvalRuns): string {
					$hostEvalRuns++;

					return 'host-' . $hostEvalRuns;
				})->until(120),
			])->toPageObject();
			expect($hostEvalRuns)->toBe(2);
			$wire->cache->deleteFor(PropResolver::ONCE_CACHE_NAMESPACE);
		}

		// 4. SSRF HttpGateway trailing-dot, IPv4-compatible IPv6, and NAT64 metadata guards
		$gateway = new \Totoglu\Inertia\Ssr\HttpGateway();
		$wire->wire($gateway);
		expect($gateway->isSafeSsrUrl('http://metadata.google.internal.:80/render'))->toBeFalse()
			->and($gateway->isSafeSsrUrl('http://[::169.254.169.254]:13714/render'))->toBeFalse()
			->and($gateway->isSafeSsrUrl('http://[64:ff9b::169.254.169.254]:13714/render'))->toBeFalse()
			->and($gateway->isSafeSsrUrl('http://127.0.0.1:13714/render'))->toBeTrue();

		// 5. MarkupInertia asset enqueueing blocks external/protocol-relative/HTTP-downgrade URLs while allowing trusted local URLs
		$config->https = true;
		$config->httpHost = 'app.example.com';
		$config->httpHosts = ['app.example.com'];
		$assetIsland = new class extends \Totoglu\Inertia\MarkupInertia {
			public function inertiaViewData(array $pageObject = []): array
			{
				return [
					'scripts' => [
						'//evil.example/x.js',
						'https://evil.example/x.js',
						'http://app.example.com/x.js',
						'https://app.example.com/site/templates/trusted-abs.js',
						'/site/templates/trusted-rel.js',
					],
				];
			}
		};
		$wire->wire($assetIsland);
		$assetIsland->render(['probe' => 1]);
		$enqueuedScripts = [];
		foreach ($config->scripts as $scriptUrl) {
			$enqueuedScripts[] = (string) $scriptUrl;
		}
		expect($enqueuedScripts)->toContain('https://app.example.com/site/templates/trusted-abs.js')
			->and($enqueuedScripts)->toContain('/site/templates/trusted-rel.js')
			->and($enqueuedScripts)->not->toContain('//evil.example/x.js')
			->and($enqueuedScripts)->not->toContain('https://evil.example/x.js')
			->and($enqueuedScripts)->not->toContain('http://app.example.com/x.js');

		// 6. executeAdminProcess handles WirePermissionException for logged-in user on X-Inertia: true with 409 X-Inertia-Location
		$inertia->resetState();
		$superuser = $wire->users->get($wire->config->superUserPageID);
		if ($superuser instanceof User && $superuser->id > 0) {
			$wire->fuel->set('user', $superuser);
		}
		$deniedProcess = new class extends \Totoglu\Inertia\ProcessInertia {
			public function className($options = null): string
			{
				return 'ProcessHome';
			}

			public function ___execute(): string
			{
				throw new \ProcessWire\WirePermissionException('Access denied to workspace');
			}
		};
		$wire->wire($deniedProcess);
		$deniedAdminPage = new Page($adminTpl);
		$wire->wire($deniedAdminPage);
		$deniedAdminPage->id = 999997;
		$deniedAdminPage->name = 'denied-workspace';
		$deniedAdminPage->setQuietly('process', $deniedProcess);
		$wire->fuel->set('page', $deniedAdminPage);

		$inertia->request->fake(['X-Inertia' => 'true'], 'GET');
		$inertia->ready();
		$deniedOutput = (string) $deniedAdminPage->render();

		expect($deniedOutput)->toBe('')
			->and($inertia->response->getStatusCode())->toBe(409)
			->and($inertia->response->getHeader('X-Inertia-Location'))->not->toBeNull();
	} finally {
		unset($_SERVER['PW_DEMO_ORIGINAL_METHOD']);
		$inertia->syncJsonToPost = $prevSyncJson;
		$config->demo = $prevDemo;
		$config->https = $prevHttps;
		$config->httpHost = $prevHttpHost;
		$config->httpHosts = $prevHttpHosts;
		$input->get->removeAll();
		$input->post->removeAll();
		if ($prevUser instanceof User) {
			$wire->fuel->set('user', $prevUser);
		}
		if ($prevPage instanceof Page) {
			$wire->fuel->set('page', $prevPage);
		}
		if ($prevMethod === null) {
			unset($_SERVER['REQUEST_METHOD']);
		} else {
			$_SERVER['REQUEST_METHOD'] = $prevMethod;
		}
		$inertia->resetState();
	}
});




it('hardens HttpGateway SSRF loopback policy, empty DNS fail-closed, transport seams, and ssrUrl config validation', function (): void {
	$wire = wire();
	$config = $wire->config;
	$inertia = inertia();
	$prevAllowedHosts = $config->inertiaSsrAllowedHosts ?? null;
	$prevModuleConfig = $wire->modules->getConfig('Inertia');

	try {
		unset($config->inertiaSsrAllowedHosts);

		$gateway = new HttpGateway('http://127.0.0.1:13714/render', 1.5, true);
		$wire->wire($gateway);

		// 1. Default strict loopback allows only 127.0.0.1, ::1, and localhost
		expect($gateway->isSafeSsrUrl('http://127.0.0.1:13714/render'))->toBeTrue()
			->and($gateway->isSafeSsrUrl('http://[::1]:13714/render'))->toBeTrue()
			->and($gateway->isSafeSsrUrl('http://localhost:13714/render'))->toBeTrue()
			// Blocks RFC1918 private LANs, CGNAT, IPv6 ULA, non-127.0.0.1 loopback aliases, metadata IPs, and external hosts
			->and($gateway->isSafeSsrUrl('http://10.0.0.5:13714/render'))->toBeFalse()
			->and($gateway->isSafeSsrUrl('http://172.16.0.10:13714/render'))->toBeFalse()
			->and($gateway->isSafeSsrUrl('http://192.168.1.50:13714/render'))->toBeFalse()
			->and($gateway->isSafeSsrUrl('http://100.64.0.1:13714/render'))->toBeFalse()
			->and($gateway->isSafeSsrUrl('http://[fc00::1]:13714/render'))->toBeFalse()
			->and($gateway->isSafeSsrUrl('http://[fd12:3456::1]:13714/render'))->toBeFalse()
			->and($gateway->isSafeSsrUrl('http://169.254.169.254/latest/meta-data/'))->toBeFalse()
			->and($gateway->isSafeSsrUrl('http://127.0.0.2:13714/render'))->toBeFalse()
			->and($gateway->isSafeSsrUrl('http://[::ffff:127.0.0.1]:13714/render'))->toBeFalse()
			->and($gateway->isSafeSsrUrl('https://example.com/render'))->toBeFalse()
			->and($gateway->isSafeSsrUrl('file:///etc/passwd'))->toBeFalse();

		// 2. Empty DNS fails closed (both for localhost and for $config->inertiaSsrAllowedHosts)
		$emptyDnsCalled = false;
		$emptyDnsGateway = new HttpGateway(
			'http://localhost:13714/render',
			1.5,
			true,
			static function () use (&$emptyDnsCalled): array {
				$emptyDnsCalled = true;

				return ['status' => 200, 'body' => '{"head":[],"body":"<div>bad</div>"}'];
			},
			static fn(string $host): array => []
		);
		$wire->wire($emptyDnsGateway);
		expect($emptyDnsGateway->isSafeSsrUrl('http://localhost:13714/render'))->toBeFalse()
			->and($emptyDnsGateway->dispatch(['component' => 'Home', 'props' => [], 'url' => '/', 'version' => '1']))->toBeNull()
			->and($emptyDnsCalled)->toBeFalse();

		// 3. Explicit $config->inertiaSsrAllowedHosts allows configured hostname with safe resolved IP & pins IP, while still blocking metadata/empty DNS
		$config->inertiaSsrAllowedHosts = ['ssr-worker.internal'];
		$pinnedCapture = null;
		$allowedHostGateway = new HttpGateway(
			'http://ssr-worker.internal:13714',
			1.5,
			true,
			static function (string $url, string $json, float $timeout, string $pinnedIp) use (&$pinnedCapture): array {
				$pinnedCapture = $pinnedIp;

				return [
					'status' => 200,
					'body' => json_encode(['head' => ['<title>SSR OK</title>'], 'body' => '<div id="app">rendered</div>']) ?: '{}',
				];
			},
			static fn(string $host): array => $host === 'ssr-worker.internal' ? ['172.18.0.10'] : []
		);
		$wire->wire($allowedHostGateway);
		$ssrOk = $allowedHostGateway->dispatch(['component' => 'Home', 'props' => [], 'url' => '/', 'version' => '1']);
		expect($ssrOk)->toBeInstanceOf(SsrResponse::class)
			->and($ssrOk?->body)->toBe('<div id="app">rendered</div>')
			->and($pinnedCapture)->toBe('172.18.0.10');

		$metadataRebindGateway = new HttpGateway(
			'http://ssr-worker.internal:13714/render',
			1.5,
			true,
			null,
			static fn(string $host): array => ['169.254.169.254']
		);
		$wire->wire($metadataRebindGateway);
		expect($metadataRebindGateway->isSafeSsrUrl('http://ssr-worker.internal:13714/render'))->toBeFalse();

		unset($config->inertiaSsrAllowedHosts);

		// 4. Transport seam: HTTP 500, timeout/exception, and oversized (> MAX_SSR_RESPONSE_BYTES) responses reject cleanly to null
		$http500Gateway = new HttpGateway(
			'http://127.0.0.1:13714/render',
			1.0,
			true,
			static fn(): array => ['status' => 500, 'body' => '{"error":"boom"}']
		);
		$wire->wire($http500Gateway);
		expect($http500Gateway->dispatch(['component' => 'Home', 'props' => [], 'url' => '/', 'version' => '1']))->toBeNull();

		$timeoutGateway = new HttpGateway(
			'http://127.0.0.1:13714/render',
			0.5,
			true,
			static function (): never {
				throw new \RuntimeException('cURL operation timed out after 500 milliseconds');
			}
		);
		$wire->wire($timeoutGateway);
		expect($timeoutGateway->dispatch(['component' => 'Home', 'props' => [], 'url' => '/', 'version' => '1']))->toBeNull();

		$oversizedGateway = new HttpGateway(
			'http://127.0.0.1:13714/render',
			1.0,
			true,
			static fn(): array => ['status' => 200, 'body' => '', 'abortedBySize' => true]
		);
		$wire->wire($oversizedGateway);
		expect($oversizedGateway->dispatch(['component' => 'Home', 'props' => [], 'url' => '/', 'version' => '1']))->toBeNull();

		$oversizedBodyGateway = new HttpGateway(
			'http://127.0.0.1:13714/render',
			1.0,
			true,
			static fn(): array => ['status' => 200, 'body' => str_repeat('x', HttpGateway::MAX_SSR_RESPONSE_BYTES + 1)]
		);
		$wire->wire($oversizedBodyGateway);
		expect($oversizedBodyGateway->dispatch(['component' => 'Home', 'props' => [], 'url' => '/', 'version' => '1']))->toBeNull();

		// 5. Modules::saveConfig('Inertia', ...) validates ssrUrl before saving
		$wire->notices->removeAll();
		$savedUnsafe = $wire->modules->saveConfig('Inertia', array_merge($prevModuleConfig, [
			'ssrUrl' => 'http://192.168.1.20:13714/render',
		]));
		expect($savedUnsafe)->toBeFalse();

		$savedExternal = $wire->modules->saveConfig('Inertia', 'ssrUrl', 'https://evil.example.com/render');
		expect($savedExternal)->toBeFalse();
	} finally {
		if ($prevAllowedHosts === null) {
			unset($config->inertiaSsrAllowedHosts);
		} else {
			$config->inertiaSsrAllowedHosts = $prevAllowedHosts;
		}
		$wire->modules->saveConfig('Inertia', $prevModuleConfig);
		$wire->notices->removeAll();
		$inertia->resetState();
	}
});

it('enforces CSRF validation on mutating ProcessInertia admin requests and catches WireCSRFException cleanly', function (): void {
	$wire = wire();
	$config = $wire->config;
	$inertia = inertia();
	$inertia->resetState();

	$prevProtectCsrf = $config->protectCSRF;
	$prevDemo = (bool) $config->demo;
	$prevUser = $wire->user;
	$prevPage = $wire->page;

	try {
		$config->demo = false;
		$config->protectCSRF = true;

		$superuser = $wire->users->get($config->superUserPageID);
		if ($superuser instanceof User && $superuser->id > 0) {
			$wire->fuel->set('user', $superuser);
		}

		$executedCount = 0;
		$adminProcess = new class($executedCount) extends \Totoglu\Inertia\ProcessInertia {
			private int $executedRef;

			public function __construct(int &$executedRef)
			{
				parent::__construct();
				$this->executedRef = &$executedRef;
			}

			public function className($options = null): string
			{
				return 'ProcessHome';
			}

			public function ___execute(): \Totoglu\Inertia\PageResponse
			{
				$this->executedRef++;

				return $this->inertiaRender('Admin/Mutated', ['count' => $this->executedRef]);
			}
		};
		$wire->wire($adminProcess);

		expect($adminProcess->shouldValidateCsrf())->toBeTrue();

		$adminTpl = $wire->templates->get('admin');
		$adminPage = new Page($adminTpl);
		$wire->wire($adminPage);
		$adminPage->id = 999994;
		$adminPage->name = 'csrf-workspace';
		$adminPage->setQuietly('process', $adminProcess);
		$wire->fuel->set('page', $adminPage);

		// 1. Mutating POST without CSRF token is rejected BEFORE Process::execute() runs -> 409 X-Inertia-Location + flashed error
		$wire->notices->removeAll();
		$wire->input->post->removeAll();
		$inertia->response->reset();
		$inertia->request->fake(['X-Inertia' => 'true'], 'POST');
		$adminPage->render();

		expect($executedCount)->toBe(0)
			->and($inertia->response->getStatusCode())->toBe(409)
			->and($inertia->response->getHeader('X-Inertia-Location'))->not->toBeNull();

		$errorTexts = [];
		foreach ($wire->notices as $notice) {
			if ($notice instanceof \ProcessWire\NoticeError) {
				$errorTexts[] = (string) $notice->text;
			}
		}
		expect($errorTexts)->not->toBeEmpty();

		// 2. Mutating POST with valid X-CSRF-TOKEN succeeds and executes Process::execute()
		$wire->notices->removeAll();
		$wire->input->post->removeAll();
		$inertia->response->reset();
		$validToken = (string) $wire->session->CSRF->getTokenValue();
		$inertia->request->fake([
			'X-Inertia' => 'true',
			'X-CSRF-TOKEN' => $validToken,
		], 'POST');
		$renderedJson = (string) $adminPage->render();

		expect($executedCount)->toBe(1)
			->and($renderedJson)->toContain('"component":"Admin/Mutated"');

		// 3. Explicit WireCSRFException thrown inside any Process::execute() is caught without HTTP 500 (flashes message + redirects/409)
		$throwingProcess = new class extends \Totoglu\Inertia\ProcessInertia {
			public function shouldValidateCsrf(): bool
			{
				return false;
			}

			public function className($options = null): string
			{
				return 'ProcessHome';
			}

			public function ___execute(): \Totoglu\Inertia\PageResponse
			{
				throw new \ProcessWire\WireCSRFException('Custom Process CSRF token expired.');
			}
		};
		$wire->wire($throwingProcess);
		$adminPage->setQuietly('process', $throwingProcess);

		$wire->notices->removeAll();
		$inertia->response->reset();
		$inertia->request->fake(['X-Inertia' => 'true'], 'POST');
		$adminPage->render();

		$caughtErrors = [];
		foreach ($wire->notices as $notice) {
			if ($notice instanceof \ProcessWire\NoticeError) {
				$caughtErrors[] = (string) $notice->text;
			}
		}
		expect($inertia->response->getStatusCode())->toBe(409)
			->and($inertia->response->getHeader('X-Inertia-Location'))->not->toBeNull()
			->and($caughtErrors)->toContain('Custom Process CSRF token expired.');
	} finally {
		$config->protectCSRF = $prevProtectCsrf;
		$config->demo = $prevDemo;
		$wire->input->post->removeAll();
		$wire->notices->removeAll();
		if ($prevUser instanceof User) {
			$wire->fuel->set('user', $prevUser);
		}
		if ($prevPage instanceof Page) {
			$wire->fuel->set('page', $prevPage);
		}
		$inertia->resetState();
	}
});

it('blocks Config, Session, Password, and Module data exposure in PropResolver and enforces User/Page guards in ProviderRegistry', function (): void {
	$wire = wire();
	$inertia = inertia();
	$inertia->resetState();

	$prevUser = $wire->user;

	try {
		// 1. PropResolver blocks Config, Session, Password, and Module objects before WireData::getArray()
		$password = new \ProcessWire\Password();
		$wire->wire($password);
		$password->salt = 'secret-salt-value';
		$password->hash = '$2y$10$secretHashValueShouldNeverLeak';

		$dummyModule = new class extends \ProcessWire\WireData implements \ProcessWire\Module {
			public static function getModuleInfo(): array
			{
				return ['title' => 'DummySecretModule', 'version' => 1];
			}
		};
		$wire->wire($dummyModule);
		$dummyModule->set('apiSecretKey', 'sk_live_super_secret');

		$resolved = inertia('Security/Redaction', [
			'configObj' => $wire->config,
			'sessionObj' => $wire->session,
			'passwordObj' => $password,
			'inertiaMod' => $inertia,
			'customMod' => $dummyModule,
		])->toPageObject();

		expect($resolved['props']['configObj'])->toBeNull()
			->and($resolved['props']['sessionObj'])->toBeNull()
			->and($resolved['props']['passwordObj'])->toBeNull()
			->and($resolved['props']['inertiaMod'])->toBeNull()
			->and($resolved['props']['customMod'])->toBeNull();

		// 2. ProviderRegistry::providePage(User) delegates to provideUser(User) and strips credentials
		$superuser = $wire->users->get($wire->config->superUserPageID);
		if ($superuser instanceof User && $superuser->id > 0) {
			$userViaPage = $inertia->providers()->providePage($superuser);
			expect($userViaPage)->toBe($inertia->providers()->provideUser($superuser))
				->and($userViaPage)->not->toHaveKey('pass')
				->and($userViaPage)->not->toHaveKey('template');
		}

		// 3. ProviderRegistry::providePage() returns [] for unviewable / unpublished / trashed pages
		$guestUser = $wire->users->getGuestUser();
		$wire->users->setCurrentUser($guestUser);
		$adminRoot = $wire->pages->get(2);
		expect($inertia->providers()->providePage($adminRoot))->toBe([]);

		$unpubPage = new Page($wire->templates->get('page'));
		$wire->wire($unpubPage);
		$unpubPage->id = 999993;
		$unpubPage->addStatus(Page::statusUnpublished);
		expect($inertia->providers()->providePage($unpubPage))->toBe([]);

		// 4. UserDataProvider::provide() returns [] for zero-ID, trashed, or unpublished users
		$userTpl = $wire->templates->get('user');
		$zeroUser = new User($userTpl);
		$wire->wire($zeroUser);
		expect($inertia->providers()->provideUser($zeroUser))->toBe([]);

		$unpubUser = new User($userTpl);
		$wire->wire($unpubUser);
		$unpubUser->id = 999992;
		$unpubUser->name = 'disabled-account';
		$unpubUser->addStatus(Page::statusUnpublished);
		expect($inertia->providers()->provideUser($unpubUser))->toBe([]);

		$trashedUser = new User($userTpl);
		$wire->wire($trashedUser);
		$trashedUser->id = 999991;
		$trashedUser->name = 'trashed-account';
		$trashedUser->addStatus(Page::statusTrash);
		expect($inertia->providers()->provideUser($trashedUser))->toBe([]);
	} finally {
		if ($prevUser instanceof User) {
			$wire->users->setCurrentUser($prevUser);
		}
		$inertia->resetState();
	}
});

it('standardizes JSON_HTML_SAFE_FLAGS, enforces navigation URL allowlist, resolves Vite hot version, supports Sec-Purpose prefetch, and emits object flash + explicit encryptHistory false', function (): void {
	$wire = wire();
	$inertia = inertia();
	$inertia->resetState();

	// 1. JSON_HTML_SAFE_FLAGS across Inertia::app(), MarkupInertia, and InputfieldInertia
	$expectedFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
	expect(\ProcessWire\Inertia::JSON_HTML_SAFE_FLAGS)->toBe($expectedFlags);

	$dangerousPayload = ['xss' => "</script><img src=x onerror=\"alert('pwn')\" data-a='1&2'>"];
	$appHtml = $inertia->app(inertia('Escaping/Test', $dangerousPayload));
	expect($appHtml)->toContain('\u003C/script\u003E')
		->and($appHtml)->toContain('\u0026')
		->and($appHtml)->toContain('\u0027pwn\u0027')
		->and($appHtml)->toContain('\u0022alert');

	$markupIsland = new class extends \Totoglu\Inertia\MarkupInertia {
		public function inertiaComponent(): string
		{
			return 'Widgets/SafeIsland';
		}

		public function inertiaWithData(): array
		{
			return ['snippet' => "<tag attr=\"q\" single='s'>a&b</tag>"];
		}
	};
	$wire->wire($markupIsland);
	$islandHtml = $markupIsland->render();
	expect($islandHtml)->toContain('\u003Ctag')
		->and($islandHtml)->toContain('\u0026')
		->and($islandHtml)->toContain('\u0027s\u0027');

	$inputIsland = new class extends \Totoglu\Inertia\InputfieldInertia {
		public function inertiaComponent(): string
		{
			return 'Fields/SafeInput';
		}
	};
	$wire->wire($inputIsland);
	$inputIsland->attr('name', 'safe_json_input');
	$inputIsland->setAttribute('value', ['raw' => "<b title=\"x\" data-s='y'>&</b>"]);
	$renderedInput = $inputIsland->render();
	$renderedValue = $inputIsland->renderValue();
	expect($renderedInput)->toContain('\u003Cb')
		->and($renderedValue)->toContain('\u003Cb');

	// 2. Response::sanitizeNavigationUrl() strict allowlist
	$res = $inertia->response;
	expect($res->sanitizeNavigationUrl('/orders/42?tab=items#summary'))->toBe('/orders/42?tab=items#summary')
		->and($res->sanitizeNavigationUrl('?filter=active'))->toBe('?filter=active')
		->and($res->sanitizeNavigationUrl('#section-2'))->toBe('#section-2')
		->and($res->sanitizeNavigationUrl('sub/path?x=1'))->toBe('sub/path?x=1')
		->and($res->sanitizeNavigationUrl('https://processwire.com/docs/'))->toBe('https://processwire.com/docs/')
		->and($res->sanitizeNavigationUrl('http://localhost:8080/callback'))->toBe('http://localhost:8080/callback')
		// Rejects non-http(s) schemes, protocol-relative, backslashes, credentials, and hostless http(s)
		->and($res->sanitizeNavigationUrl('javascript:alert(1)'))->toBe('/')
		->and($res->sanitizeNavigationUrl('mailto:admin@example.com'))->toBe('/')
		->and($res->sanitizeNavigationUrl('tel:+15551234567'))->toBe('/')
		->and($res->sanitizeNavigationUrl('ftp://files.example.com/pub'))->toBe('/')
		->and($res->sanitizeNavigationUrl('custom-app://open'))->toBe('/')
		->and($res->sanitizeNavigationUrl('//evil.example.com/phish'))->toBe('/')
		->and($res->sanitizeNavigationUrl('relative//double-slash'))->toBe('/')
		->and($res->sanitizeNavigationUrl('relative:colon/path'))->toBe('/')
		->and($res->sanitizeNavigationUrl('/path\\with\\backslash'))->toBe('/')
		->and($res->sanitizeNavigationUrl('https://user:pass@example.com/steal'))->toBe('/')
		->and($res->sanitizeNavigationUrl('http://user@example.com/steal'))->toBe('/')
		->and($res->sanitizeNavigationUrl('https:///missing-host'))->toBe('/');

	// 3. Vite hot file version resolution & 409 Conflict (using isolated temp directory, never touching site/templates/hot)
	$tempViteRoot = rtrim(sys_get_temp_dir(), '/\\') . '/inertia_vite_hot_' . uniqid();
	mkdir($tempViteRoot, 0777, true);
	$tempHotFile = $tempViteRoot . '/hot';
	file_put_contents($tempHotFile, 'http://localhost:5173');
	$vite = $wire->vite;
	$prevViteRoot = $vite instanceof \ProcessWire\Vite ? $vite->rootPath : null;
	$prevViteHotFile = $vite instanceof \ProcessWire\Vite ? $vite->hotFile : null;

	try {
		$inertia->version(null);
		if ($vite instanceof \ProcessWire\Vite) {
			$vite->rootPath = $tempViteRoot;
			$vite->hotFile = 'hot';
		}
		$expectedHotVersion = 'hot:' . (string) md5_file($tempHotFile);
		expect($inertia->getVersion())->toBe($expectedHotVersion);

		$inertia->response->reset();
		$inertia->request->fake([
			'X-Inertia' => 'true',
			'X-Inertia-Version' => 'stale-manifest-hash',
		], 'GET');
		$conflictOut = $inertia->renderPageResponse(inertia('Dashboard/HotTest'));
		expect($conflictOut)->toBe('')
			->and($inertia->response->getStatusCode())->toBe(409)
			->and($inertia->response->getHeader('X-Inertia-Location'))->not->toBeNull();
	} finally {
		if ($vite instanceof \ProcessWire\Vite) {
			$vite->rootPath = (string) $prevViteRoot;
			$vite->hotFile = (string) $prevViteHotFile;
		}
		@unlink($tempHotFile);
		@rmdir($tempViteRoot);
	}

	// 4. Sec-Purpose: prefetch support in Request::isPrefetch()
	$inertia->resetState();
	$inertia->flash('keepMe', 'preserved');
	$inertia->request->fake([
		'X-Inertia' => 'true',
		'Sec-Purpose' => 'prefetch',
	], 'GET');
	expect($inertia->request->isPrefetch())->toBeTrue()
		->and($inertia->request->headers()['sec-purpose'] ?? null)->toBe('prefetch');

	$secPrefetchJson = $inertia->renderPageResponse(inertia('Prefetch/SecPurpose'));
	expect($secPrefetchJson)->toContain('"flash":{}')
		->and($inertia->response->getHeader('Vary'))->toContain('Sec-Purpose');

	// 5. Always emit top-level 'flash' => (object) $topLevelFlash and 'encryptHistory' => false when overridden to false
	$inertia->resetState();
	$emptyFlashResponse = inertia('Protocol/V3ObjectFlash');
	$emptyFlashObj = $emptyFlashResponse->toPageObject();
	$emptyFlashJson = $emptyFlashResponse->toJson();
	expect($emptyFlashObj)->toHaveKey('flash')
		->and($emptyFlashObj['flash'])->toBeInstanceOf(\stdClass::class)
		->and((array) $emptyFlashObj['flash'])->toBe([])
		->and($emptyFlashJson)->toContain('"flash":{}');

	$inertia->encryptHistory(true);
	$explicitUnencrypted = inertia('Protocol/PublicHistory')->encryptHistory(false)->toPageObject();
	expect($explicitUnencrypted)->toHaveKey('encryptHistory')
		->and($explicitUnencrypted['encryptHistory'])->toBeFalse();

	$inertia->encryptHistory(false);
	$explicitFalseWhenGlobalFalse = inertia('Protocol/ExplicitFalse')->encryptHistory(false)->toPageObject();
	expect($explicitFalseWhenGlobalFalse)->toHaveKey('encryptHistory')
		->and($explicitFalseWhenGlobalFalse['encryptHistory'])->toBeFalse();

	$inertia->resetState();
});


it('cleans WireCache and Session namespaces on uninstall, enforces zero deleted aliases, and executes CLI commands cleanly', function (): void {
	$wire = wire();
	$inertia = inertia();
	$config = $wire->config;
	$prevDemo = (bool) $config->demo;
	$tmpTsPath = sys_get_temp_dir() . '/inertia-cli-types-' . uniqid('', true) . '.d.ts';

	try {
		// 1. Deleted pass-through aliases must not exist on Inertia or PageResponse
		expect(method_exists(Inertia::class, 'shared'))->toBeFalse()
			->and(method_exists(Inertia::class, 'withViewData'))->toBeFalse()
			->and(method_exists(PageResponse::class, 'viewData'))->toBeFalse()
			->and(method_exists(Inertia::class, 'clearOnceCacheForPage'))->toBeFalse()
			->and(method_exists(Inertia::class, 'isDemoMode'))->toBeFalse();

		// 2. Inertia::uninstall() cleans PropResolver::ONCE_CACHE_NAMESPACE and Inertia::SESSION_NAMESPACE
		$wire->cache->saveFor(PropResolver::ONCE_CACHE_NAMESPACE, 'uninstall_probe', ['cached' => 123], 3600);
		$wire->session->setFor(Inertia::SESSION_NAMESPACE, 'flash', ['banner' => 'persisted']);
		$wire->session->setFor(Inertia::SESSION_NAMESPACE, 'errors', ['email' => 'Invalid']);
		expect($wire->cache->getFor(PropResolver::ONCE_CACHE_NAMESPACE, 'uninstall_probe'))->toBe(['cached' => 123])
			->and($wire->session->getFor(Inertia::SESSION_NAMESPACE, 'flash'))->toBe(['banner' => 'persisted']);

		$inertia->uninstall();

		expect($wire->cache->getFor(PropResolver::ONCE_CACHE_NAMESPACE, 'uninstall_probe'))->toBeNull()
			->and($wire->session->getFor(Inertia::SESSION_NAMESPACE, 'flash'))->toBeNull()
			->and($wire->session->getFor(Inertia::SESSION_NAMESPACE, 'errors'))->toBeNull();

		// 3. CLI commands do not import global wire() helper and execute via $this->wire()
		$installCmdSource = (string) file_get_contents(dirname(__DIR__, 2) . '/commands/InertiaInstallCommand.php');
		$typesCmdSource = (string) file_get_contents(dirname(__DIR__, 2) . '/commands/InertiaTypesCommand.php');
		expect($installCmdSource)->not->toContain('use function ProcessWire\\wire;')
			->and($installCmdSource)->toContain('$this->wire()')
			->and($typesCmdSource)->not->toContain('use function ProcessWire\\wire;')
			->and($typesCmdSource)->toContain('$this->wire()');

		// 4. InertiaTypesCommand generates .d.ts with --ts-only --json and rejects demo mode
		$config->demo = false;
		$typesCmd = new \Totoglu\Inertia\Commands\InertiaTypesCommand();
		$typesTester = new \Symfony\Component\Console\Tester\CommandTester($typesCmd);
		$exitOk = $typesTester->execute([
			'--template' => ['home'],
			'--ts-only' => true,
			'--output' => $tmpTsPath,
			'--json' => true,
		]);
		$jsonTypes = json_decode($typesTester->getDisplay(), true);
		expect($exitOk)->toBe(\Symfony\Component\Console\Command\Command::SUCCESS)
			->and($jsonTypes['ok'] ?? null)->toBeTrue()
			->and($jsonTypes['data']['phpClasses'] ?? null)->toBe([])
			->and(is_file($tmpTsPath))->toBeTrue()
			->and((string) file_get_contents($tmpTsPath))->toContain('HomePageData');

		$config->demo = true;
		$exitDemoTypes = $typesTester->execute(['--ts-only' => true, '--json' => true]);
		$jsonDemoTypes = json_decode($typesTester->getDisplay(), true);
		expect($exitDemoTypes)->toBe(\Symfony\Component\Console\Command\Command::FAILURE)
			->and($jsonDemoTypes['ok'] ?? null)->toBeFalse()
			->and($jsonDemoTypes['error']['code'] ?? null)->toBe('DEMO_MODE');

		// 5. InertiaInstallCommand rejects invalid --stack and demo mode cleanly
		$installCmd = new \Totoglu\Inertia\Commands\InertiaInstallCommand();
		$installTester = new \Symfony\Component\Console\Tester\CommandTester($installCmd);
		$exitDemoInstall = $installTester->execute(['--json' => true]);
		$jsonDemoInstall = json_decode($installTester->getDisplay(), true);
		expect($exitDemoInstall)->toBe(\Symfony\Component\Console\Command\Command::FAILURE)
			->and($jsonDemoInstall['ok'] ?? null)->toBeFalse()
			->and($jsonDemoInstall['error']['code'] ?? null)->toBe('DEMO_MODE');

		$config->demo = false;
		$exitInvalidStack = $installTester->execute(['--stack' => 'angular', '--json' => true]);
		$jsonInvalidStack = json_decode($installTester->getDisplay(), true);
		expect($exitInvalidStack)->toBe(\Symfony\Component\Console\Command\Command::FAILURE)
			->and($jsonInvalidStack['ok'] ?? null)->toBeFalse()
			->and($jsonInvalidStack['error']['code'] ?? null)->toBe('INVALID_STACK');
	} finally {
		$config->demo = $prevDemo;
		@unlink($tmpTsPath);
		$inertia->resetState();
	}
});
