<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Props;

/**
 * Client-side merge strategy for `MergeProp` and `DeferredProp`.
 */
enum MergeStrategy: string
{
	case Append = 'append';
	case Prepend = 'prepend';
	case Deep = 'deep';
}
