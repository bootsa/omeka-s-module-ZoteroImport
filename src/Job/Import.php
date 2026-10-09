<?php
namespace ZoteroImport\Job;

use DateTime;
use Omeka\Job\AbstractJob;
use Omeka\Job\Exception;
use Laminas\Http\Client;
use Laminas\Http\Response;
use ZoteroImport\Zotero\Url;

class Import extends AbstractJob
{
    /**
     * Zotero API client
     *
     * @var Client
     */
    protected $client;

    /**
     * Zotero API URL
     *
     * @var Url
     */
    protected $url;

    /**
     * Vocabularies to cache: prefix => list of namespace URIs.
     *
     * Loaded from data/mapping/prefixes.json by loadVocabularies(). The
     * prefixes only have meaning inside the mapping files; vocabularies are
     * matched to the site by namespace URI, never by the site's own prefix.
     *
     * @var array
     */
    protected $vocabularies = [];

    /**
     * Built-in prefixes, used if data/mapping/prefixes.json is missing or
     * invalid.
     *
     * @var array
     */
    protected $defaultVocabularies = [
        'dcterms' => ['http://purl.org/dc/terms/'],
        'dctype' => ['http://purl.org/dc/dcmitype/'],
        'bibo' => ['http://purl.org/ontology/bibo/'],
        'foaf' => ['http://xmlns.com/foaf/0.1/'],
        'schema' => ['http://schema.org/', 'https://schema.org/'],
    ];

    /**
     * Cache of selected Omeka resource classes
     *
     * @var array
     */
    protected $resourceClasses = [];

    /**
     * Cache of selected Omeka properties
     *
     * @var array
     */
    protected $properties = [];

    /**
     * Cached per-item-type mappings keyed by Zotero item type.
     *
     * Each entry has the keys resourceClass (list of [prefix, localName]),
     * fields (Zotero field => list of [prefix, localName]) and creators
     * (Zotero creator type => list of [prefix, localName]). Each list is in
     * priority order: the first term that exists on the site is used.
     *
     * @var array
     */
    protected $itemTypeMappings = [];

    /**
     * Cached shared extra mapping (data/mapping/extra.json). Null until
     * first needed.
     *
     * @var array|null
     */
    protected $extraMapping;

    /**
     * Cached generic (legacy PHP) mappings, used when an item type has no
     * JSON mapping file. Null until first needed.
     *
     * @var array|null
     */
    protected $genericMapping;

    /**
     * Cached field defaults (data/mapping/field_defaults.json). Null until
     * first needed.
     *
     * @var array|null
     */
    protected $fieldDefaults;

    /**
     * Directories searched for processors/ and types/ include files. Null
     * until first needed.
     *
     * @var array|null
     */
    protected $includePaths;

    /**
     * Loaded include files, keyed "kind/name". A null value means the file
     * was looked for and not found.
     *
     * @var array
     */
    protected $includes = [];

    /**
     * Resolved value builders keyed by Omeka data type.
     *
     * @var array
     */
    protected $typeBuilders = [];

    /**
     * Resolved Omeka data type names keyed by the name used in a mapping.
     *
     * @var array
     */
    protected $dataTypes = [];

