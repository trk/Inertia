<?php // __(file-not-translatable)

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 4);
if (is_file($projectRoot . '/index.php') && (!class_exists(\ProcessWire\ProcessWire::class, false) || !\ProcessWire\ProcessWire::getCurrentInstance())) {
	require_once $projectRoot . '/index.php';
}

require_once __DIR__ . '/bootstrap.php';
