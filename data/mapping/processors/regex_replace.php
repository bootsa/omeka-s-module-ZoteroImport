<?php
/**
 * Processor: regular expression replacement.
 *
 * Options:
 * - pattern (string, required): full PCRE pattern including delimiters
 * - replacement (string, default "")
 */
return function (array $payload, array $context, array $options) {
    if (empty($options['pattern'])) {
        return $payload;
    }
    $result = @preg_replace(
        $options['pattern'],
        isset($options['replacement']) ? $options['replacement'] : '',
        (string) $payload['value']
    );
    if (null !== $result) {
        $payload['value'] = $result;
    }
    return $payload;
};
