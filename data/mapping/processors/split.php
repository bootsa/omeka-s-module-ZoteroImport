<?php
/**
 * Processor: split the value into several values.
 *
 * Options:
 * - separator (string, default ";")
 * - regex (bool, default false): treat separator as a regular expression
 */
return function (array $payload, array $context, array $options) {
    $separator = isset($options['separator']) ? (string) $options['separator'] : ';';
    if ('' === $separator) {
        return $payload;
    }
    $pieces = !empty($options['regex'])
        ? @preg_split($separator, (string) $payload['value'])
        : explode($separator, (string) $payload['value']);
    if (!is_array($pieces)) {
        return $payload;
    }
    $payloads = [];
    foreach ($pieces as $piece) {
        $piece = trim($piece);
        if ('' !== $piece) {
            $payloads[] = ['value' => $piece] + $payload;
        }
    }
    return $payloads;
};
