<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Support;

/**
 * Template activation mode (`Selected` for Hybrid mode, `All` for Full SPA mode).
 */
enum TemplateMode: string
{
	case Selected = 'selected';
	case All = 'all';
}