    /**
     * Perform the import.
     *
     * Accepts the following arguments:
     *
     * - itemSet:       The Omeka item set ID (int)
     * - import:        The Omeka Zotero import ID (int)
     * - type:          The Zotero library type (user, group)
     * - id:            The Zotero library ID (int)
     * - collectionKey: The Zotero collection key (string)
     * - apiKey:        The Zotero API key (string)
     * - importFiles:   Whether to import file attachments (bool)
     * - version:       The Zotero Last-Modified-Version of the last import (int)
     * - timestamp:     The Zotero dateAdded timestamp (UTC) to begin importing (int)
     *
     * Roughly follows Zotero's recommended steps for synchronizing a Zotero Web
     * API client with the Zotero server. But for the purposes of this job, a
     * "sync" only imports parent items (and their children) that have been
     * added to Zotero since the passed timestamp.
     *
     * @see https://www.zotero.org/support/dev/web_api/v3/syncing#full-library_syncing
     */
    public function perform()
    {
        // Raise the memory limit to accommodate very large imports.
        ini_set('memory_limit', '500M');

        $api = $this->getServiceLocator()->get('Omeka\ApiManager');

        $itemSet = $api->read('item_sets', $this->getArg('itemSet'))->getContent();

        $this->loadVocabularies();
        $this->cacheResourceClasses();
        $this->cacheProperties();
        $this->logMissingVocabularies();

        $this->setImportClient();
        $this->setImportUrl();

        $apiVersion = $this->getArg('version', 0);
        $apiKey = $this->getArg('apiKey');
        $collectionKey = $this->getArg('collectionKey');

        $params = [
            'since' => $apiVersion,
            'format' => 'versions',
            // Sort by ascending date added so items are imported roughly in the
            // same order. This way, if there is an error during an import,
            // users can estimate when to set the "Added after" field.
            'sort' => 'dateAdded',
            'direction' => 'asc',
            // Do not import notes.
            'itemType' => '-note',
        ];
        if ($collectionKey) {
            $url = $this->url->collectionItems($collectionKey, $params);
        } else {
            $url = $this->url->items($params);
        }
        $zItemKeys = array_keys(json_decode($this->getResponse($url)->getBody(), true));

        if (empty($zItemKeys)) {
            return;
        }

        // Cache all Zotero parent and child items.
        $zParentItems = [];
        $zChildItems = [];
        foreach (array_chunk($zItemKeys, 50, true) as $zItemKeysChunk) {
            if ($this->shouldStop()) {
                return;
            }
            $url = $this->url->items([
                'itemKey' => implode(',', $zItemKeysChunk),
                // Include the Zotero key so Zotero adds enclosure links to the
                // response. An attachment can only be downloaded if an
                // enclosure link is included.
                'key' => $apiKey,
            ]);
            $zItems = json_decode($this->getResponse($url)->getBody(), true);

            foreach ($zItems as $zItem) {
                $dateAdded = new DateTime($zItem['data']['dateAdded']);
                if ($dateAdded->getTimestamp() < $this->getArg('timestamp', 0)) {
                    // Only import items added since the passed timestamp. Note
                    // that the timezone must be UTC.
                    continue;
                }
                $zItem['oId'] = null;
                if ($this->getArg('matchingPoint', 1)) {
                    $importedItems = $api->search('zotero_import_items', ['zoteroKey' => $zItem['key']])->getContent();
                    if (!empty($importedItems)) {
                        $zItem['oId'] = reset($importedItems)->item()->id();
                    }
                }

                // Unset unneeded data to save memory.
                unset($zItem['library']);
                unset($zItem['version']);
                unset($zItem['meta']);
                unset($zItem['links']['self']);
                unset($zItem['links']['alternate']);

                if (isset($zItem['data']['parentItem'])) {
                    $zChildItems[$zItem['data']['parentItem']][] = $zItem;
                } else {
                    $zParentItems[$zItem['key']] = $zItem;
                }
            }
        }

        // Map Zotero items to Omeka items. Pass by reference so PHP doesn't
        // create a copy of the array, saving memory.
        $oItems = [];
        $oItemsToUpdate = [];
        foreach ($zParentItems as $zParentItemKey => &$zParentItem) {
            $oItem = [];
            $oItem['o:item_set'] = [['o:id' => $itemSet->id()]];
            $oItem = $this->mapResourceClass($zParentItem, $oItem);
            $oItem = $this->mapNameValues($zParentItem, $oItem);
            $oItem = $this->mapSubjectValues($zParentItem, $oItem);
            $oItem = $this->mapValues($zParentItem, $oItem);
            $oItem = $this->mapExtra($zParentItem, $oItem);
            $oItem = $this->mapAttachment($zParentItem, $oItem);
            if (isset($zChildItems[$zParentItemKey])) {
                foreach ($zChildItems[$zParentItemKey] as $zChildItem) {
                    $oItem = $this->mapAttachment($zChildItem, $oItem);
                }
            }
            if (!empty($zParentItem['oId'])) {
                $oItemsToUpdate[$zParentItem['oId']] = $oItem;
                $oItemsToUpdate[$zParentItem['oId']]['zKey'] = $zParentItemKey;
            } else {
                $oItems[$zParentItemKey] = $oItem;
            }
            // Unset unneeded data to save memory.
            unset($zParentItems[$zParentItemKey]);
        }
        // Break the reference left over from the foreach loop above.
        unset($zParentItem);

        // Batch create Omeka items.
        $importId = $this->getArg('import');
        foreach (array_chunk($oItems, 50, true) as $oItemsChunk) {
            if ($this->shouldStop()) {
                return;
            }
            $response = $api->batchCreate('items', $oItemsChunk, [], ['continueOnError' => true]);

            // Batch create Zotero import items.
            $importItems = [];
            foreach ($response->getContent() as $zKey => $item) {
                $importItems[] = [
                    'o:item' => ['o:id' => $item->id()],
                    'o-module-zotero_import:import' => ['o:id' => $importId],
                    'o-module-zotero_import:zotero_key' => $zKey,
                ];
            }
            // The ZoteroImportItem entity cascade detaches items, which saves
            // memory during batch create.
            $api->batchCreate('zotero_import_items', $importItems, [], ['continueOnError' => true]);
        }
        // Batch update Omeka items.
        $updatedImportItems = [];
        if (!empty($oItemsToUpdate)) {
            foreach ($oItemsToUpdate as $oId => $oItem) {
                if ($this->shouldStop()) {
                    return;
                }
                $api->update('items', $oId, $oItem, [], ['continueOnError' => true, 'collectionAction' => 'replace']);
                $updatedImportItems[] = [
                    'o:item' => ['o:id' => $oId],
                    'o-module-zotero_import:import' => ['o:id' => $importId],
                    'o-module-zotero_import:zotero_key' => $oItem['zKey'],
                ];
            }
            $api->batchCreate('zotero_import_items', $updatedImportItems, [], ['continueOnError' => true]);
        }

    }

    /**
     * Set the HTTP client to use during this import.
     */
    public function setImportClient()
    {
        $headers = ['Zotero-API-Version' => '3'];
        if ($apiKey = $this->getArg('apiKey')) {
            $headers['Authorization'] = sprintf('Bearer %s', $apiKey);
        }
        $this->client = $this->getServiceLocator()->get('Omeka\HttpClient')
            ->setHeaders($headers)
            // Decrease the chance of timeout by increasing to 20 seconds,
            // which splits the time between Omeka's default (10) and Zotero's
            // upper limit (30).
            ->setOptions(['timeout' => 20]);
    }

    /**
     * Set the Zotero URL object to use during this import.
     */
    public function setImportUrl()
    {
        $this->url = new Url($this->getArg('type'), $this->getArg('id'));
    }

    /**
     * Get a response from the Zotero API.
     *
     * @param string $url
     * @return Response
     */
    public function getResponse($url)
    {
        $response = $this->client->setUri($url)->send();
        if (!$response->isSuccess()) {
            throw new Exception\RuntimeException(sprintf(
                'Requested "%s" got "%s".', $url, $response->renderStatusLine()
            ));
        }
        return $response;
    }

