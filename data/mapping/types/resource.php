<?php
/**
 * Value builder for resource:item, resource:itemset and resource:media.
 * Needs payload "resource_id" (set by a processor such as resolve_resource);
 * values without one are skipped.
 */
return function (array $payload, array $context) {
    if (empty($payload['resource_id'])) {
        return null;
    }
    return ['type' => $payload['type'], 'value_resource_id' => (int) $payload['resource_id']];
};
