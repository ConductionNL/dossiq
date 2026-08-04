<?php

/**
 * Procest PDOK Locatieserver Service
 *
 * Shared, server-side ingress for every Procest call against the PDOK
 * Locatieserver v3_1 API (suggest, free, lookup, reverse). Other code MUST
 * NOT instantiate its own HTTP client targeting `api.pdok.nl/bzk/locatieserver`
 * — case-location, case-map-overview, and the map component all consume this
 * service. When the `pdok_locatieserver_source` IAppConfig key is non-empty,
 * outbound HTTP is dispatched through the configured OpenConnector source
 * slug instead of directly to PDOK.
 *
 * Cache strategy: 24 h for `lookup`/`reverse`, 5 min for `suggest`, keyed on
 * the full request signature (including `fq` filters so filtered suggests do
 * not collide with unfiltered ones).
 *
 * Outage handling: 3 consecutive 5xx responses within 60 s flip the service
 * into a 5 min "degraded" state; during that window `suggest` short-circuits
 * to an empty array so the calling address-resolution path can fall back to
 * free-text (REQ-CL-3 graceful degradation).
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
 *
 * @spec openspec/specs/pdok-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Pdok;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Support\SuppressesWarnings;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Single ingress for PDOK Locatieserver v3_1 calls.
 */
class PdokLocatieserverService
{

    use SuppressesWarnings;

    /**
     * Default endpoint when `pdok_locatieserver_endpoint` is empty.
     */
    private const DEFAULT_ENDPOINT = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1';

    /**
     * Cache TTL fallback in seconds for lookup/reverse responses (24 h).
     */
    private const DEFAULT_LOOKUP_TTL = 86400;

    /**
     * Cache TTL fallback in seconds for suggest responses (5 min).
     */
    private const DEFAULT_SUGGEST_TTL = 300;

    /**
     * Outage window — number of consecutive 5xx responses before degrading.
     */
    private const OUTAGE_THRESHOLD = 3;

    /**
     * Outage window length in seconds.
     */
    private const OUTAGE_WINDOW = 60;

    /**
     * How long the service stays degraded once tripped (5 min).
     */
    private const DEGRADED_TTL = 300;

    /**
     * Shared cache used for response caching AND outage tracking.
     *
     * @var ICache The distributed cache instance.
     */
    private ICache $cache;

    /**
     * Constructor.
     *
     * @param ICacheFactory      $cacheFactory Cache factory.
     * @param IAppConfig         $appConfig    App configuration accessor.
     * @param ContainerInterface $container    DI container for optional
     *                                         OpenConnector resolution.
     * @param LoggerInterface    $logger       PSR logger.
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        $this->cache = $cacheFactory->createDistributed('procest_pdok_locatieserver');
    }//end __construct()

    /**
     * Autocomplete suggest call.
     *
     * Returns an empty array while the service is in a degraded state so the
     * caller can fall back to free-text input.
     *
     * @param string $query         Free-text address fragment.
     * @param array  $filterQueries Optional Solr-style filter queries (e.g.
     *                              `['type:adres', 'gemeentenaam:Amsterdam']`).
     * @param int    $rows          Maximum number of suggestions to return.
     *
     * @return array Decoded JSON response or `[]` while degraded.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function suggest(string $query, array $filterQueries=[], int $rows=10): array
    {
        if ($this->isDegraded() === true) {
            return [];
        }

        $params = ['q' => $query, 'rows' => $rows];
        if (empty($filterQueries) === false) {
            $params['fq'] = $filterQueries;
        }

        return $this->call(
            method: 'suggest',
            params: $params,
            ttl: (int) $this->appConfig->getValueString(
                Application::APP_ID,
                'pdok_cache_suggest_ttl_seconds',
                (string) self::DEFAULT_SUGGEST_TTL
            ),
        );
    }//end suggest()

    /**
     * Free-text geocoding call.
     *
     * @param string $query         Free-text query.
     * @param array  $filterQueries Optional filter queries.
     *
     * @return array Decoded JSON response.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function free(string $query, array $filterQueries=[]): array
    {
        $params = ['q' => $query];
        if (empty($filterQueries) === false) {
            $params['fq'] = $filterQueries;
        }

        return $this->call(
            method: 'free',
            params: $params,
            ttl: (int) $this->appConfig->getValueString(
                Application::APP_ID,
                'pdok_cache_lookup_ttl_seconds',
                (string) self::DEFAULT_LOOKUP_TTL
            ),
        );
    }//end free()

    /**
     * Lookup a single suggest result by id.
     *
     * @param string $id Locatieserver result id (returned by suggest/free).
     *
     * @return array Decoded JSON response.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function lookup(string $id): array
    {
        return $this->call(
            method: 'lookup',
            params: ['id' => $id],
            ttl: (int) $this->appConfig->getValueString(
                Application::APP_ID,
                'pdok_cache_lookup_ttl_seconds',
                (string) self::DEFAULT_LOOKUP_TTL
            ),
        );
    }//end lookup()

    /**
     * Reverse geocode coordinates to nearest addresses.
     *
     * @param float $lat WGS84 latitude.
     * @param float $lng WGS84 longitude.
     *
     * @return array Decoded JSON response.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function reverse(float $lat, float $lng): array
    {
        return $this->call(
            method: 'reverse',
            params: ['lat' => $lat, 'lon' => $lng],
            ttl: (int) $this->appConfig->getValueString(
                Application::APP_ID,
                'pdok_cache_lookup_ttl_seconds',
                (string) self::DEFAULT_LOOKUP_TTL
            ),
        );
    }//end reverse()

    /**
     * Current service health.
     *
     * @return string `ok` when healthy, `degraded` during the cool-down.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function health(): string
    {
        if ($this->isDegraded() === true) {
            return 'degraded';
        }

        return 'ok';
    }//end health()

    /**
     * Execute a Locatieserver call with caching + outage tracking.
     *
     * Routes through OpenConnector when `pdok_locatieserver_source` is set;
     * otherwise calls PDOK directly. Cache key includes the method and the
     * normalised full query string so that filtered variants are distinct.
     *
     * @param string $method Endpoint suffix (`suggest`, `free`, `lookup`,
     *                       `reverse`).
     * @param array  $params Query string parameters.
     * @param int    $ttl    Cache TTL in seconds.
     *
     * @return array Decoded JSON body or `['response' => ['docs' => []]]`
     *               when the call fails after exhausting retries.
     */
    private function call(string $method, array $params, int $ttl): array
    {
        $queryString = http_build_query(data: $params);
        $cacheKey    = $method.'_'.md5(string: $queryString);

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            $this->logger->debug(
                'Procest PDOK Locatieserver cache hit',
                ['method' => $method, 'key' => $cacheKey]
            );
            return $cached;
        }

