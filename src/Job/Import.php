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
     * Cached generic (legacy PHP) mappings, used when an item type has no
     * JSON mapping file. Null until first needed.
     *
     * @var array|null
     */
    protected $genericMapping;

    /**
     * Terms (prefix:localName) whose values are stored as URI values rather
     * than literals.
     *
     * @var array
     */
    protected $uriTerms = ['bibo:uri', 'schema:url'];

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
     * @param string $itemType
     * @return array
     */
    protected function loadItemTypeMapping($itemType)
    {
        // Zotero item types are camelCase identifiers (e.g. journalArticle).
        // This also guards against path traversal.
        if (is_string($itemType) && preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $itemType)) {
            $data = $this->readJsonMapping(
                $this->getMappingDir() . '/item_types/' . $itemType . '.json'
            );
            if ($data) {
                $mapping = [
                    'resourceClass' => $this->parseTermList(
                        isset($data['resourceClass']) ? $data['resourceClass'] : []
                    ),
                    'fields' => $this->parseTermMap(
                        isset($data['fields']) ? $data['fields'] : []
                    ),
                    'creators' => $this->parseTermMap(
                        isset($data['creators']) ? $data['creators'] : []
                    ),
                ];
                $this->logUnknownPrefixes($itemType, $mapping);
                return $mapping;
            }
        }
        return $this->getGenericMapping($itemType);
    }

    /**
     * Log prefixes used in an item type mapping that prefixes.json does not define.
     *
     * @param string $itemType
     * @param array $mapping
     */
    protected function logUnknownPrefixes($itemType, array $mapping)
    {
        $used = [];
        foreach ($mapping['resourceClass'] as $pair) {
            $used[$pair[0]] = true;
        }
        foreach (['fields', 'creators'] as $section) {
            foreach ($mapping[$section] as $pairs) {
                foreach ($pairs as $pair) {
                    $used[$pair[0]] = true;
                }
            }
        }
        $unknown = array_diff(array_keys($used), array_keys($this->vocabularies));
        if ($unknown) {
            $this->getServiceLocator()->get('Omeka\Logger')->warn(sprintf(
                'ZoteroImport: mapping for item type "%s" uses prefixes not defined in prefixes.json (%s); those terms will be skipped.',
                $itemType,
                implode(', ', $unknown)
            ));
        }
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
                'fields' => $this->parseTermMap($this->readPhpMapping('item_field_map')),
                'creators' => $this->parseTermMap($this->readPhpMapping('creator_type_map')),
            ];
        }
        return [
            'resourceClass' => isset($this->genericMapping['classes'][$itemType])
                ? $this->genericMapping['classes'][$itemType]
                : [],
            'fields' => $this->genericMapping['fields'],
            'creators' => $this->genericMapping['creators'],
        ];
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
        $fieldMap = $this->getItemTypeMapping($zoteroItem)['fields'];
        foreach ($zoteroItem['data'] as $key => $value) {
            if (!$value || !is_scalar($value) || !isset($fieldMap[$key])) {
                continue;
            }
            $found = $this->findProperty($fieldMap[$key]);
            if (!$found) {
                continue;
            }
            list($property, $term) = $found;
            $valueObject = ['property_id' => $property->id()];
            if (in_array($term, $this->uriTerms, true)) {
                $valueObject['@id'] = $value;
                $valueObject['type'] = 'uri';
            } else {
                $valueObject['@value'] = $value;
                $valueObject['type'] = 'literal';
            }
            $omekaItem[$property->term()][] = $valueObject;
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
            $found = $this->findProperty($creatorMap[$creatorType]);
            if (!$found) {
                continue;
            }
            list($property) = $found;
            $omekaItem[$property->term()][] = [
                '@value' => implode(' ', $name),
                'property_id' => $property->id(),
                'type' => 'literal',
            ];
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
