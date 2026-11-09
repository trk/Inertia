<?php

declare(strict_types=1);

namespace Totoglu\Inertia;

use ProcessWire\Process;

/**
 * Base ProcessWire `Process` controller class with `InteractWithInertia` support
 * for full-page or hybrid Inertia admin modules.
 */
abstract class ProcessInertia extends Process
{
	use InteractWithInertia;
}
