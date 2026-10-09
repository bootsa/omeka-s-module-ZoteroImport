<?php
/**
 * Value builder for the uri data type. The value is the URI and the optional
 * payload "label" becomes the link text. A value that is not an absolute URI
 * is stored as a literal instead.
 */
return function (array $payload, array $context) {
    $value = trim((string) $payload['value']);
    if ('' === $value) {
        return null;
    }
    if (!preg_match('/^[a-z][a-z0-9+.\-]*:/i', $value)) {
        return ['type' => 'literal', '@value' => $value];
    }
    $built = ['type' => 'uri', '@id' => $value];
    if (isset($payload['label']) && '' !== (string) $payload['label']) {
        $built['o:label'] = (string) $payload['label'];
    }
    return $built;
};
