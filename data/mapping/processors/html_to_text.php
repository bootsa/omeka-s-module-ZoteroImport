<?php
/**
 * Processor: convert HTML to plain text. Block elements and <br> become line
 * breaks, list items get a "- " prefix, tags are removed and entities decoded.
 * Values without markup or entities are left untouched.
 */
return function (array $payload, array $context, array $options) {
    $value = $payload['value'];
    if (!is_string($value) || !preg_match('/[<&]/', $value)) {
        return $payload;
    }
    $value = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $value);
    $value = preg_replace('#<br\s*/?>#i', "\n", $value);
    $value = preg_replace('#<li\b[^>]*>#i', "\n- ", $value);
    $value = preg_replace('#</(p|div|li|ul|ol|h[1-6]|blockquote|tr|table)>#i', "\n", $value);
    $value = strip_tags($value);
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace("\xC2\xA0", ' ', $value);
    $payload['value'] = $value;
    return $payload;
};
