<?php
/**
 * Processor: trim whitespace (including non-breaking spaces) from the value.
 * An empty result drops the value.
 *
 * Processors return a closure: function (array $payload, array $context, array $options)
 * returning the payload, null to drop the value, or a list of payloads.
 */
return function (array $payload, array $context, array $options) {
    if (!is_string($payload['value'])) {
        return $payload;
    }
    $value = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $payload['value']);
    if (null === $value) {
        return $payload; // invalid UTF-8: leave untouched
    }
    if ('' === $value) {
        return null;
    }
    $payload['value'] = $value;
    return $payload;
};
