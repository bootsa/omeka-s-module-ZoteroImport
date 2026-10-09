<?php
/**
 * Processor: turn the value into a reference to an existing Omeka resource,
 * found by matching the value against a property of the resource. Use with
 * "type": "resource:item" (or resource:itemset / resource:media).
 *
 * Options:
 * - property (string, default "dcterms:title"): term to match against
 * - resource (string, default "items"): items, item_sets or media
 * - onMissing (string, default "literal"): what to do if nothing matches:
 *   "literal" keeps the text as a literal value, "drop" discards it
 */
return function (array $payload, array $context, array $options) {
    $property = isset($options['property']) ? $options['property'] : 'dcterms:title';
    $resource = isset($options['resource']) ? $options['resource'] : 'items';
    $types = ['items' => 'resource:item', 'item_sets' => 'resource:itemset', 'media' => 'resource:media'];
    if (!isset($types[$resource])) {
        return $payload;
    }
    $text = trim((string) $payload['value']);
    $ids = [];
    if ('' !== $text) {
        $ids = $context['api']->search($resource, [
            'property' => [['property' => $property, 'type' => 'eq', 'text' => $text]],
            'limit' => 1,
        ], ['returnScalar' => 'id'])->getContent();
    }
    if ($ids) {
        $payload['type'] = $types[$resource];
        $payload['resource_id'] = (int) reset($ids);
        return $payload;
    }
    if (isset($options['onMissing']) && 'drop' === $options['onMissing']) {
        return null;
    }
    $payload['type'] = 'literal';
    return $payload;
};
