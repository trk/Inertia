<?php // __(file-not-translatable)

declare(strict_types=1);

namespace ProcessWire;

/**
 * Ensure Inertia and its namespaces are loaded and wired for testing and static analysis.
 */
function bootInertiaForTesting(): Inertia
{
	$moduleRoot = dirname(__DIR__);
	require_once $moduleRoot . '/Inertia.module.php';

	$modules = wire('modules');
	$inertia = wire('inertia');
	if (!$inertia instanceof Inertia) {
		if ($modules instanceof Modules && $modules->isInstalled('Inertia')) {
			/** @var Inertia|null $installed */
			$installed = $modules->getModule('Inertia', ['noPermissionCheck' => true]);
			$inertia = $installed;
		}
		if (!$inertia instanceof Inertia) {
			$inertia = new Inertia();
			wire($inertia);
			$inertia->init();
		}
	}

	return $inertia;
}

bootInertiaForTesting();
