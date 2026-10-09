<?php
/**
 * Processor: add a prefix and/or suffix to the value, e.g. to turn a bare
 * Wikidata Q-number into a URI.
 *
 * Options:
 * - prefix (string)
 * - suffix (string)
 * - unlessMatches (regex, default: an absolute URI such as "https://...").
 *   If the value matches, it is left as it is.
 */
return function (array $payload, array $context, array $options) {
    $value = (string) $payload['value'];
    $unless = isset($options['unlessMatches']) ? $options['unlessMatches'] : '#^[a-z][a-z0-9+.\-]*://#i';
    if ('' !== $unless && 1 === @preg_match($unless, $value)) {
        return $payload;
    }
    $payload['value'] = (isset($options['prefix']) ? $options['prefix'] : '')
        . $value
        . (isset($options['suffix']) ? $options['suffix'] : '');
    return $payload;
};
