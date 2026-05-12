<?php

/**
 * Procest PDOK BAG Service
 *
 * Shared, server-side ingress for every Procest call against the PDOK BAG
 * WFS v2_0 API. Exposes a small, stable Procest-internal shape on top of the
 * BAG payload — snake_case → camelCase, `bouwjaar` always integer,
 * `oppervlakte` always integer m2, `gebruiksdoel` always array — so consumer
 * code never has to defend against the raw WFS quirks.
 *
 * When the `pdok_bag_source` IAppConfig key is non-empty, outbound HTTP is
 * dispatched through the configured OpenConnector source slug; otherwise the
 * service calls PDOK directly. Cache hits bypass the rate guard.
 *
 * Cache strategy: 24 h on every method, keyed on the BAG identifier.
 *
 * @category Service
 * @package  OCA\Procest\Service\Pdok
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Pdok;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SettingsService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Single ingress for PDOK BAG WFS v2_0 lookups.
 */
class PdokBagService
{

    /**
     * Default endpoint when `pdok_bag_endpoint` is empty.
     */
    private const DEFAULT_ENDPOINT = 'https://service.pdok.nl/lv/bag/wfs/v2_0';

    /**
     * Cache TTL fallback in seconds (24 h).
     */
    private const DEFAULT_TTL = 86400;

    /**
     * Shared cache used for response caching.
     *
     * @var ICache The distributed cache instance.
     */
    private ICache $cache;

    /**
     * Constructor.
     *
     * @param ICacheFactory      $cacheFactory    Cache factory.
     * @param IAppConfig         $appConfig       App configuration accessor.
     * @param SettingsService    $settingsService Procest settings service.
     * @param ContainerInterface $container       DI container for optional
     *                                            OpenConnector resolution.
     * @param LoggerInterface    $logger          PSR logger.
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        private IAppConfig $appConfig,
        private SettingsService $settingsService,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        $this->cache = $cacheFactory->createDistributed('procest_pdok_bag');
    }//end __construct()

    /**
     * Look up a BAG nummeraanduiding by id.
     *
     * @param string $id BAG nummeraanduiding identificatie (16 digits).
     *
     * @return array Normalised Procest-internal shape.
     */
    public function getNummeraanduiding(string $id): array
    {
        return $this->fetch(
            typeName: 'bag:nummeraanduiding',
            propertyName: 'identificatie',
            value: $id,
        );
    }//end getNummeraanduiding()

    /**
     * Look up a BAG verblijfsobject by id.
     *
     * @param string $id BAG verblijfsobject identificatie.
     *
     * @return array Normalised Procest-internal shape.
     */
    public function getVerblijfsobject(string $id): array
    {
        return $this->fetch(
            typeName: 'bag:verblijfsobject',
            propertyName: 'identificatie',
            value: $id,
        );
    }//end getVerblijfsobject()

    /**
     * Look up a BAG pand footprint by id.
     *
     * @param string $id BAG pand identificatie.
     *
     * @return array Normalised Procest-internal shape.
     */
    public function getPand(string $id): array
    {
        return $this->fetch(
            typeName: 'bag:pand',
            propertyName: 'identificatie',
            value: $id,
        );
    }//end getPand()

