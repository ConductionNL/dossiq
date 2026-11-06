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
use OCA\Dossiq\Service\Outbound\OutboundUrlGuard;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Zgw\ZgwRuleLookups;
use OCA\Dossiq\Service\Zgw\ZgwRuleResults;
use Psr\Log\LoggerInterface;

/**
 * Base class for ZGW register-specific business rule services.
 *
 * Provides shared utilities: UUID extraction, URL validation,
 * external URL fetching, OpenRegister lookups, error builders.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
abstract class ZgwRulesBase {
	use SearchesObjects;

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
	 * Builds the answers of a rule: valid, refused, and the field a refusal names.
	 *
	 * @var ZgwRuleResults
	 */
	protected readonly ZgwRuleResults $results;

	/**
	 * Reads the store for the rules, in the request's context.
	 *
	 * A rules class hands this same object to a collaborator it builds, so the
	 * collaborator reads in the same context without being told it again.
	 *
	 * @var ZgwRuleLookups
	 */
	protected readonly ZgwRuleLookups $lookups;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param SettingsService $settingsService The settings service
	 * @param FieldValidator $fieldValidator The stateless field-format validator
	 * @param LanguageMapText $text Reads a translatable field of a stored type row as text
	 *
	 * @return void
	 */
	public function __construct(
		protected readonly LoggerInterface $logger,
		protected readonly SettingsService $settingsService,
		protected readonly FieldValidator $fieldValidator,
		protected readonly LanguageMapText $text = new LanguageMapText(),
	) {
		$this->results = new ZgwRuleResults(logger: $logger);
		$this->lookups = new ZgwRuleLookups(settingsService: $settingsService);
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
		$this->lookups->setContext(objectService: $objectService, mappingConfig: $mappingConfig);
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
		return $this->results->isValid(body: $body);
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
		return $this->results->error(status: $status, detail: $detail, invalidParams: $invalidParams, code: $code);
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
		return $this->results->fieldError(fieldName: $fieldName, code: $code, reason: $reason);
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
		return $this->results->fieldImmutableError(fieldName: $fieldName);
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
	 * The URL comes from a request body, so {@see OutboundUrlGuard} resolves it
	 * first: https only, no address inside the instance's own network. The
	 * request then goes to the address that was inspected, follows no redirect
	 * and verifies TLS.
	 *
	 * @param string $url The URL to fetch
	 *
	 * @return array|null The JSON response data, or null on failure
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function fetchExternalUrl(string $url): ?array {
		$guard = $this->outboundGuard();
		$address = $guard->publicAddress(url: $url);
		if ($address === null) {
			$this->logger->warning(
				'fetchExternalUrl blocked: URL failed SSRF safety check',
				['url' => substr($url, 0, 200)]
			);
			return null;
		}

		try {
			$body = $this->httpGet(
				url: $url,
				options: (['timeout' => 10] + $guard->requestOptions(url: $url, address: $address))
			);
			$data = json_decode($body, true);
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
	 * The guard every outbound fetch of a rules class goes through.
	 *
	 * Built here, not injected: a rules class is constructed in a dozen places
	 * from the same three collaborators, and the guard needs only the logger.
	 *
	 * @return OutboundUrlGuard
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	protected function outboundGuard(): OutboundUrlGuard {
		return new OutboundUrlGuard(logger: $this->logger);
	}//end outboundGuard()

	/**
	 * Make the GET request of an outbound fetch.
	 *
	 * Its own method so a test can see the options a fetch connects with:
	 * Guzzle is Nextcloud's, and is not there when the unit tests run.
	 *
	 * @param string $url The URL the guard approved.
	 * @param array<string, mixed> $options The request options, the guard's included.
	 *
	 * @return string The response body.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	protected function httpGet(string $url, array $options): string {
		return (string)(new Client())->get($url, $options)->getBody();
	}//end httpGet()

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
		return $this->lookups->findObjectByField(register: $register, schema: $schema, field: $field, value: $value);
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
		return $this->lookups->findAllObjectsByField(register: $register, schema: $schema, field: $field, value: $value);
	}//end findAllObjectsByField()

	/**
	 * A translatable field of a stored type row as text.
	 *
	 * Type names and titles are declared translatable, so a row read from the
	 * store carries them as a language map rather than a string.
	 *
	 * @param mixed $value A string or a language map.
	 *
	 * @return string The text, or '' when there is none.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	protected function textOf(mixed $value): string {
		return $this->text->textOf(value: $value);
	}//end textOf()

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
		return $this->lookups->findBySchemaKey(uuid: $uuid, schemaKey: $schemaKey);
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	protected function checkFieldUniqueness(
		string $field1Value,
		string $field1Search,
		string $field2Value,
		string $field2Search,
		string $errorField,
	): ?array {
		$taken = $this->lookups->fieldPairExists(
			field1Value: $field1Value,
			field1Search: $field1Search,
			field2Value: $field2Value,
			field2Search: $field2Search
		);
		if ($taken === false) {
			return null;
		}

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
	}//end checkFieldUniqueness()
}//end class
