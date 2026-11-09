<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Props;

use Closure;
use Totoglu\Inertia\Request;

/**
 * Prop wrapper instructing the client router to append, prepend, or deep-merge incoming data
 * with existing client state instead of replacing it during partial reloads.
 */
class MergeProp implements PropInterface
{
	use ConfiguresMergeStrategy;
	use ConfiguresOnceStrategy;

	public function __construct(
		protected readonly mixed $value,
		MergeStrategy $strategy = MergeStrategy::Append
	) {
		$this->shouldMerge = true;
		$this->mergeStrategy = $strategy;
	}

	public function __invoke(?Request $request = null): mixed
	{
		return $this->value instanceof Closure ? ($this->value)() : $this->value;
	}
}
