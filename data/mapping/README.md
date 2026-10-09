# Zotero to Omeka S mappings

```
data/mapping/
  prefixes.json         prefix -> namespace URI(s) used by every mapping file
  field_defaults.json   base settings (type, language, pipeline) for every field
  extra.json            mappings for "Key: Value" lines in Zotero's Extra field
  item_types/           one file per Zotero item type (+ optional _default.json)
  processors/           processor include files (pipeline stages)
  types/                value builder include files (one per Omeka data type)
```

## Field entries (item type files and extra.json)

A field can be written three ways:

```json
"title":    "dcterms:title",
"date":     ["dcterms:date", "schema:datePublished"],
"abstractNote": {
  "terms": ["dcterms:abstract", "bibo:abstract"],
  "type": "literal",
  "language": "en",
  "pipeline": ["@default", "html_to_text"]
}
```

A string or list uses the default type and pipeline from `field_defaults.json`.
The first term that exists on the site is used. In the object form every key
except `terms` is optional and overrides the default.

* `type`: an Omeka data type: `literal`, `uri`, `resource:item`,
  `resource:itemset`, `resource:media`, or one added by another module
  (e.g. `numeric:timestamp`). Aliases: `text`, `resource`, `item`, `itemset`,
  `media`. Unregistered types are imported as literal and logged.
* `language`: sets `@language` on literal values.
* `pipeline`: processors run in order. A stage is a name or
  `{"name": "split", "separator": ","}`. A pipeline replaces the inherited
  one; the stage `"@default"` stands for the inherited stages. `[]` means no
  processing.

Defaults are layered: `defaults`, then the section's `*`, then the field's own
entry in `field_defaults.json`, then the item type file.

## Payload passed through a pipeline

```php
[
  'value'       => 'Text or URI',   // working value
  'type'        => 'literal',       // Omeka data type
  'property'    => 'dcterms:title', // term the value will be stored on
  'lang'        => null,            // @language
  'label'       => null,            // o:label for uri values
  'resource_id' => null,            // for resource:* values
]
```

Context (second argument): `zoteroItem`, `source` (field, creator or extra),
`field`, `api` (Omeka API manager), `services`.

A processor (`processors/{name}.php`) returns a closure
`function (array $payload, array $context, array $options)` that returns the
payload, `null` to drop the value, or a list of payloads to split it.

A value builder (`types/{type}.php`) returns
`function (array $payload, array $context)` giving the Omeka value (without
`property_id`) or `null`. Lookup order: `resource_item.php` (":" -> "_"),
`resource.php`, then `default.php`.

## Adding processors or types from another module

In that module's `config/module.config.php`:

```php
'zotero_import' => [
    'include_paths' => [
        __DIR__ . '/../data/zotero_import', // contains processors/ and/or types/
    ],
],
```

`data/mapping` is searched first, then the registered paths in order.
