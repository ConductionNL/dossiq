<?php

/**
 * Dossiq PDOK Locatieserver Service
 *
 * Shared, server-side ingress for every Dossiq call against the PDOK
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
 * @package  OCA\Dossiq\Service\Pdok
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/pdok-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Pdok;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Support\FleetAppId;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Single ingress for PDOK Locatieserver v3_1 calls.
 *
 * @spec openspec/specs/pdok-integration/spec.md
 */
class PdokLocatieserverService {

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
	 * Shared cache used for response caching AND outage tracking.
	 *
	 * @var ICache The distributed cache instance.
	 */
	private ICache $cache;

	/**
	 * Counts PDOK's 5xx answers and degrades the service when they pile up.
	 *
	 * @var OutageBreaker
	 */
	private readonly OutageBreaker $breaker;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Cache factory.
	 * @param IAppConfig $appConfig App configuration accessor.
	 * @param ContainerInterface $container DI container for optional
	 *                                      OpenConnector resolution.
	 * @param LoggerInterface $logger PSR logger.
	 * @param IClientService $clientService Nextcloud HTTP client factory.
	 */
	public function __construct(
		ICacheFactory $cacheFactory,
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
		private IClientService $clientService,
	) {
		$this->cache = $cacheFactory->createDistributed('dossiq_pdok_locatieserver');
		$this->breaker = new OutageBreaker(cache: $this->cache, logger: $logger);
	}//end __construct()

	/**
	 * Autocomplete suggest call.
	 *
	 * Returns an empty array while the service is in a degraded state so the
	 * caller can fall back to free-text input.
	 *
	 * @param string $query Free-text address fragment.
	 * @param array $filterQueries Optional Solr-style filter queries (e.g.
	 *                             `['type:adres', 'gemeentenaam:Amsterdam']`).
	 * @param int $rows Maximum number of suggestions to return.
	 *
	 * @return array Decoded JSON response or `[]` while degraded.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function suggest(string $query, array $filterQueries = [], int $rows = 10): array {
		if ($this->breaker->isDegraded() === true) {
			return [];
		}

		$params = ['q' => $query, 'rows' => $rows];
		if (empty($filterQueries) === false) {
			$params['fq'] = $filterQueries;
		}

		return $this->call(method: 'suggest', params: $params);
	}//end suggest()

	/**
	 * Free-text geocoding call.
	 *
	 * @param string $query Free-text query.
	 * @param array $filterQueries Optional filter queries.
	 *
	 * @return array Decoded JSON response.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function free(string $query, array $filterQueries = []): array {
		$params = ['q' => $query];
		if (empty($filterQueries) === false) {
			$params['fq'] = $filterQueries;
		}

		return $this->call(method: 'free', params: $params);
	}//end free()

	/**
	 * Lookup a single suggest result by id.
	 *
	 * @param string $id Locatieserver result id (returned by suggest/free).
	 *
	 * @return array Decoded JSON response.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function lookup(string $id): array {
		return $this->call(method: 'lookup', params: ['id' => $id]);
	}//end lookup()

	/**
	 * Reverse geocode coordinates to nearest addresses.
	 *
	 * @param float $lat WGS84 latitude.
	 * @param float $lng WGS84 longitude.
	 *
	 * @return array Decoded JSON response.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function reverse(float $lat, float $lng): array {
		return $this->call(method: 'reverse', params: ['lat' => $lat, 'lon' => $lng]);
	}//end reverse()

	/**
	 * Current service health.
	 *
	 * @return string `ok` when healthy, `degraded` during the cool-down.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function health(): string {
		if ($this->breaker->isDegraded() === true) {
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
	 * @param array $params Query string parameters.
	 *
	 * @return array Decoded JSON body or `['response' => ['docs' => []]]`
	 *               when the call fails after exhausting retries.
	 */
	private function call(string $method, array $params): array {
		$read = $this->newCall(method: $method, params: $params);

		$cached = $this->cachedRead(read: $read);
		if ($cached !== null) {
			return $cached;
		}

		$read->started = microtime(as_float: true);
		$read->source = $this->appConfig->getValueString(AppIdentity::APP_ID, 'pdok_locatieserver_source', '');

		try {
			$body = $this->fetchBody(read: $read);
		} catch (RuntimeException $e) {
			return $this->failedRead(read: $read, exception: $e);
		}

		return $this->decodedAndCached(read: $read, body: $body);
	}//end call()

	/**
	 * Describe one Locatieserver read with its query string and cache key.
	 *
	 * @param string               $method Endpoint suffix.
	 * @param array<string, mixed> $params Query string parameters.
	 *
	 * @return LocatieserverCall
	 */
	private function newCall(string $method, array $params): LocatieserverCall {
		$read = new LocatieserverCall();
		$read->method = $method;
		$read->params = $params;
		$read->ttl = $this->ttlFor(method: $method);
		$read->queryString = http_build_query(data: $params);
		$read->cacheKey = $method . '_' . md5(string: $read->queryString);

		return $read;
	}//end newCall()

