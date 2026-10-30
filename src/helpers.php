<?php

declare(strict_types=1);

/** Escape a value for HTML text or attribute output. */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
