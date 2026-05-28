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

        // H1: Fetch using curl with pinned IP to prevent TOCTOU DNS rebinding.
        // The IP was validated inside isUrlAllowed; we pin it here so the actual
        // HTTP connection cannot re-resolve to a different (private) address.
        [$responseBody, $contentType] = $this->fetchWithPinnedDns(url: $fullUrl);
        if ($responseBody === null) {
            throw new \RuntimeException('Failed to fetch from external service');
        }

        $result = ['data' => $responseBody, 'contentType' => $contentType];

        if (str_contains(haystack: $contentType, needle: 'xml') === true) {
            $result['data'] = $this->xmlToArray(xml: $responseBody);
        } else if (str_contains(haystack: $contentType, needle: 'json') === true) {
            $decoded = json_decode(json: $responseBody, associative: true);
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

        // H1: Fetch with pinned DNS to prevent TOCTOU rebinding.
        [$response] = $this->fetchWithPinnedDns(url: $capUrl);
        if ($response === null) {
            throw new \RuntimeException('Failed to fetch GetCapabilities');
        }

        return $this->parseCapabilities(xml: $response, service: $service);
    }//end getCapabilities()

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

    /**
     * Check if a URL is in the allowlist (matches a configured MapLayer URL).
     *
     * @param string $url The URL to check
     *
     * @return bool True if allowed
     */
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
        // H1: Use dns_get_record to fetch ALL A and AAAA records and check every address.
        // gethostbyname only returns the first IPv4 address and silently ignores IPv6;
        // a DNS rebind or round-robin could return a private address on later lookups.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        // H1: Treat DNS failure as DENY rather than allow — an attacker can craft a
        // domain that times out selectively to bypass the check.
        if ($records === false || count($records) === 0) {
            $this->logger->warning(
                'GIS proxy blocked: DNS resolution returned no records',
                ['host' => $host]
            );
            return false;
        }

        foreach ($records as $record) {
            // A records use 'ip', AAAA records use 'ipv6'.
            $ip = $record['ip'] ?? ($record['ipv6'] ?? null);
            if ($ip === null) {
                continue;
            }

            foreach (self::BLOCKED_CIDRS as $cidr) {
                if ($this->ipInCidr(ip: $ip, cidr: $cidr) === true) {
                    $this->logger->warning(
                        'GIS proxy blocked SSRF: host resolved to private/loopback address',
                        ['host' => $host, 'ip' => $ip, 'cidr' => $cidr]
                    );
                    return false;
                }
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
        $isIpv6Cidr = str_contains($cidr, ':');
        $isIpv6Ip   = str_contains($ip, ':');

        // H1: Handle IPv6 CIDR ranges against IPv6 addresses.
        if ($isIpv6Cidr === true && $isIpv6Ip === true) {
            [$network, $prefix] = explode('/', $cidr);
            $prefixLen          = (int) $prefix;

            $networkBin = inet_pton($network);
            $inputBin   = inet_pton($ip);

            if ($networkBin === false || $inputBin === false) {
                return false;
            }

            // Build a bit-mask and compare the network parts byte by byte.
            $fullBytes  = intdiv($prefixLen, 8);
            $remainBits = $prefixLen % 8;

            for ($i = 0; $i < $fullBytes; $i++) {
                if ($networkBin[$i] !== $inputBin[$i]) {
                    return false;
                }
            }

            if ($remainBits > 0 && $fullBytes < 16) {
                $mask = (0xFF << (8 - $remainBits)) & 0xFF;
                if ((ord($networkBin[$fullBytes]) & $mask) !== (ord($inputBin[$fullBytes]) & $mask)) {
                    return false;
                }
            }

            return true;
        }//end if

        // Skip mismatched families (IPv4 CIDR vs IPv6 address, or vice-versa).
        if ($isIpv6Cidr !== $isIpv6Ip) {
            return false;
        }

        [$network, $prefix] = explode('/', $cidr);
        $prefix    = (int) $prefix;
        $networkIp = ip2long($network);
        $inputIp   = ip2long($ip);

        if ($networkIp === false || $inputIp === false) {
            return false;
        }

        $mask = 0;
        if ($prefix !== 0) {
            $mask = ~0 << (32 - $prefix);
        }

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
     * Fetch a URL via cURL with a DNS-pinned connection to prevent TOCTOU rebinding.
     *
     * This method performs a fresh DNS lookup (checking all returned addresses against
     * SSRF CIDRs), then pins the resolved IP in the cURL request via CURLOPT_RESOLVE so
     * the HTTP connection cannot re-resolve to a different address mid-flight.
     *
     * @param string $url The URL to fetch
     *
     * @return array{0: string|null, 1: string} [$body, $contentType]; $body is null on error
     */
    private function fetchWithPinnedDns(string $url): array
    {
        $parsed      = parse_url(url: $url);
        $host        = strtolower($parsed['host'] ?? '');
        $defaultPort = 80;
        if (($parsed['scheme'] ?? '') === 'https') {
            $defaultPort = 443;
        }

        $port = (int) ($parsed['port'] ?? $defaultPort);

        if ($host === '') {
            return [null, ''];
        }

        // Resolve all A/AAAA records and pick the first public IP.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || count($records) === 0) {
            $this->logger->warning(
                'GIS proxy fetchWithPinnedDns: DNS resolution returned no records',
                ['host' => $host]
            );
            return [null, ''];
        }

        $pinnedIp = null;
        foreach ($records as $record) {
            $candidate = $record['ip'] ?? ($record['ipv6'] ?? null);
            if ($candidate === null) {
                continue;
            }

            $isPrivate = false;
            foreach (self::BLOCKED_CIDRS as $cidr) {
                if ($this->ipInCidr(ip: $candidate, cidr: $cidr) === true) {
                    $isPrivate = true;
                    break;
                }
            }

            if ($isPrivate === false) {
                $pinnedIp = $candidate;
                break;
            }
        }

        if ($pinnedIp === null) {
            $this->logger->warning(
                'GIS proxy fetchWithPinnedDns: all resolved IPs are private/blocked',
                ['host' => $host]
            );
            return [null, ''];
        }

        // Build the CURLOPT_RESOLVE entry: "host:port:ip" pins name resolution.
        $resolveEntry = $host.':'.$port.':'.$pinnedIp;

        $curl = curl_init();
        curl_setopt_array(
            handle: $curl,
            options: [
                CURLOPT_URL            => $url,
                CURLOPT_RESOLVE        => [$resolveEntry],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HEADER         => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'Procest-GisProxy/1.0',
            ]
        );

        $body        = curl_exec(handle: $curl);
        $contentType = curl_getinfo(handle: $curl, option: CURLINFO_CONTENT_TYPE) ?? '';
        $httpCode    = (int) curl_getinfo(handle: $curl, option: CURLINFO_HTTP_CODE);
        $curlError   = curl_error(handle: $curl);
        curl_close(handle: $curl);

        if ($body === false || $curlError !== '') {
            $this->logger->warning(
                'GIS proxy fetchWithPinnedDns: curl error',
                ['host' => $host, 'error' => $curlError]
            );
            return [null, ''];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->warning(
                'GIS proxy fetchWithPinnedDns: non-2xx response',
                ['host' => $host, 'http_code' => $httpCode]
            );
            return [null, ''];
        }

        // Strip charset / parameters from content-type header value.
        $bareContentType = strtolower(trim(explode(';', $contentType)[0]));

        return [$body, $bareContentType];
    }//end fetchWithPinnedDns()

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
