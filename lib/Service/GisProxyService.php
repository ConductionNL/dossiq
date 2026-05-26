<?php

/**
 * Procest GIS Proxy Service
 *
 * Handles URL allowlist validation, request forwarding, caching, and
 * GetCapabilities parsing for WMS/WFS services.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for proxying and caching WMS/WFS requests to external GIS services.
 */
class GisProxyService
{

    /**
     * Cache TTL for proxied responses (5 minutes).
     */
    private const CACHE_TTL = 300;

    /**
     * Rate limit: max requests per minute per user.
     */
    private const RATE_LIMIT = 100;

    /**
     * The cache instance.
     *
     * @var ICache The cache instance.
     */
    private ICache $cache;

    /**
     * Constructor for GisProxyService.
     *
     * @param ICacheFactory      $cacheFactory The cache factory
     * @param IUserSession       $userSession  The user session
     * @param ContainerInterface $container    The DI container
     * @param LoggerInterface    $logger       The logger
     *
     * @return void
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        private IUserSession $userSession,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        $this->cache = $cacheFactory->createDistributed('procest_gis_proxy');
    }//end __construct()

    /**
     * Proxy a request to an external WMS/WFS service.
     *
     * @param string $url   The target URL
     * @param array  $query Query parameters to forward
     * @param string $type  Request type (wms, wfs, capabilities)
     *
     * @return array The response data
     *
     * @throws \RuntimeException If URL is not allowed or rate limit exceeded
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function proxyRequest(string $url, array $query, string $type): array
    {
        // Validate URL against allowlist.
        if ($this->isUrlAllowed(url: $url) === false) {
            throw new \RuntimeException('URL not in configured layer allowlist', 403);
        }

        // Check rate limit.
        $this->checkRateLimit();

        // Build the full request URL.
        $fullUrl = $url;
        if (empty($query) === false) {
            $fullUrl .= '?'.http_build_query(data: $query);
        }

        // Check cache.
        $cacheKey = 'proxy_'.md5(string: $fullUrl);
        $cached   = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Forward the request.
        $streamOptions = [
            'http' => [
                'method'  => 'GET',
                'timeout' => 30,
                'header'  => "Accept: application/json, application/xml, image/png\r\n",
            ],
        ];
        $context       = stream_context_create(options: $streamOptions);

        $response = @file_get_contents(filename: $fullUrl, use_include_path: false, context: $context);
        if ($response === false) {
            throw new \RuntimeException('Failed to fetch from external service');
        }

        // Parse XML responses to JSON for WFS/capabilities.
        $contentType = '';
        // $http_response_header is populated by file_get_contents() via PHP.
        // It is always set after a successful HTTP wrapper call.
        foreach ($http_response_header as $header) {
            if (stripos(haystack: $header, needle: 'Content-Type:') === 0) {
                $contentType = trim(string: substr(string: $header, offset: 13));
                break;
            }
        }

        $result = ['data' => $response, 'contentType' => $contentType];

        if (str_contains(haystack: $contentType, needle: 'xml') === true) {
            $result['data'] = $this->xmlToArray(xml: $response);
        } else if (str_contains(haystack: $contentType, needle: 'json') === true) {
            $decoded = json_decode(json: $response, associative: true);
            if ($decoded !== null) {
                $result['data'] = $decoded;
            }
        }

        // Cache the result.
        $this->cache->set($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }//end proxyRequest()

    /**
     * Fetch and parse GetCapabilities from a WMS/WFS service.
     *
     * @param string $url  The service base URL
     * @param string $type Service type (wms or wfs)
     *
     * @return array Parsed capabilities with layers list
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function getCapabilities(string $url, string $type): array
    {
        $service = 'WMS';
        if (strtoupper($type) === 'WFS') {
            $service = 'WFS';
        }

        $version = '1.3.0';
        if ($service === 'WFS') {
            $version = '2.0.0';
        }

        $separator = '?';
        if (str_contains(haystack: $url, needle: '?') === true) {
            $separator = '&';
        }

        $queryParams = http_build_query(
            data: [
                'service' => $service,
                'request' => 'GetCapabilities',
                'version' => $version,
            ]
        );
        $capUrl      = $url.$separator.$queryParams;

        $streamOptions = [
            'http' => [
                'method'  => 'GET',
                'timeout' => 30,
            ],
        ];
        $context       = stream_context_create(options: $streamOptions);

        $response = @file_get_contents(filename: $capUrl, use_include_path: false, context: $context);
        if ($response === false) {
            throw new \RuntimeException('Failed to fetch GetCapabilities');
        }

        return $this->parseCapabilities(xml: $response, service: $service);
    }//end getCapabilities()

    /**
     * Check if a URL is in the allowlist (matches a configured MapLayer URL).
     *
     * @param string $url The URL to check
     *
     * @return bool True if allowed
     */
    private function isUrlAllowed(string $url): bool
    {
        // Always allow PDOK URLs.
        if (str_contains(haystack: $url, needle: 'pdok.nl') === true
            || str_contains(haystack: $url, needle: 'kadaster.nl') === true
        ) {
            return true;
        }

        // Check against configured MapLayer URLs.
        try {
            $objectService   = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $settingsService = $this->container->get(SettingsService::class);
            $schemaId        = $settingsService->getConfigValue('map_layer_schema');
            $registerId      = $settingsService->getConfigValue('register');

            if (empty($schemaId) === true || empty($registerId) === true) {
                return false;
            }

            $layers = $objectService->findAll(
                schemaId: (int) $schemaId,
                registerId: (int) $registerId,
            );

            $parsedUrl = parse_url(url: $url);
            $urlHost   = ($parsedUrl['host'] ?? '');

            foreach ($layers as $layer) {
                $layerObj = $layer;
                if (is_object($layer) === true) {
                    $layerObj = $layer->jsonSerialize();
                }

                $layerUrl    = ($layerObj['url'] ?? '');
                $parsedLayer = parse_url(url: $layerUrl);
                $layerHost   = ($parsedLayer['host'] ?? '');
                if ($urlHost === $layerHost) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'GIS proxy allowlist check failed',
                ['exception' => $e->getMessage()]
            );
        }//end try

        return false;
    }//end isUrlAllowed()

