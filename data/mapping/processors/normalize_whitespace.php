<?php
/**
 * Processor: collapse runs of whitespace.
 *
 * Options:
 * - keepNewlines (bool, default true): keep line breaks (at most one blank
 *   line in a row). If false, everything becomes a single line.
 */
return function (array $payload, array $context, array $options) {
    if (!is_string($payload['value'])) {
        return $payload;
    }
    $value = str_replace(["\r\n", "\r"], "\n", $payload['value']);
    $keepNewlines = !isset($options['keepNewlines']) || $options['keepNewlines'];
    if ($keepNewlines) {
        $value = preg_replace('/[^\S\n]+/u', ' ', $value);
        $value = preg_replace('/ ?\n ?/', "\n", $value);
        $value = preg_replace('/\n{3,}/', "\n\n", $value);
    } else {
        $value = preg_replace('/\s+/u', ' ', $value);
    }
    if (null === $value) {
        return $payload; // invalid UTF-8: leave untouched
    }
    $value = trim($value);
    if ('' === $value) {
        return null;
    }
    $payload['value'] = $value;
    return $payload;
};
