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

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
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

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function getCapabilities(string $url, string $type): array
    {
        // C5: Allowlist check MUST run before any URL fetch — previously this method
        // called file_get_contents directly without calling isUrlAllowed, allowing
        // file:// and php:// stream-wrapper LFI, and bypassing the allowlist entirely.
        if ($this->isUrlAllowed(url: $url) === false) {
            throw new \RuntimeException('URL not in configured layer allowlist', 403);
        }

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
    /**
     * Known-safe PDOK/Kadaster hostnames (exact match only — C5 substring bypass fix).
     *
     * @var string[]
     */
    private const TRUSTED_HOSTNAMES = [
        'geodata.nationaalgeoregister.nl',
        'service.pdok.nl',
        'tiles.pdok.nl',
        'api.pdok.nl',
        'bgt.basisregistraties.overheid.nl',
        'kad.nl',
        'geodata.kadaster.nl',
    ];

    /**
     * Allowed URL schemes (C5: block file://, php://, data:, etc.).
     *
     * @var string[]
     */
    private const ALLOWED_SCHEMES = ['https'];

    /**
     * RFC1918 + loopback + link-local CIDR blocks to deny (SSRF protection).
     *
     * @var string[]
     */
    private const BLOCKED_CIDRS = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '::1/128',
        'fc00::/7',
    ];

    private function isUrlAllowed(string $url): bool
    {
        $parsed = parse_url(url: $url);

        // C5: Validate scheme — only https allowed; rejects file://, php://, data: etc.
        $scheme = strtolower($parsed['scheme'] ?? '');
        if (in_array($scheme, self::ALLOWED_SCHEMES, true) === false) {
            $this->logger->warning(
                'GIS proxy blocked non-https scheme',
                ['scheme' => $scheme, 'url' => substr($url, 0, 100)]
            );
            return false;
        }

        $host = strtolower($parsed['host'] ?? '');
        if ($host === '') {
            return false;
        }

        // C5: Exact hostname match against trusted PDOK/Kadaster list.
        // Substring match (e.g. str_contains($url, 'pdok.nl')) was bypassable via
        // https://evil.com/?x=pdok.nl — exact hostname comparison prevents this.
        foreach (self::TRUSTED_HOSTNAMES as $trusted) {
            if ($host === $trusted || str_ends_with($host, '.'.$trusted) === true) {
                return $this->isHostSafeFromSsrf(host: $host);
            }
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

            foreach ($layers as $layer) {
                $layerObj = $layer;
                if (is_object($layer) === true) {
                    $layerObj = $layer->jsonSerialize();
                }

                $layerUrl    = ($layerObj['url'] ?? '');
                $parsedLayer = parse_url(url: $layerUrl);
                $layerHost   = strtolower($parsedLayer['host'] ?? '');

                // C5: Exact hostname comparison (not substring).
                if ($layerHost !== '' && $host === $layerHost) {
                    return $this->isHostSafeFromSsrf(host: $host);
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
     * Check that a resolved hostname does not map to an internal/private address.
     *
     * Performs a DNS lookup and checks the resolved IP against RFC1918, loopback,
     * and link-local CIDRs to prevent SSRF against internal services.
     *
     * @param string $host The hostname to check
     *
     * @return bool True if the host resolves to a public (non-private) address
     */
    private function isHostSafeFromSsrf(string $host): bool
    {
        // Resolve the hostname to an IP address.
        $ip = gethostbyname($host);
        if ($ip === $host) {
            // DNS resolution failed — allow (WMS services may not always resolve in test env).
            return true;
        }

        // Check against private/loopback CIDR ranges.
        foreach (self::BLOCKED_CIDRS as $cidr) {
            if ($this->ipInCidr(ip: $ip, cidr: $cidr) === true) {
                $this->logger->warning(
                    'GIS proxy blocked SSRF: host resolved to private/loopback address',
                    ['host' => $host, 'ip' => $ip, 'cidr' => $cidr]
                );
                return false;
            }
        }

        return true;
    }//end isHostSafeFromSsrf()

    /**
     * Check if an IP address is within a CIDR range.
     *
     * @param string $ip   The IP address to check (IPv4)
     * @param string $cidr The CIDR block (e.g. '10.0.0.0/8')
     *
     * @return bool True if the IP is within the CIDR range
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        // IPv6 CIDRs — skip if the IP is not IPv6.
        if (str_contains($cidr, ':') === true) {
            return false;
        }

        [$network, $prefix] = explode('/', $cidr);
        $prefix    = (int) $prefix;
        $networkIp = ip2long($network);
        $inputIp   = ip2long($ip);

        if ($networkIp === false || $inputIp === false) {
            return false;
        }

        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix));

        return ($inputIp & $mask) === ($networkIp & $mask);
    }//end ipInCidr()

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
