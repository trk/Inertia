<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Commands;

use Laravel\Prompts\Prompt;
use ProcessWire\Inertia;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

final class InertiaTypesCommand extends Command
{
	protected function configure(): void
	{
		$this
			->setName('inertia:types')
			->setDescription('Generate strongly-typed PHP Custom Page Classes (site/classes/*Page.php) and TypeScript definitions (inertia.d.ts) for Inertia templates.')
			->addOption('template', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Target template name(s) (comma-separated or repeated)')
			->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Custom output path for TypeScript definitions (inertia.d.ts)')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing PHP Custom Page Classes and template controllers')
			->addOption('ts-only', null, InputOption::VALUE_NONE, 'Generate only TypeScript (.d.ts) definitions')
			->addOption('php-only', null, InputOption::VALUE_NONE, 'Generate only PHP Custom Page Classes')
			->addOption('controllers', 'c', InputOption::VALUE_NONE, 'Also scaffold missing site/templates/{template}.php controllers')
			->addOption('json', null, InputOption::VALUE_NONE, 'Output results as structured JSON');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		Prompt::setOutput($output);

		$inertia = Inertia::instance();
		$rawTemplates = $input->getOption('template');
		$templateNames = $inertia->normalizeTemplateList($rawTemplates);

		$force = (bool) $input->getOption('force');
		$tsOnly = (bool) $input->getOption('ts-only');
		$phpOnly = (bool) $input->getOption('php-only');
		$controllers = (bool) $input->getOption('controllers');
		$asJson = (bool) $input->getOption('json');
		$outputOpt = $input->getOption('output');
		$customOutput = is_string($outputOpt) && trim($outputOpt) !== '' ? trim($outputOpt) : null;

		$generatePhp = !$tsOnly;
		$generateTs = !$phpOnly;

		$result = $inertia->typeGenerator()->generate(
			$templateNames !== [] ? $templateNames : null,
			$force,
			$generatePhp,
			$generateTs,
			$controllers,
			$customOutput
		);

		if ($asJson) {
			$output->writeln((string) json_encode([
				'ok' => true,
				'data' => $result,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return Command::SUCCESS;
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
			info(sprintf(
				'TypeScript definitions written to %s (%d bytes, %d interfaces).',
				$ts['path'],
				$ts['bytes'],
				count($ts['interfaces'])
			));
		}

		return Command::SUCCESS;
	}
}
