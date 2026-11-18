<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Commands;

use Laravel\Prompts\Prompt;
use ProcessWire\Inertia;
use ProcessWire\ProcessWire;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Totoglu\Console\Traits\InteractWithProcessWire;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

final class InertiaTypesCommand extends Command
{
	use InteractWithProcessWire;

	private function wire(): ProcessWire
	{
		return Inertia::instance()->wire();
	}

	protected function configure(): void
	{
		$this
			->setName('inertia:types')
			->setDescription('Generate strongly-typed PHP Custom Page Classes (site/classes/*Page.php) and TypeScript definitions (inertia.d.ts) for Inertia templates.')
			->addOption('template', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Target template name(s) (comma-separated or repeated)')
			->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Custom output path for TypeScript definitions (inertia.d.ts)')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview generated classes and TypeScript output without writing to disk')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing PHP Custom Page Classes and template controllers')
			->addOption('ts-only', null, InputOption::VALUE_NONE, 'Generate only TypeScript (.d.ts) definitions')
			->addOption('php-only', null, InputOption::VALUE_NONE, 'Generate only PHP Custom Page Classes')
			->addOption('controllers', 'c', InputOption::VALUE_NONE, 'Also scaffold missing site/templates/{template}.php controllers')
			->addOption('json', null, InputOption::VALUE_NONE, 'Output results as structured JSON');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		Prompt::setOutput($output);

		$wire = $this->wire();
		$asJson = (bool) $input->getOption('json');
		if ((bool) $wire->config->demo) {
			return $this->failCommand($output, $asJson, 'DEMO_MODE', 'Write operations are disabled in demo mode.');
		}

		$inertia = Inertia::instance($wire);
		$typeGenerator = $inertia->typeGenerator();

		$force = (bool) $input->getOption('force');
		$dryRun = (bool) $input->getOption('dry-run');
		$tsOnly = (bool) $input->getOption('ts-only');
		$phpOnly = (bool) $input->getOption('php-only');
		$controllers = (bool) $input->getOption('controllers');

		if ($tsOnly && $phpOnly) {
			return $this->failCommand(
				$output,
				$asJson,
				'INVALID_OPTIONS',
				'Options --ts-only and --php-only cannot be combined.'
			);
		}

		$outputOpt = $input->getOption('output');
		$customOutput = null;
		if ($outputOpt !== null) {
			$trimmedOutput = is_string($outputOpt) ? trim($outputOpt) : '';
			if ($trimmedOutput === '' || !$typeGenerator->isValidCustomOutputPath($trimmedOutput)) {
				return $this->failCommand(
					$output,
					$asJson,
					'INVALID_OUTPUT_PATH',
					'Invalid --output path. Must be a non-symlinked .ts file confined inside site/ or the system temp directory.'
				);
			}
			$customOutput = $trimmedOutput;
		}

		$rawTemplates = $input->getOption('template');
		$explicitTemplates = null;
		if (is_array($rawTemplates) && $rawTemplates !== []) {
			$parsedNames = [];
			foreach ($rawTemplates as $rawEntry) {
				if (!is_scalar($rawEntry)) {
					continue;
				}
				foreach (explode(',', (string) $rawEntry) as $piece) {
					$parsedNames[] = trim($piece);
				}
			}

			$validatedNames = [];
			foreach ($parsedNames as $candidateName) {
				$validatedTpl = $typeGenerator->validateTargetTemplateName($candidateName, true);
				if (!$validatedTpl['ok']) {
					return $this->failCommand(
						$output,
						$asJson,
						'INVALID_TEMPLATE',
						$validatedTpl['error']
					);
				}

				$validatedNames[] = $validatedTpl['name'];
			}

			$explicitTemplates = array_values(array_unique($validatedNames));
		}

		$generatePhp = !$tsOnly;
		$generateTs = !$phpOnly;

		$result = $typeGenerator->generate(
			$explicitTemplates,
			$force,
			$generatePhp,
			$generateTs,
			$controllers,
			$customOutput,
			$dryRun
		);
		$result['dryRun'] = $dryRun;

		if ($asJson) {
			return $this->outputJsonSuccess($output, $result);
		}

		if ($result['templates'] === []) {
			warning('No Inertia templates resolved. Pass --template=<name> or enable templates in Modules -> Site -> Inertia.');
		}

		if ($result['phpClasses'] !== []) {
			$rows = [];
			foreach ($result['phpClasses'] as $item) {
				$rows[] = [
					$item['template'],
					$item['class'],
					$item['path'],
					strtoupper($item['status']),
				];
			}
			table(['Template', 'Page Class', 'Path', 'Status'], $rows);
		}

		if ($result['templateControllers'] !== []) {
			$rows = [];
			foreach ($result['templateControllers'] as $item) {
				$rows[] = [
					$item['template'],
					$item['path'],
					strtoupper($item['status']),
				];
			}
			table(['Template', 'Controller Path', 'Status'], $rows);
		}

		if (is_array($result['typescript'])) {
			$ts = $result['typescript'];
			$template = $dryRun
				? 'Dry-run preview: TypeScript definitions would be written to %s (%d bytes, %d interfaces).'
				: ($ts['written']
					? 'TypeScript definitions written to %s (%d bytes, %d interfaces).'
					: 'TypeScript definitions skipped for %s (%d bytes, %d interfaces).');
			info(sprintf(
				$template,
				$ts['path'],
				$ts['bytes'],
				count($ts['interfaces'])
			));
		}

		return Command::SUCCESS;
	}
}
