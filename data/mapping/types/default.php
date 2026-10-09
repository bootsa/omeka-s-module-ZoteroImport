<?php
/**
 * Value builder: turns a payload into an Omeka value (without property_id).
 * This default is used for literal and for any data type that has no builder
 * of its own, including types added by other modules that store their value
 * in @value (e.g. numeric:timestamp).
 *
 * Builders return a closure: function (array $payload, array $context)
 * returning an Omeka value array, or null to skip the value.
 */
return function (array $payload, array $context) {
    $value = $payload['value'];
    if (!is_scalar($value) || '' === trim((string) $value)) {
        return null;
    }
    $built = ['type' => $payload['type'], '@value' => (string) $value];
    if (!empty($payload['lang'])) {
        $built['@language'] = $payload['lang'];
    }
    return $built;
};
