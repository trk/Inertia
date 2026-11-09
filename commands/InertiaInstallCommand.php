<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Commands;

use Laravel\Prompts\Prompt;
use ProcessWire\Inertia;
use ProcessWire\Template;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Totoglu\Inertia\Provider\ProviderRegistry;
use Totoglu\Inertia\Support\TypeGenerator;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function ProcessWire\wire;

final class InertiaInstallCommand extends Command
{
	protected function configure(): void
	{
		$this
			->setName('inertia:install')
			->setDescription('Publish the Inertia.js v3 Root View (_inertia.php), Vite entry (inertia.ts), starter SPA page component, Custom Page Class, and TypeScript definitions.')
			->addOption('stack', 's', InputOption::VALUE_REQUIRED, 'Frontend framework stack: vue, react, or svelte', 'vue')
			->addOption('template', 't', InputOption::VALUE_REQUIRED, 'ProcessWire template to enable and scaffold', 'inertia')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing starter files')
			->addOption('json', null, InputOption::VALUE_NONE, 'Output results as structured JSON');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		Prompt::setOutput($output);

		$force = (bool) $input->getOption('force');
		$asJson = (bool) $input->getOption('json');

		$stackRaw = strtolower(trim((string) $input->getOption('stack')));
		if (!in_array($stackRaw, ['vue', 'react', 'svelte'], true)) {
			$msg = "Unsupported stack '{$stackRaw}'. Allowed values: vue, react, svelte.";
			if ($asJson) {
				$output->writeln((string) json_encode([
					'ok' => false,
					'error' => [
						'code' => 'INVALID_STACK',
						'message' => $msg,
					],
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

				return Command::FAILURE;
			}

			error($msg);

			return Command::FAILURE;
		}

		$templateName = (string) preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string) $input->getOption('template')));
		if ($templateName === '' || $templateName === 'admin') {
			$templateName = 'inertia';
		}

		$wire = wire();
		$inertia = Inertia::instance();
		$typeGenerator = $inertia->typeGenerator();
		$config = $wire->config;
		$modules = $wire->modules;
		$templates = $wire->templates;

		$templatesPath = rtrim((string) $config->paths->templates, '/\\');

		// 1. Enable template in Inertia instance
		$tplObj = $templates->get($templateName);
		if ($tplObj instanceof Template) {
			$inertia->forTemplates($templateName, true);
		}

		$filesReport = [];

		// 2. Publish site/templates/_inertia.php
		$rootViewStub = __DIR__ . '/stubs/templates/_inertia.php';
		$rootViewDest = $templatesPath . '/_inertia.php';
		$filesReport[] = $this->publishContent(
			$typeGenerator,
			$rootViewDest,
			is_file($rootViewStub) ? (string) file_get_contents($rootViewStub) : '',
			$force,
			'Root View (_inertia.php)'
		);

		// 3. Publish site/templates/assets/js/inertia.ts
		$entryFile = $templatesPath . '/assets/js/inertia.ts';
		$filesReport[] = $this->publishContent(
			$typeGenerator,
			$entryFile,
			$this->buildEntryPointCode($stackRaw),
			$force,
			'Vite Inertia Entry (assets/js/inertia.ts)'
		);

		// 4. Scaffold starter frontend component (e.g. Pages/Inertia/Show.vue)
		$pascal = ProviderRegistry::studly($templateName);
		$ext = match ($stackRaw) {
			'react' => 'tsx',
			'svelte' => 'svelte',
			default => 'vue',
		};
		$componentPath = $templatesPath . "/assets/js/Pages/{$pascal}/Show.{$ext}";
		$filesReport[] = $this->publishContent(
			$typeGenerator,
			$componentPath,
			$this->buildStarterComponentCode($stackRaw, $pascal),
			$force,
			"Starter Component (Pages/{$pascal}/Show.{$ext})"
		);

		// 5. Register assets/js/inertia.ts in site/templates/vite.config.js if present
		$viteConfigPath = $templatesPath . '/vite.config.js';
		if (is_file($viteConfigPath)) {
			$filesReport[] = $this->patchViteConfig($typeGenerator, $viteConfigPath, $stackRaw);
		}

		// 6. Persist enabledTemplates in Inertia module configuration
		if ($tplObj instanceof Template && $modules->isInstalled('Inertia')) {
			$savedConfig = $modules->getConfig('Inertia');
			$existingEnabled = is_array($savedConfig['enabledTemplates'] ?? null) ? $savedConfig['enabledTemplates'] : [];
			if (!in_array($templateName, $existingEnabled, true)) {
				$existingEnabled[] = $templateName;
			}
			$savedConfig['enabledTemplates'] = array_values(array_unique($existingEnabled));
			$modules->saveConfig('Inertia', $savedConfig);
		}

		// 7. Run TypeGenerator for Custom Page Class, Template Controller, and inertia.d.ts
		$typeGenResult = $typeGenerator->generate(
			templateNames: [$templateName],
			force: $force,
			generatePhp: true,
			generateTs: true,
			generateControllers: true
		);

		if ($asJson) {
			$output->writeln((string) json_encode([
				'ok' => true,
				'data' => [
					'stack' => $stackRaw,
					'template' => $templateName,
					'files' => $filesReport,
					'types' => $typeGenResult,
				],
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return Command::SUCCESS;
		}

		$rows = [];
		foreach ($filesReport as $f) {
			$rows[] = [$f['label'], $f['path'], strtoupper($f['status'])];
		}
		foreach ($typeGenResult['phpClasses'] as $cls) {
			$rows[] = ["Page Class ({$cls['class']})", $cls['path'], strtoupper($cls['status'])];
		}
		foreach ($typeGenResult['templateControllers'] as $ctrl) {
			$rows[] = ["Template Controller ({$ctrl['template']}.php)", $ctrl['path'], strtoupper($ctrl['status'])];
		}
		if (is_array($typeGenResult['typescript'])) {
			$rows[] = ['TypeScript Definitions', $typeGenResult['typescript']['path'], 'WRITTEN'];
		}

		table(['Artifact', 'Path', 'Status'], $rows);
		info("Inertia.js v3 ({$stackRaw}) scaffolding completed for template '{$templateName}'.");

		return Command::SUCCESS;
	}

	/**
	 * @return array{label: string, path: string, status: string}
	 */
	private function publishContent(
		TypeGenerator $typeGenerator,
		string $destPath,
		string $content,
		bool $force,
		string $label
	): array {
		return [
			'label' => $label,
			'path' => $destPath,
			'status' => $typeGenerator->writeScaffoldFile($destPath, $content, $force),
		];
	}

	/**
	 * Ensure `assets/js/inertia.ts` (and `@vitejs/plugin-vue` when stack is `vue`) is registered in `vite.config.js`.
	 *
	 * @return array{label: string, path: string, status: string}
	 */
	private function patchViteConfig(TypeGenerator $typeGenerator, string $viteConfigPath, string $stack): array
	{
		$original = (string) file_get_contents($viteConfigPath);
		$updated = $original;

		if (!str_contains($updated, 'assets/js/inertia.ts')) {
			$updated = str_replace(
				'input: ["assets/css/app.css", "assets/js/app.js"]',
				'input: ["assets/css/app.css", "assets/js/app.js", "assets/js/inertia.ts"]',
				$updated
			);
		}

		if ($stack === 'vue' && !str_contains($updated, '@vitejs/plugin-vue')) {
			$updated = str_replace(
				'import tailwindcss from "@tailwindcss/vite";',
				"import tailwindcss from \"@tailwindcss/vite\";\nimport vue from \"@vitejs/plugin-vue\";",
				$updated
			);
			$updated = str_replace(
				"plugins: [\n\t\t\ttailwindcss(),",
				"plugins: [\n\t\t\tvue(),\n\t\t\ttailwindcss(),",
				$updated
			);
		}

		if ($updated === $original) {
			return [
				'label' => 'Vite Config (vite.config.js)',
				'path' => $viteConfigPath,
				'status' => 'unchanged',
			];
		}

		$typeGenerator->writeFileSafely($viteConfigPath, $updated);

		return [
			'label' => 'Vite Config (vite.config.js)',
			'path' => $viteConfigPath,
			'status' => 'updated',
		];
	}

	private function buildEntryPointCode(string $stack): string
	{
		[$imports, $ext, $globType, $setup] = match ($stack) {
			'react' => [
				"import { createInertiaApp } from \"@inertiajs/react\";\nimport { createRoot } from \"react-dom/client\";",
				'tsx',
				'',
				"setup({ el, App, props }) {\n\t\tcreateRoot(el).render(<App {...props} />);\n\t},",
			],
			'svelte' => [
				"import { createInertiaApp } from \"@inertiajs/svelte\";\nimport { mount } from \"svelte\";",
				'svelte',
				'',
				"setup({ el, App, props }) {\n\t\tmount(App, { target: el, props });\n\t},",
			],
			default => [
				"import { createApp, h, type DefineComponent } from \"vue\";\nimport { createInertiaApp } from \"@inertiajs/vue3\";",
				'vue',
				'<DefineComponent>',
				"setup({ el, App, props, plugin }) {\n\t\tcreateApp({ render: () => h(App, props) })\n\t\t\t.use(plugin)\n\t\t\t.mount(el);\n\t},",
			],
		};

		return <<<TS
{$imports}

createInertiaApp({
	resolve: (name: string) => {
		const pages = import.meta.glob{$globType}("./Pages/**/*.{$ext}", { eager: true });
		const page = pages[`./Pages/\${name}.{$ext}`];
		if (!page) {
			throw new Error(`Inertia page component not found: ./Pages/\${name}.{$ext}`);
		}
		return page;
	},
	{$setup}
});

TS;
	}

	private function buildStarterComponentCode(string $stack, string $pascal): string
	{
		$interfaceName = "{$pascal}PageData";

		if ($stack === 'react') {
			return <<<TSX
import React from "react";
import { Head, Link, Deferred } from "@inertiajs/react";
import type { InertiaPageProps, {$interfaceName} } from "../../types/inertia";

export default function Show({ page, children }: InertiaPageProps<{$interfaceName}>) {
	return (
		<>
			<Head title={page.title} />
			<main className="max-w-4xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
				<header className="mb-8">
					<span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 mb-3">
						Inertia.js v3 &middot; {page.template}
					</span>
					<h1 className="text-3xl sm:text-4xl font-bold text-gray-900 dark:text-white">
						{page.title}
					</h1>
				</header>
				<Deferred data="children" fallback={<p className="mt-8 text-sm text-gray-500">Loading child pages...</p>}>
					{children && children.length > 0 && (
						<section className="mt-10 pt-8 border-t border-gray-200 dark:border-neutral-700">
							<h2 className="text-lg font-semibold mb-4">Subpages</h2>
							<ul className="grid sm:grid-cols-2 gap-4">
								{children.map((child) => (
									<li key={child.id}>
										<Link
											href={child.url}
											className="block p-4 rounded-xl border border-gray-200 hover:border-blue-500 transition"
										>
											{child.title}
										</Link>
									</li>
								))}
							</ul>
						</section>
					)}
				</Deferred>
			</main>
		</>
	);
}

TSX;
		}

		if ($stack === 'svelte') {
			return <<<SVELTE
<script lang="ts">
	import type { InertiaPageProps, {$interfaceName} } from "../../types/inertia";

	let { page, children = [] }: InertiaPageProps<{$interfaceName}> = \$props();
</script>

<main class="max-w-4xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
	<h1 class="text-3xl font-bold">{page.title}</h1>
</main>

SVELTE;
		}

		return <<<VUE
<script setup lang="ts">
import { Head, Link, Deferred } from "@inertiajs/vue3";
import type { InertiaPageBase, {$interfaceName}, InertiaFlash } from "../../types/inertia";

defineProps<{
	page: {$interfaceName};
	children?: InertiaPageBase[];
	flash?: InertiaFlash;
}>();
</script>

<template>
	<Head :title="page.title" />

	<div class="min-h-screen bg-gray-50 text-gray-800 dark:bg-neutral-900 dark:text-neutral-200">
		<header class="bg-white border-b border-gray-200 dark:bg-neutral-800 dark:border-neutral-700">
			<div class="max-w-5xl mx-auto py-4 px-4 sm:px-6 lg:px-8 flex items-center justify-between">
				<a href="/" class="text-sm font-medium text-gray-600 hover:text-blue-600 dark:text-neutral-400 dark:hover:text-blue-400">
					&larr; Home
				</a>
				<span class="inline-flex items-center gap-x-1.5 py-1 px-2.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-800/30 dark:text-blue-400">
					Inertia.js v3 &middot; {{ page.template }}
				</span>
			</div>
		</header>

		<main class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
			<article class="bg-white rounded-2xl shadow-2xs border border-gray-200 overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
				<div class="p-6 sm:p-10">
					<h1 class="text-2xl sm:text-4xl font-bold text-gray-900 dark:text-white">
						{{ page.title }}
					</h1>
				</div>
			</article>

			<Deferred data="children">
				<template #fallback>
					<div class="mt-8 p-6 rounded-xl border border-dashed border-gray-300 text-sm text-gray-500 dark:border-neutral-700 dark:text-neutral-400">
						Loading child pages...
					</div>
				</template>

				<section v-if="children && children.length > 0" class="mt-8">
					<h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
						Subpages
					</h2>
					<div class="grid sm:grid-cols-2 gap-4">
						<Link
							v-for="child in children"
							:key="child.id"
							:href="child.url"
							prefetch
							class="group block p-5 bg-white rounded-xl border border-gray-200 hover:border-blue-500 transition dark:bg-neutral-800 dark:border-neutral-700 dark:hover:border-blue-500"
						>
							<h3 class="font-semibold text-gray-900 group-hover:text-blue-600 dark:text-white dark:group-hover:text-blue-400">
								{{ child.title }}
							</h3>
							<span class="mt-1 block text-xs text-gray-500 dark:text-neutral-400">
								{{ child.url }}
							</span>
						</Link>
					</div>
				</section>
			</Deferred>
		</main>
	</div>
</template>

VUE;
	}
}