	/**
	 * The configured cache TTL of an endpoint: the suggest TTL for `suggest`, the lookup TTL otherwise.
	 *
	 * @param string $method Endpoint suffix.
	 *
	 * @return int Cache TTL in seconds.
	 */
	private function ttlFor(string $method): int {
		if ($method === 'suggest') {
			return (int)$this->appConfig->getValueString(
				AppIdentity::APP_ID,
				'pdok_cache_suggest_ttl_seconds',
				(string)self::DEFAULT_SUGGEST_TTL
			);
		}

		return (int)$this->appConfig->getValueString(
			AppIdentity::APP_ID,
			'pdok_cache_lookup_ttl_seconds',
			(string)self::DEFAULT_LOOKUP_TTL
		);
	}//end ttlFor()

	/**
	 * The cached answer for the read, logged as a cache hit, or null.
	 *
	 * @param LocatieserverCall $read The read.
	 *
	 * @return mixed The cached answer, or null on a miss.
	 */
	private function cachedRead(LocatieserverCall $read): mixed {
		$cached = $this->cache->get($read->cacheKey);
		if ($cached !== null) {
			$this->logger->debug(
				'Dossiq PDOK Locatieserver cache hit',
				['method' => $read->method, 'key' => $read->cacheKey]
			);
		}

		return $cached;
	}//end cachedRead()

	/**
	 * The raw body of the read: through the gateway when a source is set, otherwise from PDOK.
	 *
	 * @param LocatieserverCall $read The read.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the read failed.
	 */
	private function fetchBody(LocatieserverCall $read): string {
		$endpoint = $this->appConfig->getValueString(
			AppIdentity::APP_ID,
			'pdok_locatieserver_endpoint',
			self::DEFAULT_ENDPOINT
		);

		$url = rtrim(string: $endpoint, characters: '/') . '/' . $read->method . '?' . $read->queryString;

		if (empty($read->source) === false) {
			return $this->callViaOpenConnector(read: $read);
		}

		return $this->callDirect(url: $url);
	}//end fetchBody()

	/**
	 * Record a failed read towards the outage budget, log it, and answer no documents.
	 *
	 * @param LocatieserverCall $read      The read.
	 * @param RuntimeException  $exception Why it failed; its code is the HTTP status, or 0.
	 *
	 * @return array{response: array{docs: array}}
	 */
	private function failedRead(LocatieserverCall $read, RuntimeException $exception): array {
		$this->breaker->recordFailure(statusCode: $exception->getCode());
		$this->logger->warning(
			'Dossiq PDOK Locatieserver call failed',
			[
				'method' => $read->method,
				'error' => $exception->getMessage(),
				'status' => $exception->getCode(),
			]
		);
		return ['response' => ['docs' => []]];
	}//end failedRead()

