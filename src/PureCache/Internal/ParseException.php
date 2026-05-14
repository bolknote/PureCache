<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * Hard parse failure surfaced by {@see LibmemcachedConfigFile}; caught at
 * the option boundary and translated into {@code RES_INVALID_ARGUMENTS}.
 */
final class ParseException extends \RuntimeException
{
}