    /**
     * Check rate limiting for the current user.
     *
     * @throws \RuntimeException If rate limit exceeded (code 429)
     *
     * @return void
     */
    private function checkRateLimit(): void
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return;
        }

        $userId   = $user->getUID();
        $cacheKey = 'rate_limit_'.$userId.'_'.date(format: 'YmdHi');
        $current  = (int) $this->cache->get($cacheKey);

        if ($current >= self::RATE_LIMIT) {
            $this->logger->warning(
                'GIS proxy rate limit exceeded',
                ['userId' => $userId, 'count' => $current]
            );
            throw new \RuntimeException('Rate limit exceeded', 429);
        }

        $this->cache->set($cacheKey, ($current + 1), 60);
    }//end checkRateLimit()

    /**
     * Parse GetCapabilities XML response into a structured array.
     *
     * @param string $xml     The XML response
     * @param string $service The service type (WMS or WFS)
     *
     * @return array Parsed capabilities
     */
    private function parseCapabilities(string $xml, string $service): array
    {
        $doc = new \DOMDocument();
        $doc->loadXML(source: $xml);

        $layers = [];

        if ($service === 'WMS') {
            $layerElements = $doc->getElementsByTagName(qualifiedName: 'Layer');
            foreach ($layerElements as $layerEl) {
                $nameEl  = $layerEl->getElementsByTagName(qualifiedName: 'Name')->item(0);
                $titleEl = $layerEl->getElementsByTagName(qualifiedName: 'Title')->item(0);
                if ($nameEl !== null) {
                    $titleText = $nameEl->textContent;
                    if ($titleEl !== null) {
                        $titleText = $titleEl->textContent;
                    }

                    $layers[] = [
                        'name'  => $nameEl->textContent,
                        'title' => $titleText,
                    ];
                }
            }
        } else {
            // WFS: look for FeatureType elements.
            $featureTypes = $doc->getElementsByTagName(qualifiedName: 'FeatureType');
            foreach ($featureTypes as $ft) {
                $nameEl  = $ft->getElementsByTagName(qualifiedName: 'Name')->item(0);
                $titleEl = $ft->getElementsByTagName(qualifiedName: 'Title')->item(0);
                if ($nameEl !== null) {
                    $titleText = $nameEl->textContent;
                    if ($titleEl !== null) {
                        $titleText = $titleEl->textContent;
                    }

                    $layers[] = [
                        'name'  => $nameEl->textContent,
                        'title' => $titleText,
                    ];
                }
            }
        }//end if

        return [
            'service' => $service,
            'layers'  => $layers,
        ];
    }//end parseCapabilities()

    /**
     * Convert an XML string to an associative array.
     *
     * @param string $xml The XML string
     *
     * @return array|string The parsed data
     */
    private function xmlToArray(string $xml): array|string
    {
        $simpleXml = @simplexml_load_string(data: $xml);
        if ($simpleXml === false) {
            return $xml;
        }

        return json_decode(json: json_encode(value: $simpleXml), associative: true);
    }//end xmlToArray()
}//end class