    /**
     * Shared fetch + cache + normalise pipeline.
     *
     * @param string $typeName     WFS typeName (e.g. `bag:nummeraanduiding`).
     * @param string $propertyName WFS filter property name.
     * @param string $value        Filter value (the BAG identifier).
     *
     * @return array Normalised payload or `[]` when not found / on failure.
     */
    private function fetch(string $typeName, string $propertyName, string $value): array
    {
        $cacheKey = $typeName.'_'.$propertyName.'_'.md5(string: $value);
        $cached   = $this->cache->get($cacheKey);
        if ($cached !== null) {
            $this->logger->debug(
                'Procest PDOK BAG cache hit',
                ['typeName' => $typeName, 'key' => $cacheKey]
            );
            return $cached;
        }

        $params = [
            'service'      => 'WFS',
            'version'      => '2.0.0',
            'request'      => 'GetFeature',
            'typeNames'    => $typeName,
            'outputFormat' => 'application/json',
            'srsName'      => 'EPSG:4326',
            'count'        => '1',
            'filter'       => $this->buildFilter(propertyName: $propertyName, value: $value),
        ];

        $endpoint = $this->appConfig->getValueString(
            Application::APP_ID,
            'pdok_bag_endpoint',
            self::DEFAULT_ENDPOINT
        );
        $source   = $this->appConfig->getValueString(
            Application::APP_ID,
            'pdok_bag_source',
            ''
        );

        $started = microtime(as_float: true);

        try {
            if (empty($source) === false) {
                $body = $this->callViaOpenConnector(sourceSlug: $source, params: $params);
            } else {
                $body = $this->callDirect(
                    url: $endpoint.'?'.http_build_query(data: $params),
                );
            }
        } catch (\RuntimeException $e) {
            $this->logger->warning(
                'Procest PDOK BAG call failed',
                [
                    'typeName' => $typeName,
                    'error'    => $e->getMessage(),
                    'status'   => $e->getCode(),
                ]
            );
            return [];
        }

        $decoded = json_decode(json: $body, associative: true);
        if (is_array(value: $decoded) === false) {
            return [];
        }

        $features = ($decoded['features'] ?? []);
        if (is_array(value: $features) === false || empty($features) === true) {
            $this->cache->set(
                $cacheKey,
                [],
                (int) $this->appConfig->getValueString(
                    Application::APP_ID,
                    'pdok_cache_lookup_ttl_seconds',
                    (string) self::DEFAULT_TTL
                ),
            );
            return [];
        }

        $normalised = $this->normaliseFeature(feature: $features[0]);

        $this->cache->set(
            $cacheKey,
            $normalised,
            (int) $this->appConfig->getValueString(
                Application::APP_ID,
                'pdok_cache_lookup_ttl_seconds',
                (string) self::DEFAULT_TTL
            ),
        );

        $elapsedMs = (int) ((microtime(as_float: true) - $started) * 1000);
        $this->logger->info(
            'Procest PDOK BAG call',
            [
                'typeName'  => $typeName,
                'cache'     => 'miss',
                'elapsedMs' => $elapsedMs,
                'viaSource' => (empty($source) === false),
            ]
        );

        return $normalised;
    }//end fetch()

    /**
     * Build the OGC Filter XML for a single property equality predicate.
     *
     * @param string $propertyName WFS property name.
     * @param string $value        Filter value.
     *
     * @return string OGC Filter XML 2.0 fragment.
     */
    private function buildFilter(string $propertyName, string $value): string
    {
        return '<Filter xmlns="http://www.opengis.net/fes/2.0">'
            .'<PropertyIsEqualTo>'
            .'<ValueReference>'.htmlspecialchars(string: $propertyName, flags: ENT_XML1).'</ValueReference>'
            .'<Literal>'.htmlspecialchars(string: $value, flags: ENT_XML1).'</Literal>'
            .'</PropertyIsEqualTo>'
            .'</Filter>';
    }//end buildFilter()

    /**
     * Direct HTTP GET.
     *
     * @param string $url Fully qualified URL.
     *
     * @return string Raw response body.
     *
     * @throws \RuntimeException On non-2xx or network failure.
     */
    private function callDirect(string $url): string
    {
        $streamOptions = [
            'http' => [
                'method'        => 'GET',
                'timeout'       => 15,
                'header'        => "Accept: application/json\r\n",
                'ignore_errors' => true,
            ],
        ];
        $context       = stream_context_create(options: $streamOptions);

        $body = @file_get_contents(filename: $url, use_include_path: false, context: $context);
        if ($body === false) {
            throw new \RuntimeException('Network error contacting PDOK BAG WFS', 0);
        }

        $statusCode = 0;
        // $http_response_header is populated by the HTTP wrapper.
        foreach ($http_response_header as $header) {
            if (preg_match(pattern: '#^HTTP/\S+\s+(\d{3})#', subject: $header, matches: $matches) === 1) {
                $statusCode = (int) $matches[1];
            }
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('PDOK BAG WFS HTTP '.$statusCode, $statusCode);
        }

        return $body;
    }//end callDirect()

