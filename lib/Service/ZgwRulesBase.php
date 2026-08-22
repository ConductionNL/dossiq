<?php

/**
 * Dossiq ZGW Rules Base
 *
 * Shared utilities for ZGW business rule validation services.
 * Each register has its own rules service (ZgwZrcRulesService, etc.)
 * that extends this base.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use GuzzleHttp\Client;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Support\SuppressesWarnings;
use Psr\Log\LoggerInterface;

/**
 * Base class for ZGW register-specific business rule services.
 *
 * Provides shared utilities: UUID extraction, URL validation,
 * external URL fetching, OpenRegister lookups, error builders.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
abstract class ZgwRulesBase {
	use SearchesObjects;
	use SuppressesWarnings;

	/**
	 * RFC1918 + loopback + link-local + cloud-metadata CIDR blocks to deny in
	 * outbound HTTP requests from fetchExternalUrl (SSRF protection — WF1).
	 *
	 * Must be kept in sync with NotificatieService::BLOCKED_CIDRS.
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
	 * Ordered vertrouwelijkheidaanduiding severity levels (zrc-006).
	 *
	 * Used for consumer authorization filtering: a zaak's level must be
	 * less than or equal to the consumer's maxVertrouwelijkheidaanduiding.
	 * Lower integer = less sensitive.
	 *
	 * @var array<string, int>
	 */
	protected const VERTROUWELIJKHEID_LEVELS = [
		'openbaar' => 1,
		'beperkt_openbaar' => 2,
		'intern' => 3,
		'zaakvertrouwelijk' => 4,
		'vertrouwelijk' => 5,
		'confidentieel' => 6,
		'geheim' => 7,
		'zeer_geheim' => 8,
	];

	/**
	 * The OpenRegister ObjectService (set per-request).
	 *
	 * @var object|null
	 */
	protected ?object $objectService = null;

	/**
	 * The mapping config (set per-request).
	 *
	 * @var array|null
	 */
	protected ?array $mappingConfig = null;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param SettingsService $settingsService The settings service
	 * @param FieldValidator $fieldValidator The stateless field-format validator
	 *
	 * @return void
	 */
	public function __construct(
		protected readonly LoggerInterface $logger,
		protected readonly SettingsService $settingsService,
		protected readonly FieldValidator $fieldValidator,
	) {
	}//end __construct()

	/**
	 * Set the per-request services for cross-resource lookups.
	 *
	 * @param object|null $objectService The OpenRegister ObjectService
	 * @param array|null $mappingConfig The mapping config
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function setContext(?object $objectService, ?array $mappingConfig): void {
		$this->objectService = $objectService;
		$this->mappingConfig = $mappingConfig;
	}//end setContext()

	/**
	 * Build a successful validation result (pass-through).
	 *
	 * @param array $body The (possibly enriched) request body
	 *
	 * @return array{valid: bool, status: int, detail: string, enrichedBody: array}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function isValid(array $body): array {
		return [
			'valid' => true,
			'status' => 200,
			'detail' => '',
			'enrichedBody' => $body,
		];
	}//end isValid()

	/**
	 * Build a validation error result.
	 *
	 * @param int $status HTTP status code (400 or 403)
	 * @param string $detail Error detail message
	 * @param array $invalidParams Invalid parameter entries
	 * @param string $code Optional error code
	 *
	 * @return array{valid: bool, status: int, detail: string, invalidParams: array, enrichedBody: array}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function error(
		int $status,
		string $detail,
		array $invalidParams = [],
		string $code = '',
	): array {
		$result = [
			'valid' => false,
			'status' => $status,
			'detail' => $detail,
			'invalidParams' => $invalidParams,
			'enrichedBody' => [],
		];
		if ($code !== '') {
			$result['code'] = $code;
		}

		return $result;
	}//end error()

	/**
	 * Build a field-level validation error.
	 *
	 * @param string $fieldName The field name
	 * @param string $code The error code
	 * @param string $reason The error reason
	 *
	 * @return array{name: string, code: string, reason: string}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function fieldError(string $fieldName, string $code, string $reason): array {
		return [
			'name' => $fieldName,
			'code' => $code,
			'reason' => $reason,
		];
	}//end fieldError()

	/**
	 * Build a field immutability error response.
	 *
	 * @param string $fieldName The immutable field name
	 *
	 * @return array The validation error result
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function fieldImmutableError(string $fieldName): array {
		$detail = "Het veld {$fieldName} mag niet gewijzigd worden.";
		return $this->error(
			status: 400,
			detail: $detail,
			invalidParams: [
				$this->fieldError(
					fieldName: $fieldName,
					code: 'wijzigen-niet-toegelaten',
					reason: $detail
				),
			]
		);
	}//end fieldImmutableError()

	/**
	 * Extract a UUID from a URL or plain UUID string.
	 *
	 * @param string $url The URL or UUID
	 *
	 * @return string|null The extracted UUID, or null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function extractUuid(string $url): ?string {
		return $this->fieldValidator->extractUuid($url);
	}//end extractUuid()

	/**
	 * Check if a URL is syntactically valid.
	 *
	 * @param string $url The URL to check
	 *
	 * @return bool True if valid
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function isValidUrl(string $url): bool {
		return $this->fieldValidator->isValidUrl($url);
	}//end isValidUrl()

	/**
	 * Check if a value is a bare RFC-4122 UUID.
	 *
	 * @param string $value The candidate UUID
	 *
	 * @return bool True when the value is exactly a UUID
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-decomp-036
	 */
	protected function isUuid(string $value): bool {
		return $this->fieldValidator->isUuid($value);
	}//end isUuid()

	/**
	 * Check if a value is a valid ISO-8601 calendar date (YYYY-MM-DD).
	 *
	 * @param string $value The candidate date string
	 *
	 * @return bool True when the value is a real YYYY-MM-DD date
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-decomp-036
	 */
	protected function isValidDate(string $value): bool {
		return $this->fieldValidator->isValidDate($value);
	}//end isValidDate()

	/**
	 * Validate a type URL (zaaktype, besluittype, informatieobjecttype).
	 *
	 * Checks: URL format, UUID extraction, exists in OpenRegister,
	 * publication status (concept=false).
	 *
	 * @param string $typeUrl The type URL from the request body
	 * @param string $fieldName The field name for error reporting
	 * @param string $schemaKey The settings key for the type's schema
	 *
	 * @return array|null Validation error, or null if valid
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function validateTypeUrl(string $typeUrl, string $fieldName, string $schemaKey): ?array {
		$extractedUuid = $this->extractUuid(url: $typeUrl);
		if ($extractedUuid === null) {
			return $this->error(
				status: 400,
				detail: "De {$fieldName} URL is ongeldig.",
				invalidParams: [
					$this->fieldError(
						fieldName: $fieldName,
						code: 'bad-url',
						reason: "De {$fieldName} URL is ongeldig of wijst niet naar een {$fieldName} resource."
					),
				]
			);
		}

		$register = $this->mappingConfig['sourceRegister'] ?? '';
		$schema = $this->settingsService->getConfigValue(key: $schemaKey);

		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		try {
			$typeObject = $this->objectService->find(
				id: $extractedUuid,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			return $this->error(
				status: 400,
				detail: "De {$fieldName} URL is ongeldig.",
				invalidParams: [
					$this->fieldError(
						fieldName: $fieldName,
						code: 'bad-url',
						reason: "De {$fieldName} URL is ongeldig of wijst niet naar een {$fieldName} resource."
					),
				]
			);
		}

		$typeData = $typeObject;
		if (is_array($typeObject) === false) {
			$typeData = $typeObject->jsonSerialize();
		}

		$isDraft = $typeData['isDraft'] ?? true;
		if ($isDraft === true) {
			return $this->error(
				status: 400,
				detail: ucfirst($fieldName) . ' is nog in concept.',
				invalidParams: [
					$this->fieldError(
						fieldName: $fieldName,
						code: 'not-published',
						reason: ucfirst($fieldName) . ' is nog in concept.'
					),
				]
			);
		}

		return null;
	}//end validateTypeUrl()

	/**
	 * Validate an informatieobject URL resolves to an existing document.
	 *
	 * @param string $ioUrl The informatieobject URL
	 *
	 * @return array|null Validation error, or null if valid
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function validateInformatieobjectUrl(string $ioUrl): ?array {
		if ($this->isValidUrl(url: $ioUrl) === false) {
			return $this->error(
				status: 400,
				detail: 'De informatieobject URL is ongeldig.',
				invalidParams: [
					$this->fieldError(
						fieldName: 'informatieobject',
						code: 'bad-url',
						reason: 'Ongeldige URL.'
					),
				]
			);
		}

		$ioUuid = $this->extractUuid(url: $ioUrl);

		// Brc-003a: If UUID extraction fails, the URL doesn't point to a valid resource.
		if ($ioUuid === null) {
			return $this->error(
				status: 400,
				detail: 'De informatieobject URL is ongeldig.',
				invalidParams: [
					$this->fieldError(
						fieldName: 'informatieobject',
						code: 'bad-url',
						reason: 'De informatieobject URL bevat geen geldig UUID.'
					),
				]
			);
		}

		// If we can look up the document in our own register, do so.
		// If the document is not found locally, that is acceptable — it may
		// be an external informatieobject managed by another DRC instance.
		// We only reject when the URL is syntactically invalid (checked above).
		if ($this->objectService !== null) {
			$register = $this->mappingConfig['sourceRegister'] ?? '';
			$docSchema = $this->settingsService->getConfigValue(key: 'document_schema');
			if ($register !== '' && $docSchema !== '') {
				try {
					$this->objectService->find(
						id: $ioUuid,
						register: $register,
						schema: $docSchema
					);
				} catch (\Throwable $e) {
					// Document not found locally — acceptable for external DRC URLs.
					$this->logger->debug(
						'Informatieobject UUID not found locally, assuming external: ' . $ioUuid
					);
				}
			}
		}

		return null;
	}//end validateInformatieobjectUrl()

	/**
	 * Validate an external URL is reachable (basic URL + UUID format check).
	 *
	 * @param string $url The URL to validate
	 * @param string $fieldName The field name for error reporting
	 *
	 * @return array|null Validation error, or null if valid
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function validateExternalUrl(string $url, string $fieldName): ?array {
		if ($this->isValidUrl(url: $url) === false) {
			return $this->error(
				status: 400,
				detail: "De {$fieldName} URL is ongeldig.",
				invalidParams: [
					$this->fieldError(
						fieldName: $fieldName,
						code: 'bad-url',
						reason: "De {$fieldName} URL is ongeldig."
					),
				]
			);
		}

		$path = parse_url($url, PHP_URL_PATH) ?? '';
		$segments = array_filter(explode('/', $path));
		$lastSegment = end($segments);
		if ($lastSegment === false) {
			$lastSegment = '';
		}

		if ($this->fieldValidator->isUuid($lastSegment) === false) {
			return $this->error(
				status: 400,
				detail: "De {$fieldName} URL wijst niet naar een geldig object.",
				invalidParams: [
					$this->fieldError(
						fieldName: $fieldName,
						code: 'invalid-resource',
						reason: "De {$fieldName} URL wijst niet naar een geldig object."
					),
				]
			);
		}

		return null;
	}//end validateExternalUrl()

	/**
	 * Fetch data from an external URL (selectielijst, resultaattypeomschrijving).
	 *
	 * WF1 fix: added SSRF guard (rejects RFC1918/loopback/link-local/cloud-metadata
	 * addresses), TLS verification enabled (verify => true), and redirect following
	 * disabled (allow_redirects => false) to prevent redirect-based bypasses.
	 *
	 * @param string $url The URL to fetch
	 *
	 * @return array|null The JSON response data, or null on failure
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function fetchExternalUrl(string $url): ?array {
		// WF1: SSRF guard — reject private/loopback/cloud-metadata URLs before
		// establishing any outbound connection.
		if ($this->isSafeExternalUrl(url: $url) === false) {
			$this->logger->warning(
				'fetchExternalUrl blocked: URL failed SSRF safety check',
				['url' => substr($url, 0, 200)]
			);
			return null;
		}

		try {
			$client = new Client(
				[
					'timeout' => 10,
					'verify' => true,
					'allow_redirects' => false,
				]
			);
			$response = $client->get($url);
			$data = json_decode((string)$response->getBody(), true);
			if (is_array($data) === false) {
				return null;
			}

			return $data;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Failed to fetch external URL: ' . $e->getMessage(),
				['url' => $url]
			);
			return null;
		}//end try
	}//end fetchExternalUrl()

	/**
	 * Validate that a URL is safe for outbound fetching (SSRF guard).
	 *
	 * Requires http or https scheme and verifies that the hostname resolves
	 * only to public IP addresses — rejects RFC1918, loopback, link-local,
	 * and cloud-metadata addresses (169.254.169.254).
	 *
	 * Returns false (fail-closed) when the URL is unparseable, the hostname
	 * resolves to no records, or any resolved address falls in a blocked CIDR.
	 *
	 * @param string $url The URL to validate
	 *
	 * @return bool True if the URL is safe for outbound fetching
	 */
	private function isSafeExternalUrl(string $url): bool {
		$parsed = parse_url($url);
		$scheme = strtolower($parsed['scheme'] ?? '');

		if (in_array($scheme, ['http', 'https'], true) === false) {
			return false;
		}

		$host = $parsed['host'] ?? '';
		if ($host === '') {
			return false;
		}

		// Resolve all A/AAAA records and block private ranges.
		$records = $this->withoutWarnings(
			operation: static function () use ($host): mixed {
				return dns_get_record($host, (DNS_A | DNS_AAAA));
			}
		);
		if ($records === false || count($records) === 0) {
			$this->logger->warning(
				'fetchExternalUrl SSRF: DNS resolution returned no records',
				['host' => $host, 'detail' => $this->lastSuppressedWarning()]
			);
			return false;
		}

		foreach ($records as $record) {
			$ipAddress = $record['ip'] ?? ($record['ipv6'] ?? null);
			if ($ipAddress === null) {
				continue;
			}

			foreach (self::BLOCKED_CIDRS as $cidr) {
				if ($this->ipInCidr(ipAddress: $ipAddress, cidr: $cidr) === true) {
					$this->logger->warning(
						'fetchExternalUrl SSRF: host resolves to private/loopback address',
						['host' => $host, 'ip' => $ipAddress, 'cidr' => $cidr]
					);
					return false;
				}
			}
		}

		return true;
	}//end isSafeExternalUrl()

	/**
	 * Check if an IP address falls within a CIDR range (IPv4 and IPv6).
	 *
	 * @param string $ipAddress The IP address to test
	 * @param string $cidr The CIDR block (e.g. '10.0.0.0/8')
	 *
	 * @return bool True if the IP is within the range
	 */
	private function ipInCidr(string $ipAddress, string $cidr): bool {
		$isIpv6Cidr = str_contains($cidr, ':');
		$isIpv6Ip = str_contains($ipAddress, ':');

		if ($isIpv6Cidr === true && $isIpv6Ip === true) {
			return $this->ipv6InCidr(ipAddress: $ipAddress, cidr: $cidr);
		}

		if ($isIpv6Cidr === false && $isIpv6Ip === false) {
			return $this->ipv4InCidr(ipAddress: $ipAddress, cidr: $cidr);
		}

		// Mixed IPv4/IPv6 — not in range.
		return false;
	}//end ipInCidr()

	/**
	 * Check if an IPv6 address falls within an IPv6 CIDR range.
	 *
	 * @param string $ipAddress The IPv6 address to test
	 * @param string $cidr The IPv6 CIDR block (e.g. 'fc00::/7')
	 *
	 * @return bool True if the IP is within the range
	 */
	private function ipv6InCidr(string $ipAddress, string $cidr): bool {
		[$network, $prefix] = explode('/', $cidr);
		$prefixLen = (int)$prefix;
		$networkBin = inet_pton($network);
		$ipBin = inet_pton($ipAddress);
		if ($networkBin === false || $ipBin === false) {
			return false;
		}

		$bytes = (int)ceil($prefixLen / 8);
		$mask = str_repeat("\xff", intdiv($prefixLen, 8));
		$remain = $prefixLen % 8;
		if ($remain > 0) {
			$mask .= chr(0xff & (0xff << (8 - $remain)));
		}

		$mask = str_pad($mask, 16, "\x00");
		return (substr($ipBin, 0, $bytes) & $mask) === (substr($networkBin, 0, $bytes) & $mask);
	}//end ipv6InCidr()

	/**
	 * Check if an IPv4 address falls within an IPv4 CIDR range.
	 *
	 * @param string $ipAddress The IPv4 address to test
	 * @param string $cidr The IPv4 CIDR block (e.g. '10.0.0.0/8')
	 *
	 * @return bool True if the IP is within the range
	 */
	private function ipv4InCidr(string $ipAddress, string $cidr): bool {
		[$network, $prefix] = explode('/', $cidr);
		$prefixLen = (int)$prefix;
		$mask = 0;
		if ($prefixLen !== 0) {
			$mask = (~0 << (32 - $prefixLen));
		}

		$networkLong = ip2long($network);
		$ipLong = ip2long($ipAddress);
		if ($networkLong === false || $ipLong === false) {
			return false;
		}

		return ($ipLong & $mask) === ($networkLong & $mask);
	}//end ipv4InCidr()

	/**
	 * Generate a unique identificatie string.
	 *
	 * @param string $prefix A prefix for the identifier (e.g. 'ZAAK', 'BESLUIT')
	 *
	 * @return string A unique identifier
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function generateIdentificatie(string $prefix): string {
		$timestamp = strtoupper(base_convert((string)time(), 10, 36));
		$random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

		return $prefix . '-' . $timestamp . '-' . $random;
	}//end generateIdentificatie()

	/**
	 * Find an object UUID by a field value (omschrijving/identificatie).
	 *
	 * @param string $register The OpenRegister register ID
	 * @param string $schema The OpenRegister schema ID
	 * @param string $field The field to search by
	 * @param string $value The value to search for
	 *
	 * @return string|null The object UUID, or null if not found
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function findObjectByField(
		string $register,
		string $schema,
		string $field,
		string $value,
	): ?string {
		try {
			$query = $this->objectService->buildSearchQuery(
				requestParams: [$field => $value, '_limit' => 1],
				register: $register,
				schema: $schema
			);
			$result = $this->objectService->searchObjectsPaginated(query: $query);

			$results = $result['results'] ?? [];
			if (empty($results) === true) {
				return null;
			}

			$obj = $results[0];
			$data = $obj;
			if (is_array($obj) === false) {
				$data = $obj->jsonSerialize();
			}

			return $data['id'] ?? ($data['@self']['id'] ?? null);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Reference resolution failed: ' . $e->getMessage(),
				['field' => $field, 'value' => $value]
			);
			return null;
		}//end try
	}//end findObjectByField()

	/**
	 * Find all objects matching a field value.
	 *
	 * @param string $register The register to search in
	 * @param string $schema The schema to search in
	 * @param string $field The field to match on
	 * @param string $value The field value to search for
	 *
	 * @return array<string> Array of matching object UUIDs
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function findAllObjectsByField(
		string $register,
		string $schema,
		string $field,
		string $value,
	): array {
		try {
			$query = $this->objectService->buildSearchQuery(
				requestParams: [$field => $value, '_limit' => 100],
				register: $register,
				schema: $schema
			);
			$result = $this->objectService->searchObjectsPaginated(query: $query);

			$ids = [];
			foreach (($result['results'] ?? []) as $obj) {
				$data = $obj;
				if (is_array($obj) === false) {
					$data = $obj->jsonSerialize();
				}

				$id = $data['id'] ?? ($data['@self']['id'] ?? null);
				if ($id !== null) {
					$ids[] = $id;
				}
			}

			return $ids;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Reference resolution failed: ' . $e->getMessage(),
				['field' => $field, 'value' => $value]
			);
			return [];
		}//end try
	}//end findAllObjectsByField()

	/**
	 * Look up an object in OpenRegister by UUID and schema key.
	 *
	 * @param string $uuid The object UUID
	 * @param string $schemaKey The settings config key for the schema
	 *
	 * @return array|null The object data, or null on failure
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function findBySchemaKey(string $uuid, string $schemaKey): ?array {
		if ($this->objectService === null) {
			return null;
		}

		$register = $this->mappingConfig['sourceRegister'] ?? '';
		$schema = $this->settingsService->getConfigValue(key: $schemaKey);

		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		try {
			$obj = $this->objectService->find(
				id: $uuid,
				register: $register,
				schema: $schema
			);
			if (is_array($obj) === true) {
				return $obj;
			}

			return $obj->jsonSerialize();
		} catch (\Throwable $e) {
			return null;
		}
	}//end findBySchemaKey()

	/**
	 * Check unique combination of two fields (identificatie + organisatie).
	 *
	 * @param string $field1Value First field value (e.g. identificatie)
	 * @param string $field1Search OpenRegister field name to search
	 * @param string $field2Value Second field value (e.g. organisatie)
	 * @param string $field2Search OpenRegister field name to search
	 * @param string $errorField Field name for error reporting
	 *
	 * @return array|null Validation error if duplicate found, null if unique
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function checkFieldUniqueness(
		string $field1Value,
		string $field1Search,
		string $field2Value,
		string $field2Search,
		string $errorField,
	): ?array {
		if ($field1Value === '' || $this->objectService === null) {
			return null;
		}

		$register = $this->mappingConfig['sourceRegister'] ?? '';
		$schema = $this->mappingConfig['sourceSchema'] ?? '';
		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		try {
			// Build query directly to avoid buildSearchQuery's underscore-splitting
			// which breaks camelCase field names like sourceOrganisation.
			// Search only by field1 (identifier) because OpenRegister may store
			// numeric strings (e.g. "000000000") as integers, which breaks
			// exact-match search for field2 (sourceOrganisation).
			$query = [
				'@self' => [
					'register' => (int)$register,
					'schema' => (int)$schema,
				],
				$field1Search => $field1Value,
			];

			$result = $this->objectService->searchObjectsPaginated(
				query: $query,
				_rbac: false,
				_multitenancy: false
			);

			// Post-filter results by field2 value in memory, comparing both
			// string and numeric forms to handle integer coercion by OpenRegister.
			// OpenRegister may store numeric-looking strings (e.g. "000000000")
			// as integer 0, which the magic mapper may serialize to empty string.
			// When the stored value is empty but field2 was provided, we still
			// count it as a match (conservative: assume coercion happened).
			$matchCount = 0;
			foreach (($result['results'] ?? []) as $obj) {
				$data = $obj;
				if (is_array($obj) === false) {
					$data = $obj->jsonSerialize();
				}

				$storedVal = $data[$field2Search] ?? null;
				$storedStr = (string)$storedVal;
				$compareStr = (string)$field2Value;

				// Match when: no field2 filter, or values match directly,
				// or stored is empty/0 (likely coerced from numeric string).
				$isMatch = ($field2Value === '')
					|| ($storedStr === $compareStr)
					|| ($storedStr === '')
					|| ($storedStr === '0' && preg_match('/^0+$/', $field2Value) === 1);

				if ($isMatch === true) {
					$matchCount++;
				}
			}//end foreach

			if ($matchCount > 0) {
				return $this->error(
					status: 400,
					detail: 'De combinatie is niet uniek.',
					invalidParams: [
						$this->fieldError(
							fieldName: $errorField,
							code: 'identificatie-niet-uniek',
							reason: 'De combinatie bestaat al.'
						),
					]
				);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Uniqueness check failed: ' . $e->getMessage()
			);
		}//end try

		return null;
	}//end checkFieldUniqueness()
}//end class