    /**
     * Cache selected resource classes.
     */
    /**
     * Load the prefix => namespace URI definitions from the shared
     * data/mapping/prefixes.json, falling back to the built-in defaults.
     */
    protected function loadVocabularies()
    {
        $this->vocabularies = [];
        $data = $this->readJsonMapping($this->getMappingDir() . '/prefixes.json');
        if ($data && isset($data['prefixes']) && is_array($data['prefixes'])) {
            foreach ($data['prefixes'] as $prefix => $namespaceUris) {
                $namespaceUris = $this->getNamespaceUris($namespaceUris);
                if ($namespaceUris) {
                    $this->vocabularies[$prefix] = $namespaceUris;
                }
            }
        }
        if (!$this->vocabularies) {
            $this->getServiceLocator()->get('Omeka\Logger')->warn(
                'ZoteroImport: prefixes.json is missing or invalid; using built-in prefixes.'
            );
            $this->vocabularies = $this->defaultVocabularies;
        }
    }

    /**
     * Log the configured vocabularies that are not installed on this site.
     */
    protected function logMissingVocabularies()
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        foreach ($this->vocabularies as $prefix => $namespaceUris) {
            if (empty($this->resourceClasses[$prefix]) && empty($this->properties[$prefix])) {
                $logger->warn(sprintf(
                    'ZoteroImport: vocabulary "%s" (%s) is not installed on this site; mappings using it will be skipped.',
                    $prefix,
                    implode(', ', $namespaceUris)
                ));
            }
        }
    }

    /**
     * Get the namespace URIs for a vocabulary prefix as a list.
     *
     * Some vocabularies (e.g. schema.org) may be installed under more than
     * one namespace URI (http/https). The API only accepts a single URI per
     * search, so each one has to be searched separately.
     *
     * @param string|array $namespaceUris
     * @return array
     */
    protected function getNamespaceUris($namespaceUris)
    {
        return array_values(array_filter((array) $namespaceUris, 'strlen'));
    }

    /**
     * Cache the resource classes of the configured vocabularies.
     */
    public function cacheResourceClasses()
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        foreach ($this->vocabularies as $prefix => $namespaceUris) {
            foreach ($this->getNamespaceUris($namespaceUris) as $namespaceUri) {
                $classes = $api->search('resource_classes', [
                    'vocabulary_namespace_uri' => $namespaceUri,
                ])->getContent();
                foreach ($classes as $class) {
                    $this->resourceClasses[$prefix][$class->localName()] = $class;
                }
            }
        }
    }

    /**
     * Cache the properties of the configured vocabularies.
     */
    public function cacheProperties()
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        foreach ($this->vocabularies as $prefix => $namespaceUris) {
            foreach ($this->getNamespaceUris($namespaceUris) as $namespaceUri) {
                $properties = $api->search('properties', [
                    'vocabulary_namespace_uri' => $namespaceUri,
                ])->getContent();
                foreach ($properties as $property) {
                    $this->properties[$prefix][$property->localName()] = $property;
                }
            }
        }
    }

    /**
     * Get the directory containing the mapping files.
     *
     * @return string
     */
    protected function getMappingDir()
    {
        return dirname(dirname(__DIR__)) . '/data/mapping';
    }

    /**
     * Parse a "prefix:localName" term into a [prefix, localName] pair.
     *
     * @param mixed $term
     * @return array|null Null if the term is not valid
     */
    protected function parseTerm($term)
    {
        if (!is_string($term)) {
            return null;
        }
        $parts = explode(':', trim($term), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        return [$parts[0], $parts[1]];
    }

    /**
     * Parse a term, or a list of terms, into a list of [prefix, localName]
     * pairs, preserving priority order and dropping invalid entries.
     *
     * @param string|array|null $terms
     * @return array
     */
    protected function parseTermList($terms)
    {
        if (!is_array($terms)) {
            $terms = [$terms];
        }
        $pairs = [];
        foreach ($terms as $term) {
            $pair = $this->parseTerm($term);
            if ($pair) {
                $pairs[] = $pair;
            }
        }
        return $pairs;
    }

    /**
     * Parse a map of key => term(s) into key => list of pairs.
     *
     * @param mixed $map
     * @return array
     */
    protected function parseTermMap($map)
    {
        $parsed = [];
        if (!is_array($map)) {
            return $parsed;
        }
        foreach ($map as $key => $terms) {
            $pairs = $this->parseTermList($terms);
            if ($pairs) {
                $parsed[$key] = $pairs;
            }
        }
        return $parsed;
    }

    /**
     * Read a JSON mapping file.
     *
     * @param string $path
     * @return array|null Null if the file is unreadable or invalid
     */
    protected function readJsonMapping($path)
    {
        if (!is_readable($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }
        return $data;
    }

    /**
     * Read a legacy PHP mapping file (returns an array of key => term).
     *
     * @param string $name File name without extension
     * @return array
     */
    protected function readPhpMapping($name)
    {
        $path = $this->getMappingDir() . '/' . $name . '.php';
        if (!is_readable($path)) {
            return [];
        }
        $map = require $path;
        return is_array($map) ? $map : [];
    }

    /**
     * Get the mapping for a Zotero item type.
     *
     * Prefers data/mapping/item_types/{itemType}.json. If that does not exist
     * (or is invalid), falls back to the generic PHP mappings.
     *
     * @param array $zoteroItem
     * @return array Array with resourceClass, fields and creators
     */
    protected function getItemTypeMapping(array $zoteroItem)
    {
        $itemType = isset($zoteroItem['data']['itemType'])
            ? $zoteroItem['data']['itemType']
            : '';
        if (!isset($this->itemTypeMappings[$itemType])) {
            $this->itemTypeMappings[$itemType] = $this->loadItemTypeMapping($itemType);
        }
        return $this->itemTypeMappings[$itemType];
    }

    /**
     * Load and prepare the mapping for an item type.
     *
     * Tries item_types/{itemType}.json, then item_types/_default.json, then
     * the generic PHP mappings.
     *
     * @param string $itemType
     * @return array
     */
    protected function loadItemTypeMapping($itemType)
    {
        $dir = $this->getMappingDir() . '/item_types/';
        $files = [];
        // Zotero item types are camelCase identifiers (e.g. journalArticle).
        // This also guards against path traversal.
        if (is_string($itemType) && preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $itemType)) {
            $files[] = $itemType . '.json';
        }
        $files[] = '_default.json';
        foreach ($files as $file) {
            $data = $this->readJsonMapping($dir . $file);
            if ($data) {
                return $this->buildMapping($data, $file);
            }
        }
        return $this->getGenericMapping($itemType);
    }

    /**
     * Build a prepared mapping from decoded item type JSON.
     *
     * @param array $data
     * @param string $label Used in log messages
     * @return array
     */
    protected function buildMapping(array $data, $label)
    {
        $ownExtra = $this->parseExtraMapping(isset($data['extra']) ? $data['extra'] : []);
        $mapping = [
            'resourceClass' => $this->parseTermList(
                isset($data['resourceClass']) ? $data['resourceClass'] : []
            ),
            'fields' => $this->parseDefMap('fields', isset($data['fields']) ? $data['fields'] : []),
            'creators' => $this->parseDefMap('creators', isset($data['creators']) ? $data['creators'] : []),
            'extra' => $this->mergeExtraMapping($this->getExtraMapping(), $ownExtra),
        ];
        $pairLists = [$mapping['resourceClass']];
        foreach (['fields', 'creators'] as $section) {
            foreach ($mapping[$section] as $def) {
                $pairLists[] = $def['terms'];
            }
        }
        $this->logUnknownPrefixes($label, $pairLists, $ownExtra);
        return $mapping;
    }

    /**
     * Log prefixes that prefixes.json does not define.
     *
     * @param string $label Name of the mapping file
     * @param array $pairLists List of lists of [prefix, localName]
     * @param array|null $extra Prepared extra mapping, if any
     */
    protected function logUnknownPrefixes($label, array $pairLists, ?array $extra = null)
    {
        if ($extra) {
            foreach (['fields', 'creators'] as $section) {
                foreach ($extra[$section] as $def) {
                    $pairLists[] = $def['terms'];
                }
            }
            foreach ($extra['types'] as $pairs) {
                $pairLists[] = $pairs;
            }
        }
        $used = [];
        foreach ($pairLists as $pairs) {
            foreach ($pairs as $pair) {
                $used[$pair[0]] = true;
            }
        }
        $unknown = array_diff(array_keys($used), array_keys($this->vocabularies));
        if ($unknown) {
            $this->getServiceLocator()->get('Omeka\Logger')->warn(sprintf(
                'ZoteroImport: mapping "%s" uses prefixes not defined in prefixes.json (%s); those terms will be skipped.',
                $label,
                implode(', ', $unknown)
            ));
        }
    }

    /**
     * Normalize an Extra key or CSL type for matching: lowercase, with runs
     * of whitespace, underscores and hyphens collapsed to a single space
     * ("Original-Date", "original_date" and "Original Date" all match).
     *
     * @param string $key
     * @return string
     */
    protected function normalizeExtraKey($key)
    {
        return mb_strtolower(trim(preg_replace('/[\s_\-]+/u', ' ', (string) $key)));
    }

    /**
     * Prepare the "extra" section of a mapping file.
     *
     * Sections: fields and creators (Extra key => field definition, see
     * parseDefLayer()) and types (CSL type => resource class terms).
     *
     * @param mixed $data
     * @return array
     */
    protected function parseExtraMapping($data)
    {
        $extra = ['fields' => [], 'creators' => [], 'types' => []];
        if (!is_array($data)) {
            return $extra;
        }
        foreach (['fields', 'creators'] as $section) {
            if (isset($data[$section]) && is_array($data[$section])) {
                $extra[$section] = $this->parseDefMap('extra', $data[$section]);
            }
        }
        if (isset($data['types'])) {
            foreach ($this->parseTermMap($data['types']) as $key => $pairs) {
                $extra['types'][$this->normalizeExtraKey($key)] = $pairs;
            }
        }
        return $extra;
    }

    /**
     * Merge two prepared extra mappings; entries in $override win.
     *
     * @param array $base
     * @param array $override
     * @return array
     */
    protected function mergeExtraMapping(array $base, array $override)
    {
        return [
            'fields' => array_replace($base['fields'], $override['fields']),
            'creators' => array_replace($base['creators'], $override['creators']),
            'types' => array_replace($base['types'], $override['types']),
        ];
    }

    /**
     * Get the shared extra mapping from data/mapping/extra.json.
     *
     * @return array
     */
    protected function getExtraMapping()
    {
        if (null === $this->extraMapping) {
            $data = $this->readJsonMapping($this->getMappingDir() . '/extra.json');
            $this->extraMapping = $this->parseExtraMapping($data);
            $this->logUnknownPrefixes('extra.json', [], $this->extraMapping);
        }
        return $this->extraMapping;
    }

    /**
     * Split an Extra field into recognized "Key: Value" lines and the rest.
     *
     * Only lines whose key is in $knownKeys are extracted; everything else
     * (free text, URLs, unrecognized keys) is left in "remaining" so it can
     * still be mapped as the Extra note.
     *
     * @param string $extra
     * @param array $knownKeys Normalized keys
     * @return array ['recognized' => [[key, value], ...], 'remaining' => string]
     */
    protected function parseExtra($extra, array $knownKeys)
    {
        $recognized = [];
        $remaining = [];
        foreach (preg_split('/\R/u', (string) $extra) as $line) {
            if (preg_match('/^\s*([A-Za-z][A-Za-z _\-]*?)\s*:\s*(.+?)\s*$/u', $line, $m)) {
                $key = $this->normalizeExtraKey($m[1]);
                if (in_array($key, $knownKeys, true)) {
                    $recognized[] = [$key, $m[2]];
                    continue;
                }
            }
            $remaining[] = $line;
        }
        return ['recognized' => $recognized, 'remaining' => trim(implode("\n", $remaining))];
    }

    /**
     * Get the Extra keys a mapping recognizes.
     *
     * @param array $mapping
     * @return array
     */
    protected function getExtraKnownKeys(array $mapping)
    {
        return array_merge(
            array_keys($mapping['extra']['fields']),
            array_keys($mapping['extra']['creators']),
            ['type']
        );
    }

    /**
     * Build a mapping for an item type from the generic PHP mapping files.
     *
     * @param string $itemType
     * @return array
     */
    protected function getGenericMapping($itemType)
    {
        if (null === $this->genericMapping) {
            $this->genericMapping = [
                'classes' => $this->parseTermMap($this->readPhpMapping('item_type_map')),
                'fields' => $this->parseDefMap('fields', $this->readPhpMapping('item_field_map')),
                'creators' => $this->parseDefMap('creators', $this->readPhpMapping('creator_type_map')),
            ];
        }
        return [
            'resourceClass' => isset($this->genericMapping['classes'][$itemType])
                ? $this->genericMapping['classes'][$itemType]
                : [],
            'fields' => $this->genericMapping['fields'],
            'creators' => $this->genericMapping['creators'],
            'extra' => $this->getExtraMapping(),
        ];
    }

    /**
     * Whether an array is a list (sequential integer keys from 0).
     *
     * @param array $array
     * @return bool
     */
    protected function isList(array $array)
    {
        return !$array || array_keys($array) === range(0, count($array) - 1);
    }

    /**
     * Parse a pipeline into a list of stages, each ['name' => string,
     * 'options' => array]. A stage is a processor name, or an object with a
     * "name" key whose other keys are the processor's options.
     *
     * @param mixed $raw
     * @return array
     */
    protected function parseStages($raw)
    {
        if (is_string($raw) || (is_array($raw) && !$this->isList($raw))) {
            $raw = [$raw];
        }
        $stages = [];
        if (!is_array($raw)) {
            return $stages;
        }
        foreach ($raw as $stage) {
            if (is_string($stage) && '' !== $stage) {
                $stages[] = ['name' => $stage, 'options' => []];
            } elseif (is_array($stage) && isset($stage['name']) && is_string($stage['name'])) {
                $name = $stage['name'];
                unset($stage['name']);
                $stages[] = ['name' => $name, 'options' => $stage];
            }
        }
        return $stages;
    }

    /**
     * Parse one layer of a field definition.
     *
     * A layer is a term string, a list of terms (shorthand for {"terms": ...}),
     * or an object with any of: terms, type, language, pipeline. Only the keys
     * that are present are returned, so layers can be merged.
     *
     * @param mixed $raw
     * @return array
     */
    protected function parseDefLayer($raw)
    {
        if (is_string($raw) || (is_array($raw) && $this->isList($raw))) {
            $raw = ['terms' => $raw];
        }
        $layer = [];
        if (!is_array($raw)) {
            return $layer;
        }
        if (array_key_exists('terms', $raw)) {
            $layer['terms'] = $this->parseTermList($raw['terms']);
        }
        if (isset($raw['type']) && is_string($raw['type']) && '' !== $raw['type']) {
            $layer['type'] = $raw['type'];
        }
        if (isset($raw['language']) && is_string($raw['language'])) {
            $layer['lang'] = $raw['language'];
        }
        if (array_key_exists('pipeline', $raw)) {
            $layer['pipeline'] = $this->parseStages($raw['pipeline']);
        }
        return $layer;
    }

    /**
     * Merge a definition layer over a base definition. A pipeline replaces
     * the inherited one, except that a "@default" stage is replaced by the
     * inherited stages.
     *
     * @param array $base
     * @param array $layer
     * @return array
     */
    protected function mergeDefLayer(array $base, array $layer)
    {
        foreach (['terms', 'type', 'lang'] as $key) {
            if (array_key_exists($key, $layer)) {
                $base[$key] = $layer[$key];
            }
        }
        if (array_key_exists('pipeline', $layer)) {
            $pipeline = [];
            foreach ($layer['pipeline'] as $stage) {
                if ('@default' === $stage['name']) {
                    $pipeline = array_merge($pipeline, $base['pipeline']);
                } else {
                    $pipeline[] = $stage;
                }
            }
            $base['pipeline'] = $pipeline;
        }
        return $base;
    }

    /**
     * Get the field defaults from data/mapping/field_defaults.json.
     *
     * Result: ['defaults' => layer, 'sections' => [section => [key => layer]]]
     * where section is fields, creators or extra, and the key "*" applies to
     * the whole section.
     *
     * @return array
     */
    protected function getFieldDefaults()
    {
        if (null === $this->fieldDefaults) {
            $data = $this->readJsonMapping($this->getMappingDir() . '/field_defaults.json');
            $data = $data ? $data : [];
            $defaults = $this->parseDefLayer(isset($data['defaults']) ? $data['defaults'] : []);
            unset($defaults['terms']);
            $sections = [];
            foreach (['fields', 'creators', 'extra'] as $section) {
                $sections[$section] = [];
                if (!isset($data[$section]) || !is_array($data[$section])) {
                    continue;
                }
                foreach ($data[$section] as $key => $raw) {
                    if ('*' !== $key && 'extra' === $section) {
                        $key = $this->normalizeExtraKey($key);
                    }
                    $layer = $this->parseDefLayer($raw);
                    unset($layer['terms']);
                    $sections[$section][$key] = $layer;
                }
            }
            $this->fieldDefaults = ['defaults' => $defaults, 'sections' => $sections];
        }
        return $this->fieldDefaults;
    }

    /**
     * Resolve the final definition of a field: built-in base, then the
     * global defaults, the section default, the field's own default and
     * finally the item type mapping's layer.
     *
     * @param string $section fields, creators or extra
     * @param string $key
     * @param array $itemLayer
     * @return array ['terms', 'type', 'lang', 'pipeline']
     */
    protected function resolveFieldDef($section, $key, array $itemLayer)
    {
        $defaults = $this->getFieldDefaults();
        $def = ['terms' => [], 'type' => 'literal', 'lang' => null, 'pipeline' => []];
        $layers = [
            $defaults['defaults'],
            isset($defaults['sections'][$section]['*']) ? $defaults['sections'][$section]['*'] : [],
            isset($defaults['sections'][$section][$key]) ? $defaults['sections'][$section][$key] : [],
            $itemLayer,
        ];
        foreach ($layers as $layer) {
            $def = $this->mergeDefLayer($def, $layer);
        }
        return $def;
    }

    /**
     * Parse a map of key => field definition, dropping fields with no terms.
     *
     * @param string $section fields, creators or extra
     * @param mixed $map
     * @return array key => resolved definition
     */
    protected function parseDefMap($section, $map)
    {
        $parsed = [];
        if (!is_array($map)) {
            return $parsed;
        }
        foreach ($map as $key => $raw) {
            if ('extra' === $section) {
                $key = $this->normalizeExtraKey($key);
            }
            $def = $this->resolveFieldDef($section, $key, $this->parseDefLayer($raw));
            if ($def['terms']) {
                $parsed[$key] = $def;
            }
        }
        return $parsed;
    }

    /**
     * Include a PHP file and return what it returns. Static so that a
     * closure defined in the file is not bound to the job object.
     *
     * @param string $file
     * @return mixed
     */
    private static function includeFile($file)
    {
        return require $file;
    }

    /**
     * Get the directories searched for processors/ and types/ include files:
     * data/mapping first, then any registered by other modules in their
     * module config under zotero_import => include_paths.
     *
     * @return array
     */
    protected function getIncludePaths()
    {
        if (null === $this->includePaths) {
            $this->includePaths = [$this->getMappingDir()];
            $config = $this->getServiceLocator()->get('Config');
            if (!empty($config['zotero_import']['include_paths']) && is_array($config['zotero_import']['include_paths'])) {
                foreach ($config['zotero_import']['include_paths'] as $path) {
                    if (is_string($path) && '' !== $path) {
                        $this->includePaths[] = rtrim($path, '/\\');
                    }
                }
            }
        }
        return $this->includePaths;
    }

    /**
     * Load an include file ($kind/$name.php) that returns a callable.
     *
     * @param string $kind processors or types
     * @param string $name
     * @param bool $required Log a warning if not found
     * @return callable|null
     */
    protected function getInclude($kind, $name, $required = true)
    {
        $key = $kind . '/' . $name;
        if (array_key_exists($key, $this->includes)) {
            return $this->includes[$key];
        }
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $callable = null;
        if (is_string($name) && preg_match('/^[A-Za-z0-9_\-]+$/', $name)) {
            foreach ($this->getIncludePaths() as $base) {
                $file = $base . '/' . $kind . '/' . $name . '.php';
                if (!is_readable($file)) {
                    continue;
                }
                $result = self::includeFile($file);
                if (is_callable($result)) {
                    $callable = $result;
                    break;
                }
                $logger->warn(sprintf('ZoteroImport: %s did not return a callable.', $file));
            }
        }
        if (!$callable && $required) {
            $logger->warn(sprintf('ZoteroImport: %s "%s" was not found in any include path.', $kind, $name));
        }
        return $this->includes[$key] = $callable;
    }

    /**
     * Get the value builder for an Omeka data type. Looks for types/ files
     * named after the type (":" becomes "_"), then after its family (the part
     * before the first ":"), then types/default.php.
     *
     * @param string $type
     * @return callable|null
     */
    protected function getTypeBuilder($type)
    {
        if (!array_key_exists($type, $this->typeBuilders)) {
            $names = [preg_replace('/[^A-Za-z0-9_\-]+/', '_', $type)];
            $pos = strpos($type, ':');
            if (false !== $pos) {
                $names[] = preg_replace('/[^A-Za-z0-9_\-]+/', '_', substr($type, 0, $pos));
            }
            $builder = null;
            foreach ($names as $name) {
                $builder = $this->getInclude('types', $name, false);
                if ($builder) {
                    break;
                }
            }
            if (!$builder) {
                $builder = $this->getInclude('types', 'default');
            }
            $this->typeBuilders[$type] = $builder;
        }
        return $this->typeBuilders[$type];
    }

    /**
     * Resolve the data type named in a mapping to a data type registered in
     * Omeka. Accepts the aliases text, uri, resource, item, itemset and
     * media; any other name (e.g. one added by another module) is used as is.
     * Unregistered types fall back to literal, with a warning.
     *
     * @param string $type
     * @return string
     */
    protected function resolveDataType($type)
    {
        if (!isset($this->dataTypes[$type])) {
            $aliases = [
                'text' => 'literal',
                'resource' => 'resource:item',
                'item' => 'resource:item',
                'itemset' => 'resource:itemset',
                'item_set' => 'resource:itemset',
                'media' => 'resource:media',
            ];
            $resolved = isset($aliases[$type]) ? $aliases[$type] : $type;
            $manager = $this->getServiceLocator()->get('Omeka\DataTypeManager');
            if (!$manager->has($resolved)) {
                $this->getServiceLocator()->get('Omeka\Logger')->warn(sprintf(
                    'ZoteroImport: data type "%s" is not registered on this site; importing those values as literal.',
                    $resolved
                ));
                $resolved = 'literal';
            }
            $this->dataTypes[$type] = $resolved;
        }
        return $this->dataTypes[$type];
    }

    /**
     * Build the context passed to processors and value builders.
     *
     * @param array $zoteroItem
     * @param string $source field, creator or extra
     * @param string $field The Zotero field, creator type or Extra key
     * @return array
     */
    protected function getPipelineContext(array $zoteroItem, $source, $field)
    {
        $services = $this->getServiceLocator();
        return [
            'zoteroItem' => $zoteroItem,
            'source' => $source,
            'field' => $field,
            'api' => $services->get('Omeka\ApiManager'),
            'services' => $services,
        ];
    }

    /**
     * Run a value payload through a pipeline of processors.
     *
     * A processor is a closure returned by processors/{name}.php:
     * function (array $payload, array $context, array $options). It returns
     * the payload (modified), null/false to drop the value, or a list of
     * payloads to split it into several values.
     *
     * @param array $stages
     * @param array $payload
     * @param array $context
     * @return array List of payloads
     */
    protected function runPipeline(array $stages, array $payload, array $context)
    {
        $payloads = [$payload];
        foreach ($stages as $stage) {
            $processor = $this->getInclude('processors', $stage['name']);
            if (!$processor) {
                continue;
            }
            $next = [];
            foreach ($payloads as $current) {
                try {
                    $result = $processor($current, $context, $stage['options']);
                } catch (\Throwable $e) {
                    $this->getServiceLocator()->get('Omeka\Logger')->warn(sprintf(
                        'ZoteroImport: processor "%s" failed on "%s": %s',
                        $stage['name'],
                        $context['field'],
                        $e->getMessage()
                    ));
                    $result = $current;
                }
                if (!is_array($result)) {
                    continue;
                }
                if (array_key_exists('value', $result)) {
                    $result = [$result];
                }
                foreach ($result as $item) {
                    if (is_array($item) && array_key_exists('value', $item)) {
                        $next[] = $item;
                    }
                }
            }
            $payloads = $next;
            if (!$payloads) {
                break;
            }
        }
        return $payloads;
    }

    /**
     * Add a value to an Omeka item: pick the first mapped property that
     * exists on the site, run the pipeline, then build the Omeka value for
     * the resulting data type.
     *
     * @param array $omekaItem
     * @param array $def Resolved field definition
     * @param mixed $value Raw Zotero value
     * @param array $context
     * @return array The Omeka item
     */
    protected function addMappedValue(array $omekaItem, array $def, $value, array $context)
    {
        $found = $this->findProperty($def['terms']);
        if (!$found) {
            return $omekaItem;
        }
        $property = $found[0];
        $payload = [
            'value' => is_string($value) ? $value : (string) $value,
            'type' => $this->resolveDataType($def['type']),
            'property' => $property->term(),
            'lang' => $def['lang'],
            'label' => null,
            'resource_id' => null,
        ];
        foreach ($this->runPipeline($def['pipeline'], $payload, $context) as $result) {
            $result['type'] = $this->resolveDataType(
                isset($result['type']) && is_string($result['type']) ? $result['type'] : 'literal'
            );
            $builder = $this->getTypeBuilder($result['type']);
            $built = $builder ? $builder($result, $context) : null;
            if (!is_array($built)) {
                continue;
            }
            $built['property_id'] = $property->id();
            $omekaItem[$property->term()][] = $built;
        }
        return $omekaItem;
    }

    /**
     * Find the first property in a priority list that exists on this site.
     *
     * @param array $pairs List of [prefix, localName]
     * @return array|null [property, "prefix:localName"] or null
     */
    protected function findProperty(array $pairs)
    {
        foreach ($pairs as $pair) {
            list($prefix, $localName) = $pair;
            if (isset($this->properties[$prefix][$localName])) {
                return [$this->properties[$prefix][$localName], $prefix . ':' . $localName];
            }
        }
        return null;
    }

    /**
     * Find the first resource class in a priority list that exists on this site.
     *
     * @param array $pairs List of [prefix, localName]
     * @return \Omeka\Api\Representation\ResourceClassRepresentation|null
     */
    protected function findResourceClass(array $pairs)
    {
        foreach ($pairs as $pair) {
            list($prefix, $localName) = $pair;
            if (isset($this->resourceClasses[$prefix][$localName])) {
                return $this->resourceClasses[$prefix][$localName];
            }
        }
        return null;
    }

    /**
     * Map Zotero item type to Omeka resource class.
     *
     * @param array $zoteroItem The Zotero item data
     * @param array $omekaItem The Omeka item data
     * @return array
     */
    public function mapResourceClass(array $zoteroItem, array $omekaItem)
    {
        if (!isset($zoteroItem['data']['itemType'])) {
            return $omekaItem;
        }
        $mapping = $this->getItemTypeMapping($zoteroItem);
        $class = $this->findResourceClass($mapping['resourceClass']);
        if ($class) {
            $omekaItem['o:resource_class'] = ['o:id' => $class->id()];
        }
        return $omekaItem;
    }

    /**
     * Map Zotero item data to Omeka item values.
     *
     * @param array $zoteroItem The Zotero item data
     * @param array $omekaItem The Omeka item data
     * @return array
     */
    public function mapValues(array $zoteroItem, array $omekaItem)
    {
        if (!isset($zoteroItem['data'])) {
            return $omekaItem;
        }
        $mapping = $this->getItemTypeMapping($zoteroItem);
        $fieldMap = $mapping['fields'];
        $data = $zoteroItem['data'];
        // Lines extracted by mapExtra() are not repeated in the Extra note.
        if (!empty($data['extra']) && is_string($data['extra'])) {
            $data['extra'] = $this->parseExtra($data['extra'], $this->getExtraKnownKeys($mapping))['remaining'];
        }
        foreach ($data as $key => $value) {
            if (!$value || !is_scalar($value) || !isset($fieldMap[$key])) {
                continue;
            }
            $omekaItem = $this->addMappedValue(
                $omekaItem,
                $fieldMap[$key],
                $value,
                $this->getPipelineContext($zoteroItem, 'field', $key)
            );
        }
        return $omekaItem;
    }

    /**
     * Map Zotero creator names to the Omeka item values.
     *
     * @param array $zoteroItem The Zotero item data
     * @param array $omekaItem The Omeka item data
     * @return array
     */
    public function mapNameValues(array $zoteroItem, array $omekaItem)
    {
        if (!isset($zoteroItem['data']['creators'])) {
            return $omekaItem;
        }
        $creatorMap = $this->getItemTypeMapping($zoteroItem)['creators'];
        foreach ($zoteroItem['data']['creators'] as $creator) {
            $creatorType = isset($creator['creatorType']) ? $creator['creatorType'] : null;
            if (!$creatorType || !isset($creatorMap[$creatorType])) {
                continue;
            }
            $name = [];
            foreach (['name', 'firstName', 'lastName'] as $part) {
                if (isset($creator[$part]) && '' !== $creator[$part]) {
                    $name[] = $creator[$part];
                }
            }
            if (!$name) {
                continue;
            }
            $omekaItem = $this->addMappedValue(
                $omekaItem,
                $creatorMap[$creatorType],
                implode(' ', $name),
                $this->getPipelineContext($zoteroItem, 'creator', $creatorType)
            );
        }
        return $omekaItem;
    }

    /**
     * Map identifiers, dates, names and the item class held in the Extra
     * field ("Key: Value" lines, as used by Zotero, CSL and Zotero-Cita).
     *
     * Following Zotero's rules, values in Extra do not override a value that
     * is already in the matching Zotero field; "Type:" overrides the class.
     *
     * @param array $zoteroItem The Zotero item data
     * @param array $omekaItem The Omeka item data
     * @return array
     */
    public function mapExtra(array $zoteroItem, array $omekaItem)
    {
        if (empty($zoteroItem['data']['extra']) || !is_string($zoteroItem['data']['extra'])) {
            return $omekaItem;
        }
        $mapping = $this->getItemTypeMapping($zoteroItem);
        $extra = $mapping['extra'];
        $parsed = $this->parseExtra($zoteroItem['data']['extra'], $this->getExtraKnownKeys($mapping));
        if (!$parsed['recognized']) {
            return $omekaItem;
        }

        // Zotero fields that already hold a value (normalized names).
        $present = [];
        foreach ($zoteroItem['data'] as $key => $value) {
            if (is_scalar($value) && '' !== (string) $value) {
                $present[$this->normalizeExtraKey(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $key))] = true;
            }
        }

        foreach ($parsed['recognized'] as $entry) {
            list($key, $value) = $entry;

            if ('type' === $key) {
                $typeKey = $this->normalizeExtraKey($value);
                if (isset($extra['types'][$typeKey])) {
                    $class = $this->findResourceClass($extra['types'][$typeKey]);
                    if ($class) {
                        $omekaItem['o:resource_class'] = ['o:id' => $class->id()];
                    }
                }
                continue;
            }

            if (isset($extra['creators'][$key])) {
                // "Last || First" => "First Last"
                $parts = array_values(array_filter(array_map('trim', explode('||', $value)), 'strlen'));
                $omekaItem = $this->addMappedValue(
                    $omekaItem,
                    $extra['creators'][$key],
                    implode(' ', array_reverse($parts)),
                    $this->getPipelineContext($zoteroItem, 'extra', $key)
                );
                continue;
            }

            if (isset($extra['fields'][$key]) && !isset($present[$key])) {
                $omekaItem = $this->addMappedValue(
                    $omekaItem,
                    $extra['fields'][$key],
                    $value,
                    $this->getPipelineContext($zoteroItem, 'extra', $key)
                );
            }
        }
        return $omekaItem;
    }

    /**
     * Map Zotero tags to Omeka item values (dcterms:subject).
     *
     * @param array $zoteroItem The Zotero item data
     * @param array $omekaItem The Omeka item data
     * @return array
     */
    public function mapSubjectValues(array $zoteroItem, array $omekaItem)
    {
        if (!isset($zoteroItem['data']['tags'])) {
            return $omekaItem;
        }
        $tags = $zoteroItem['data']['tags'];
        foreach ($tags as $tag) {
            $property = $this->properties['dcterms']['subject'];
            $omekaItem[$property->term()][] = [
                '@value' => $tag['tag'],
                'property_id' => $property->id(),
                'type' => 'literal',
            ];
        }
        return $omekaItem;
    }

    /**
     * Map an attachment.
     *
     * There are four kinds of Zotero attachments: imported_url, imported_file,
     * linked_url, and linked_file. Only imported_url and imported_file have
     * files, and only when the response includes an enclosure link. For
     * linked_url, the @id URL was already mapped in mapValues(). For
     * linked_file, there is nothing to save.
     *
     * @param array $zoteroItem The Zotero item data
     * @param array $omekaItem The Omeka item data
     * @return string
     */
    public function mapAttachment($zoteroItem, $omekaItem)
    {
        if ('attachment' === $zoteroItem['data']['itemType']
            && isset($zoteroItem['links']['enclosure'])
            && $this->getArg('importFiles')
            && $this->getArg('apiKey')
        ) {
            $property = $this->properties['dcterms']['title'];
            $omekaItem['o:media'][] = [
                'o:ingester' => 'url',
                'o:source' => $this->url->itemFile($zoteroItem['key']),
                'ingest_url' => $this->url->itemFile(
                    $zoteroItem['key'],
                    ['key' => $this->getArg('apiKey')]
                ),
                $property->term() => [
                    [
                        '@value' => $zoteroItem['data']['title'],
                        'property_id' => $property->id(),
                        'type' => 'literal',
                    ],
                ],
            ];
        }
        return $omekaItem;
    }

    /**
     * Get a URL from the Link header.
     *
     * @param Response $response
     * @param string $rel The relationship from the current document. Possible
     * values are first, prev, next, last, alternate.
     * @return string|null
     */
    public function getLink(Response $response, $rel)
    {
        $linkHeader = $response->getHeaders()->get('Link');
        if (!$linkHeader) {
            return null;
        }
        preg_match_all(
            '/<([^>]+)>; rel="([^"]+)"/',
            $linkHeader->getFieldValue(),
            $matches
        );
        if (!$matches) {
            return null;
        }
        $key = array_search($rel, $matches[2]);
        if (false === $key) {
            return null;
        }
        return $matches[1][$key];
    }
}
