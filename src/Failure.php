<?php

declare(strict_types=1);

namespace Softpack;

/**
 * An error worth showing the user as a plain message rather than a stack trace.
 */
final class Failure extends \RuntimeException
{
}