        $started = microtime(as_float: true);
        $source  = $this->appConfig->getValueString(
            Application::APP_ID,
            'pdok_locatieserver_source',
            ''
        );

        $endpoint = $this->appConfig->getValueString(
            Application::APP_ID,
            'pdok_locatieserver_endpoint',
            self::DEFAULT_ENDPOINT
        );

        $url = rtrim(string: $endpoint, characters: '/').'/'.$method.'?'.$queryString;

        try {
            if (empty($source) === false) {
                $body = $this->callViaOpenConnector(sourceSlug: $source, path: $method, params: $params);
            }

            if (empty($source) === true) {
                $body = $this->callDirect(url: $url);
            }
        } catch (RuntimeException $e) {
            $this->recordFailure(statusCode: $e->getCode());
            $this->logger->warning(
                'Procest PDOK Locatieserver call failed',
                [
                    'method' => $method,
                    'error'  => $e->getMessage(),
                    'status' => $e->getCode(),
                ]
            );
            return ['response' => ['docs' => []]];
        }//end try

        $decoded = json_decode(json: $body, associative: true);
        if (is_array(value: $decoded) === false) {
            $decoded = ['response' => ['docs' => []]];
        }

        $this->cache->set($cacheKey, $decoded, $ttl);

        $elapsedMs = (int) ((microtime(as_float: true) - $started) * 1000);
        $this->logger->info(
            'Procest PDOK Locatieserver call',
            [
                'method'    => $method,
                'cache'     => 'miss',
                'elapsedMs' => $elapsedMs,
                'viaSource' => (empty($source) === false),
            ]
        );