    /**
     * Dispatch through OpenConnector when configured (optional dependency).
     *
     * @param string $sourceSlug OpenConnector source slug.
     * @param array  $params     WFS query parameters.
     *
     * @return string Raw response body.
     *
     * @throws \RuntimeException On upstream failure.
     */
    private function callViaOpenConnector(string $sourceSlug, array $params): string
    {
        try {
            $callService = $this->container->get('OCA\OpenConnector\Service\CallService');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Procest PDOK BAG: OpenConnector not available, falling back to direct HTTP',
                ['sourceSlug' => $sourceSlug, 'error' => $e->getMessage()]
            );
            $endpoint = $this->appConfig->getValueString(
                Application::APP_ID,
                'pdok_bag_endpoint',
                self::DEFAULT_ENDPOINT
            );
            return $this->callDirect(
                url: $endpoint.'?'.http_build_query(data: $params),
            );
        }

        try {
            $result = $callService->call(
                $sourceSlug,
                '',
                'GET',
                ['query' => $params, 'headers' => ['Accept' => 'application/json']]
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'OpenConnector dispatch failed: '.$e->getMessage(),
                500
            );
        }

        if (is_array(value: $result) === true) {
            $body = ($result['body'] ?? ($result['response']['body'] ?? null));
            if (is_string(value: $body) === true) {
                return $body;
            }

            if (is_array(value: $body) === true) {
                return (string) json_encode(value: $body);
            }
        }

        throw new \RuntimeException('OpenConnector returned an unrecognised response shape', 502);
    }//end callViaOpenConnector()

    /**
     * Normalise a single WFS feature into the Procest-internal shape.
     *
     * Rules:
     * - snake_case keys → camelCase
     * - `bouwjaar` always integer (`0` when missing)
     * - `oppervlakte` always integer m2 (`0` when missing)
     * - `gebruiksdoel` always array (single string → `[$string]`)
     *
     * @param array $feature One feature entry from the WFS FeatureCollection.
     *
     * @return array Normalised payload (includes `_geometry` when present).
     */
    private function normaliseFeature(array $feature): array
    {
        $properties = ($feature['properties'] ?? []);
        if (is_array(value: $properties) === false) {
            $properties = [];
        }

        $out = [];
        foreach ($properties as $key => $value) {
            $camel       = $this->toCamel(snake: (string) $key);
            $out[$camel] = $value;
        }

        $out['bouwjaar']    = (int) ($out['bouwjaar'] ?? 0);
        $out['oppervlakte'] = (int) ($out['oppervlakte'] ?? 0);

        $gebruiksdoel = ($out['gebruiksdoel'] ?? []);
        if (is_array(value: $gebruiksdoel) === false) {
            $gebruiksdoel = [(string) $gebruiksdoel];
        }

        $out['gebruiksdoel'] = $gebruiksdoel;

        $geometry = ($feature['geometry'] ?? null);
        if (is_array(value: $geometry) === true) {
            $out['_geometry'] = $geometry;
        }

        return $out;
    }//end normaliseFeature()

    /**
     * snake_case → camelCase.
     *
     * @param string $snake Input string.
     *
     * @return string Camel-cased output.
     */
    private function toCamel(string $snake): string
    {
        $parts = explode(separator: '_', string: $snake);
        $first = array_shift(array: $parts);
        $tail  = array_map(callback: 'ucfirst', array: $parts);
        return $first.implode(separator: '', array: $tail);
    }//end toCamel()
}//end class
