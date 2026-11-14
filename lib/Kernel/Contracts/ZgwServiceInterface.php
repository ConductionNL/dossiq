<?php

/**
 * Contract of ZgwService
 *
 * The public surface of ZgwService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use OCA\Dossiq\Kernel\Contracts\ZgwBusinessRulesServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ZgwDocumentServiceInterface;
use OCA\OpenRegister\Db\Mapping;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Contract of ZgwService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ZgwServiceInterface {

	/**
	 * Map of ZGW API + resource to the config key suffix used in Dossiq.
	 *
	 * EVERY VALUE HERE IS A MAPPING KEY, NOT A SCHEMA NAME. It is the suffix of
	 * the `zgw_mapping_<key>` appconfig entry that `LoadDefaultZgwMappings`
	 * writes, so a value this repair step never writes resolves to no mapping
	 * at all and the endpoint answers 404 "No ZGW mapping configured".
	 *
	 * Two values used to be schema names instead: `zaken/zaken` said `case` and
	 * `documenten/verzendingen` said `dispatch`, while the repair step writes
	 * `zgw_mapping_zaak` and `zgw_mapping_verzending`. Nothing compared the two
	 * lists, so the whole ZRC zaken surface — the largest folder in both VNG
	 * contract collections — 404ed on every request and took its setUp cascade
	 * with it. ZgwResourceMapConsistencyTest now holds the two sides together.
	 *
	 * Requests per minute a ZGW consumer may spend on a plain read.
	 *
	 * The ZGW APIs are machine to machine. Nextcloud sees a JWT-authenticated
	 * consumer as anonymous, so `#[AnonRateLimit]` is the only throttle on
	 * them, and it buckets by remote address: behind a municipal reverse proxy
	 * every consumer shares one bucket.
	 *
	 * The old ceilings were 120 reads and 30 writes a minute. Measured on this
	 * tree, the two VNG contract collections peak at 180 writes and 35 reads
	 * inside one 60 second window, so the write tier was exceeded four times
	 * over by an ordinary conformance run. 133 of 646 business-rules requests
	 * came back as a bare 429, and a probe of 45 posts to one endpoint answered
	 * 30 times and then 429 fifteen times, exactly at the declared limit.
	 *
	 * These numbers keep a real ceiling, 20 reads and 10 writes a second, while
	 * admitting the traffic a single integration actually makes.
	 *
	 * PER-CONSUMER KEYING IS THE REAL ANSWER and this is not it. `AnonRateLimit`
	 * cannot key on the JWT client_id, so one noisy consumer still spends the
	 * budget of every consumer sharing its address. Tracked in #2460.
	 */
	public const RATE_LIMIT_READ = 1200;

	/**
	 * Requests per minute a ZGW consumer may spend on a write.
	 *
	 * Also covers the two reads that cost like a write: `zaken/_zoek` runs a
	 * full search, and the document download streams a file.
	 *
	 * See {@see self::RATE_LIMIT_READ} for the measurement behind both numbers.
	 */
	public const RATE_LIMIT_WRITE = 600;

	/**
	 * Apply inbound mapping (Dutch to English) to request data.
	 *
	 * @param array $body The Dutch-language request body
	 * @param object $mapping The inbound mapping entity
	 * @param array $mappingConfig The ZGW mapping configuration
	 *
	 * @return array The mapped English-language data
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function applyInboundMapping(
		array $body,
		object $mapping,
		array $mappingConfig,
	): array;

	/**
	 * Apply outbound mapping (English to Dutch) to an object.
	 *
	 * @param array $objectData The English-language object data
	 * @param object $mapping The outbound mapping entity
	 * @param array $mappingConfig The ZGW mapping configuration
	 * @param string $baseUrl The base URL for ZGW URL references
	 *
	 * @return array The mapped Dutch-language object
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function applyOutboundMapping(
		array $objectData,
		object $mapping,
		array $mappingConfig,
		string $baseUrl,
	): array;

	/**
	 * Build the base URL for ZGW API responses.
	 *
	 * @param IRequest $request The request object
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 *
	 * @return string The base URL
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function buildBaseUrl(IRequest $request, string $zgwApi, string $resource): string;

	/**
	 * Build the error response data from a validation result.
	 *
	 * @param array $ruleResult The validation result from ZgwBusinessRulesService
	 *
	 * @return array The error response data with detail and optional invalidParams
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function buildValidationError(array $ruleResult): array;

	/**
	 * Check if the current JWT consumer has a specific scope.
	 *
	 * @param IRequest $request The request object
	 * @param string $component The ZGW component (e.g. 'zrc', 'ztc', 'brc', 'drc')
	 * @param string $scope The required scope
	 *
	 * @return bool True if the consumer has the scope or heeftAlleAutorisaties
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function consumerHasScope(IRequest $request, string $component, string $scope): bool;

	/**
	 * Create a Mapping object for inbound (Dutch to English) transformation.
	 *
	 * @param array $mappingConfig The ZGW mapping configuration
	 *
	 * @return Mapping The inbound mapping entity
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function createInboundMapping(array $mappingConfig): object;

	/**
	 * Create a Mapping object for outbound (English to Dutch) transformation.
	 *
	 * @param array $mappingConfig The ZGW mapping configuration
	 *
	 * @return Mapping The outbound mapping entity
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function createOutboundMapping(array $mappingConfig): object;

	/**
	 * Get the business rules service.
	 *
	 * @return ZgwBusinessRulesServiceInterface
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function getBusinessRulesService(): ZgwBusinessRulesServiceInterface;

	/**
	 * Get the consumer's authorization details for a component (for zrc-006).
	 *
	 * Returns the authorization entries (autorisaties) for the given component,
	 * or null if the consumer has full access (superuser / no restrictions).
	 *
	 * @param IRequest $request The request object
	 * @param string $component The ZGW component (e.g. 'zrc')
	 *
	 * @return array|null Array of autorisatie entries, or null if unrestricted
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getConsumerAuthorisaties(IRequest $request, string $component): ?array;

	/**
	 * Get the OpenRegister ConsumerMapper.
	 *
	 * @return object|null
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function getConsumerMapper(): ?object;

	/**
	 * Get the document service.
	 *
	 * @return ZgwDocumentServiceInterface
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function getDocumentService(): ZgwDocumentServiceInterface;

	/**
	 * Get the logger.
	 *
	 * @return LoggerInterface
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function getLogger(): LoggerInterface;

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return object|null
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function getObjectService(): ?object;

	/**
	 * Get the request body, falling back to raw body parsing for malformed JSON.
	 *
	 * @param IRequest $request The request object
	 *
	 * @return array The parsed request body
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getRequestBody(IRequest $request): array;

	/**
	 * Get the ZGW mapping service.
	 *
	 * @return ZgwMappingServiceInterface
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function getZgwMappingService(): ZgwMappingServiceInterface;

	/**
	 * Handle audit trail index — proxies to OpenRegister's audit trail.
	 *
	 * @param IRequest $request The request object
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleAudittrailIndex(
		IRequest $request,
		string $zgwApi,
		string $resource,
		string $uuid,
	): JSONResponse;

	/**
	 * Handle audit trail show — proxies to OpenRegister's audit trail.
	 *
	 * @param IRequest $request The request object
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
	 * @param string $auditUuid The audit trail entry UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleAudittrailShow(
		IRequest $request,
		string $zgwApi,
		string $resource,
		string $uuid,
		string $auditUuid,
	): JSONResponse;

	/**
	 * Generic create operation for a ZGW resource.
	 *
	 * @param IRequest $request The request object
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 * @param bool $caseClosed Whether the parent zaak is closed (zrc-007)
	 * @param bool $hasForceer Whether the consumer has geforceerd-bijwerken scope
	 * @param bool $parentCaseTypeDraft Whether parent zaaktype is draft (ztc-010)
	 *
	 * @return JSONResponse
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   — ZGW scope flags from middleware
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleCreate(
		IRequest $request,
		string $zgwApi,
		string $resource,
		?bool $caseClosed = null,
		bool $hasForceer = true,
		?bool $parentCaseTypeDraft = null,
	): JSONResponse;

	/**
	 * Generic destroy (delete) operation for a ZGW resource.
	 *
	 * @param IRequest $request The request object
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
	 * @param bool $parentZtDraft Whether parent zaaktype is draft (ztc-010)
	 * @param bool $caseClosed Whether the parent zaak is closed (zrc-007)
	 * @param bool $hasForceer Whether consumer has geforceerd-bijwerken
	 *
	 * @return JSONResponse
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — ZGW scope flags from middleware
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleDestroy(
		IRequest $request,
		string $zgwApi,
		string $resource,
		string $uuid,
		?bool $parentZtDraft = null,
		?bool $caseClosed = null,
		bool $hasForceer = true,
	): JSONResponse;

	/**
	 * Generic index (list) operation for a ZGW resource.
	 *
	 * @param IRequest $request The request object
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleIndex(IRequest $request, string $zgwApi, string $resource): JSONResponse;

	/**
	 * Generic show (get single) operation for a ZGW resource.
	 *
	 * @param IRequest $request The request object
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleShow(
		IRequest $request,
		string $zgwApi,
		string $resource,
		string $uuid,
	): JSONResponse;

	/**
	 * Generic update (PUT/PATCH) operation for a ZGW resource.
	 *
	 * @param IRequest $request The request object
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
	 * @param bool $partial Whether this is a partial update (PATCH)
	 * @param bool $parentZtDraft Whether parent zaaktype is draft (ztc-010)
	 * @param bool $caseClosed Whether the parent zaak is closed (zrc-007)
	 * @param bool $hasForceer Whether consumer has geforceerd-bijwerken
	 *
	 * @return JSONResponse
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   — ZGW scope flags from middleware
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleUpdate(
		IRequest $request,
		string $zgwApi,
		string $resource,
		string $uuid,
		bool $partial = false,
		?bool $parentZtDraft = null,
		?bool $caseClosed = null,
		bool $hasForceer = true,
	): JSONResponse;

	/**
	 * Load ZGW mapping configuration.
	 *
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 *
	 * @return array|null The mapping configuration or null if not found
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function loadMappingConfig(string $zgwApi, string $resource): ?array;

	/**
	 * Return a "mapping not found" error response.
	 *
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function mappingNotFoundResponse(string $zgwApi, string $resource): JSONResponse;

	/**
	 * The answer for an object the store does not hold.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
	 */
	public function notFoundResponse(): JSONResponse;

	/**
	 * Publish the notification of a delete that happened, logging a failure instead of answering it.
	 *
	 * @param IRequest $request The request.
	 * @param string $zgwApi The ZGW API group.
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The deleted object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
	 */
	public function notifyDeleted(IRequest $request, string $zgwApi, string $resource, string $uuid): void;

	/**
	 * Publish a ZGW notification (non-blocking).
	 *
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 * @param string $resourceUrl The resource URL
	 * @param string $action The action (create, update, destroy)
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function publishNotification(
		string $zgwApi,
		string $resource,
		string $resourceUrl,
		string $action,
	): void;

	/**
	 * Resolve whether the parent zaaktype is in draft (concept) state.
	 *
	 * @param string $resource The ZGW resource name
	 * @param array $existingData The existing sub-resource object data
	 *
	 * @return bool|null True if draft, false if published, null if N/A
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function resolveParentZaaktypeDraft(string $resource, array $existingData): ?bool;

	/**
	 * Resolve parent zaaktype draft status from a request body (for sub-resource creation).
	 *
	 * Extracts the zaaktype URL/UUID from the body and looks up whether
	 * the zaaktype is still in draft (concept) state.
	 *
	 * @param string $resource The ZGW resource name
	 * @param array $body The request body (Dutch field names)
	 *
	 * @return bool|null True if draft, false if published, null if N/A
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function resolveParentZaaktypeDraftFromBody(string $resource, array $body): ?bool;

	/**
	 * Extract the UUID from the request URL path.
	 *
	 * Nextcloud's controller argument injection merges JSON body params into
	 * getParam(), so a body "uuid" field overrides the route's {uuid} param.
	 * This method extracts the UUID directly from the URL path to avoid that.
	 *
	 * @param IRequest $request The request object
	 * @param string $uuid The controller-injected UUID (potentially wrong)
	 *
	 * @return string The correct UUID from the URL path, or the fallback
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function resolvePathUuid(IRequest $request, string $uuid): string;

	/**
	 * Resolve whether a zaak is closed (has einddatum set).
	 *
	 * @param string $resource The ZGW resource name
	 * @param array $existingData The existing object data
	 *
	 * @return bool|null True if closed, false if open, null if N/A
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function resolveZaakClosed(string $resource, array $existingData): ?bool;

	/**
	 * Resolve whether a zaak is closed from a request body (for sub-resource creation).
	 *
	 * @param string $resource The ZGW resource name
	 * @param array $body The request body
	 *
	 * @return bool|null True if closed, false if open, null if N/A
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function resolveZaakClosedFromBody(string $resource, array $body): ?bool;

	/**
	 * The answer for a store call that failed: 404 not found, 403 not allowed, or 503 not reached.
	 *
	 * Every ZGW read, update and delete of a stored object answers its failures through here,
	 * so a store that was down never reads as an object that is not there (REQ-ZBR-023).
	 *
	 * @param \Throwable $error The failure.
	 * @param string $operation What was asked of the store (`ZGW delete`).
	 * @param string $object Which object (`besluiten <uuid>`).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
	 */
	public function storeFailureResponse(\Throwable $error, string $operation, string $object): JSONResponse;

	/**
	 * Translate ZGW query parameters to OpenRegister filter parameters.
	 *
	 * @param array $params The request query parameters
	 * @param array $mappingConfig The ZGW mapping configuration
	 *
	 * @return array Translated filter parameters
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function translateQueryParams(array $params, array $mappingConfig): array;

	/**
	 * Return an "OpenRegister unavailable" error response.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function unavailableResponse(): JSONResponse;

	/**
	 * Validate JWT-ZGW authentication from the Authorization header.
	 *
	 * @param IRequest $request The request object
	 *
	 * @return JSONResponse|null 401 response on failure, null on success
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function validateJwtAuth(IRequest $request): ?JSONResponse;

	/**
	 * Update a field in the cached request body.
	 *
	 * Used when pre-processing resolves a value (e.g., IOT omschrijving → UUID).
	 *
	 * @param string $key The field name
	 * @param mixed $value The new value
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function updateCachedBodyField(string $key, mixed $value): void;
}//end interface