        return $decoded;
    }//end call()

    /**
     * Direct HTTP GET to PDOK with a JSON Accept header.
     *
     * @param string $url Fully qualified URL.
     *
     * @return string Raw response body.
     *
     * @throws \RuntimeException When the upstream returns non-2xx or the
     *                           network call fails. The exception code carries
     *                           the HTTP status (or 0 for network failures).
     *
     * @SuppressWarnings(PHPMD.UndefinedVariable) $matches is a preg_match() by-reference
     * out-parameter, which PHPMD does not model.
     */
    private function callDirect(string $url): string
    {
        $streamOptions = [
            'http' => [
                'method'        => 'GET',
                'timeout'       => 10,
                'header'        => "Accept: application/json\r\n",
                'ignore_errors' => true,
            ],
        ];
        $context       = stream_context_create(options: $streamOptions);

        // Deliberately fopen() + stream_get_meta_data() rather than
        // file_get_contents(): the HTTP stream wrapper publishes
        // $http_response_header only into the scope that actually made the
        // call, which here is the closure, so that magic variable is
        // unreachable from this method. The wrapper_data key carries the
        // identical response header lines and travels back with the return
        // value.
        $response = $this->withoutWarnings(
            operation: static function () use ($url, $context): array {
                $handle = fopen(filename: $url, mode: 'rb', use_include_path: false, context: $context);
                if ($handle === false) {
                    return [
                        'body'    => false,
                        'headers' => [],
                    ];
                }

                $metaData = stream_get_meta_data($handle);
                $headers  = ($metaData['wrapper_data'] ?? []);
                if (is_array($headers) === false) {
                    $headers = [];
                }

                $body = stream_get_contents($handle);
                fclose($handle);

                return [
                    'body'    => $body,
                    'headers' => $headers,
                ];
            }
        );

        $body = $response['body'];
        if ($body === false) {
            $this->logger->warning(
                'PDOK Locatieserver request failed',
                ['detail' => $this->lastSuppressedWarning()]
            );
            throw new RuntimeException('Network error contacting PDOK Locatieserver', 0);
        }

        $statusCode = 0;
        foreach ($response['headers'] as $header) {
            if (preg_match(pattern: '#^HTTP/\S+\s+(\d{3})#', subject: $header, matches: $matches) === 1) {
                $statusCode = (int) $matches[1];
            }
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('PDOK Locatieserver HTTP '.$statusCode, $statusCode);
        }

        $this->recordSuccess();
        return $body;
    }//end callDirect()

    /**
     * Dispatch through an OpenConnector source slug when configured.
     *
     * OpenConnector is an optional runtime dependency; the source slug carries
     * its own credentials inside OpenConnector's vault, so no auth material is
     * ever stored in Procest config. If OpenConnector is not installed the
     * call falls back to a direct HTTP call against the configured endpoint.
     *
     * @param string $sourceSlug OpenConnector source slug.
     * @param string $path       Locatieserver endpoint suffix (`suggest` etc).
     * @param array  $params     Query parameters.
     *
     * @return string Raw response body.
     *
     * @throws \RuntimeException On upstream failure (code carries HTTP status).
     */
    private function callViaOpenConnector(string $sourceSlug, string $path, array $params): string
    {
        try {
            $callService = $this->container->get('OCA\OpenConnector\Service\CallService');
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest PDOK Locatieserver: OpenConnector not available, falling back to direct HTTP',
                ['sourceSlug' => $sourceSlug, 'error' => $e->getMessage()]
            );
            $endpoint = $this->appConfig->getValueString(
                Application::APP_ID,
                'pdok_locatieserver_endpoint',
                self::DEFAULT_ENDPOINT
            );
            return $this->callDirect(url: rtrim(string: $endpoint, characters: '/').'/'.$path.'?'.http_build_query(data: $params));
        }

        // We intentionally call CallService dynamically; the signature has
        // varied across OpenConnector versions and we keep this loose to
        // avoid coupling Procest to a specific contract. The minimum
        // contract is `call(string $sourceSlug, string $endpoint, string
        // $method, array $config): array` returning a body string under
        // `['body']` or `['response']['body']`.
        try {
            $result = $callService->call(
                $sourceSlug,
                $path,
                'GET',
                ['query' => $params, 'headers' => ['Accept' => 'application/json']]
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                'OpenConnector dispatch failed: '.$e->getMessage(),
                500
            );
        }

        if (is_array(value: $result) === true) {
            $body = ($result['body'] ?? ($result['response']['body'] ?? null));
            if (is_string(value: $body) === true) {
                $this->recordSuccess();
                return $body;
            }

            if (is_array(value: $body) === true) {
                $this->recordSuccess();
                return (string) json_encode(value: $body);
            }
        }

        throw new RuntimeException('OpenConnector returned an unrecognised response shape', 502);
    }//end callViaOpenConnector()

    /**
     * Record a successful call (clears the failure counter).
     *
     * @return void
     */
    private function recordSuccess(): void
    {
        $this->cache->remove('failures');
        // Self-clear degraded state on first success after cool-down.
        if ($this->cache->get('degraded_until') !== null) {
            $this->cache->remove('degraded_until');
        }
    }//end recordSuccess()

    /**
     * Record a failure and flip into degraded state if the threshold is hit
     * within the rolling window.
     *
     * @param int $statusCode HTTP status code from the failing call.
     *
     * @return void
     */
    private function recordFailure(int $statusCode): void
    {
        // Only 5xx (and network errors, coded 0) count toward the outage
        // budget. 4xx responses indicate a client problem, not a PDOK outage.
        if ($statusCode !== 0 && ($statusCode < 500 || $statusCode >= 600)) {
            return;
        }

        $failures = $this->cache->get('failures');
        if (is_array(value: $failures) === false) {
            $failures = [];
        }

        $now      = time();
        $failures = array_filter(
                array: $failures,
                callback: static function (int $failedAt) use ($now): bool {
                    return ($now - $failedAt) <= self::OUTAGE_WINDOW;
                }
                );

        $failures[] = $now;

        if (count(value: $failures) >= self::OUTAGE_THRESHOLD) {
            $this->cache->set('degraded_until', ($now + self::DEGRADED_TTL), self::DEGRADED_TTL);
            $this->cache->remove('failures');
            $this->logger->warning(
                'Procest PDOK Locatieserver: degraded state engaged',
                ['failureCount' => count(value: $failures), 'cooldownSec' => self::DEGRADED_TTL]
            );
            return;
        }

        $this->cache->set('failures', array_values(array: $failures), self::OUTAGE_WINDOW);
    }//end recordFailure()

    /**
     * Whether the service is currently in degraded state.
     *
     * @return bool True while degraded.
     */
    private function isDegraded(): bool
    {
        $until = $this->cache->get('degraded_until');
        if ($until === null) {
            return false;
        }

        if ((int) $until <= time()) {
            $this->cache->remove('degraded_until');
            return false;
        }

        return true;
    }//end isDegraded()
}//end class
