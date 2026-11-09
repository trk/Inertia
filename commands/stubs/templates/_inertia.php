<?php

declare(strict_types=1);

namespace ProcessWire;

if (!defined('PROCESSWIRE')) {
	die();
}

/**
 * Inertia.js v3 Root View Template (`_inertia.php`)
 *
 * Rendered only on the initial full-page browser load (or when SSR is active).
 * Subsequent client-side navigations receive pure JSON Page Objects directly.
 *
 * @var Inertia $inertia
 * @var Sanitizer $sanitizer
 * @var array<string, mixed> $pageObject
 * @var string|null $locale
 * @var string|null $head
 */
?>
<!DOCTYPE html>
<html lang="<?= $sanitizer->entities((string) ($locale ?? 'en')) ?>" class="h-full">

<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?= $head ?? '' ?>
	<?php
	if (function_exists(__NAMESPACE__ . '\vite')) {
		echo vite([
			'@assets/css/app.css',
			'@assets/js/inertia.ts',
		]);
	}
	?>
</head>

<body class="min-h-full antialiased">
	<?= $inertia->app($pageObject) ?>
</body>

</html>