	/**
	 * The decoded body, cached; a body that is no JSON object reads as no documents.
	 *
	 * @param LocatieserverCall $read The read.
	 * @param string            $body The raw body.
	 *
	 * @return array
	 */
	private function decodedAndCached(LocatieserverCall $read, string $body): array {
		$decoded = json_decode(json: $body, associative: true);
		if (is_array(value: $decoded) === false) {
			$decoded = ['response' => ['docs' => []]];
		}

		$this->cache->set($read->cacheKey, $decoded, $read->ttl);

		$elapsedMs = (int)((microtime(as_float: true) - $read->started) * 1000);
		$this->logger->info(
			'Dossiq PDOK Locatieserver call',
			[
				'method' => $read->method,
				'cache' => 'miss',
				'elapsedMs' => $elapsedMs,
				'viaSource' => (empty($read->source) === false),
			]
		);

		return $decoded;
	}//end decodedAndCached()

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
	 */
	private function callDirect(string $url): string {
		// Nextcloud's own HTTP client, not the `http://` stream wrapper. The
		// wrapper ignores the instance's proxy and certificate configuration
		// and is unavailable outright when `allow_url_fopen` is off, so on a
		// hardened or proxied deployment the direct PDOK path failed in a way
		// that looked like PDOK being down. IClientService honours config.php
		// (`proxy`, `proxyuserpwd`) and gives the status code directly instead
		// of it having to be parsed back out of `wrapper_data`.
		try {
			$response = $this->clientService->newClient()->get($url, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
		} catch (Throwable $e) {
			throw $this->requestFailure(exception: $e);
		}

		$statusCode = $response->getStatusCode();
		if ($statusCode < 200 || $statusCode >= 300) {
			throw new RuntimeException('PDOK Locatieserver HTTP ' . $statusCode, $statusCode);
		}

		$this->breaker->recordSuccess();
		return (string)$response->getBody();
	}//end callDirect()

	/**
	 * The exception a failed request raises: the upstream status, or a network error (logged).
	 *
	 * @param Throwable $exception The exception raised by the HTTP client.
	 *
	 * @return RuntimeException
	 */
	private function requestFailure(Throwable $exception): RuntimeException {
		$statusCode = $this->statusFromException(exception: $exception);
		if ($statusCode !== 0) {
			return new RuntimeException('PDOK Locatieserver HTTP ' . $statusCode, $statusCode);
		}

		$this->logger->warning(
			'PDOK Locatieserver request failed',
			['detail' => $exception->getMessage()]
		);
		return new RuntimeException('Network error contacting PDOK Locatieserver', 0);
	}//end requestFailure()

	/**
	 * Recover the upstream HTTP status from a client exception.
	 *
	 * The HTTP client raises on non-2xx, and the status lives on the
	 * exception's response rather than on the exception itself. Returns 0 when
	 * the failure carried no status — a genuine transport error — so the
	 * caller can tell "PDOK said no" from "PDOK was unreachable".
	 *
	 * @param Throwable $exception The exception raised by the HTTP client.
	 *
	 * @return int The HTTP status code, or 0 when there was none.
	 */
	private function statusFromException(Throwable $exception): int {
		if (method_exists($exception, 'getResponse') === true) {
			$response = $exception->getResponse();
			if (is_object($response) === true && method_exists($response, 'getStatusCode') === true) {
				$status = (int)$response->getStatusCode();
				if ($status >= 100 && $status < 600) {
					return $status;
				}
			}
		}

		$code = $exception->getCode();
		if (is_int($code) === true && $code >= 100 && $code < 600) {
			return $code;
		}

		return 0;
	}//end statusFromException()

	/**
	 * Dispatch through an OpenConnector source slug when configured.
	 *
	 * OpenConnector is an optional runtime dependency; the source slug carries
	 * its own credentials inside OpenConnector's vault, so no auth material is
	 * ever stored in Dossiq config. If OpenConnector is not installed the
	 * call falls back to a direct HTTP call against the configured endpoint.
	 *
	 * @param LocatieserverCall $read The read: its source slug, endpoint suffix and query parameters.
	 *
	 * @return string Raw response body.
	 *
	 * @throws \RuntimeException On upstream failure (code carries HTTP status).
	 */
	private function callViaOpenConnector(LocatieserverCall $read): string {
		try {
			// Resolved across every namespace integriq has shipped under. Pinned
			// to 'OCA\OpenConnector\...' this threw on any current instance and
			// the handler below logged "OpenConnector not available" and fell
			// back to direct HTTP — bypassing the gateway silently.
			$callService = FleetAppId::getService($this->container, 'integriq', 'Service\CallService')
				?? throw new RuntimeException('Integriq CallService is not available under any known namespace.');
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq PDOK Locatieserver: OpenConnector not available, falling back to direct HTTP',
				['sourceSlug' => $read->source, 'error' => $e->getMessage()]
			);
			return $this->directFallback(path: $read->method, params: $read->params);
		}

		// We intentionally call CallService dynamically; the signature has
		// varied across OpenConnector versions and we keep this loose to
		// avoid coupling Dossiq to a specific contract. The minimum
		// contract is `call(string $sourceSlug, string $endpoint, string
		// $method, array $config): array` returning a body string under
		// `['body']` or `['response']['body']`.
		try {
			$result = $callService->call(
				$read->source,
				$read->method,
				'GET',
				['query' => $read->params, 'headers' => ['Accept' => 'application/json']]
			);
		} catch (Throwable $e) {
			throw new RuntimeException(
				'OpenConnector dispatch failed: ' . $e->getMessage(),
				500
			);
		}

		return $this->gatewayBody(result: $result);
	}//end callViaOpenConnector()

	/**
	 * The body the gateway answered, as a string; an unknown shape is a refusal.
	 *
	 * The minimum contract is a body string under `['body']` or
	 * `['response']['body']`; an array body is encoded.
	 *
	 * @param mixed $result What the gateway answered.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the shape is not recognised (502).
	 */
	private function gatewayBody(mixed $result): string {
		if (is_array(value: $result) === true) {
			$body = ($result['body'] ?? ($result['response']['body'] ?? null));
			if (is_string(value: $body) === true) {
				$this->breaker->recordSuccess();
				return $body;
			}

			if (is_array(value: $body) === true) {
				$this->breaker->recordSuccess();
				return (string)json_encode(value: $body);
			}
		}

		throw new RuntimeException('OpenConnector returned an unrecognised response shape', 502);
	}//end gatewayBody()

	/**
	 * Call PDOK directly when the gateway is not there.
	 *
	 * @param string               $path   The endpoint suffix.
	 * @param array<string, mixed> $params The query string parameters.
	 *
	 * @return string The raw body.
	 *
	 * @throws RuntimeException When the direct call fails.
	 */
	private function directFallback(string $path, array $params): string {
		$endpoint = $this->appConfig->getValueString(
			AppIdentity::APP_ID,
			'pdok_locatieserver_endpoint',
			self::DEFAULT_ENDPOINT
		);
		return $this->callDirect(url: rtrim(string: $endpoint, characters: '/') . '/' . $path . '?' . http_build_query(data: $params));
	}//end directFallback()
}//end class
