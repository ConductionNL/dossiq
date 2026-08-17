<?php

/**
 * Procest OpenCatalogi API Client.
 *
 * The boundary for every outbound call this app makes to OpenCatalogi's
 * publication model. OpenCatalogi is a peer app on the SAME Nextcloud
 * instance, and its own `PublicationsController` exposes no write endpoint
 * (index/show/uses/used/attachments/download only — confirmed against
 * `origin/development` in the opencatalogi repo). Both OpenCatalogi's own
 * backend (`PublicationService::getObjectService()`) and frontend
 * (`src/store/modules/object.js`) create/update/withdraw publications through
 * OpenRegister instead, addressing the register/schema OpenCatalogi ships by
 * default (`lib/Settings/publication_register.json`: register slug
 * `publication`, schemas `publication`/`document`).
 *
 * This client reaches OpenRegister **in process**, through the contract
 * OpenRegister publishes (ADR-084 `ObjectServiceInterface`) — not through an
 * authenticated HTTP request from this instance to itself. That HTTP hop is
 * what ADR-080 D2/D3 forbids and hydra gate-62 names; it also cost a full
 * request cycle per object and needed a stored service account with a real app
 * password. See
 * `openspec/changes/woo-publication-in-process-object-writes/proposal.md`,
 * including the two things measured while writing it:
 *
 *   - `ObjectServiceInterface::updateObject()` is documented as a partial
 *     update and implemented as a full replace, and the merging write
 *     (`patchObject()`) is not on the contract. `updatePublication()`
 *     therefore does its own read-merge-write, exactly as OpenRegister's
 *     `objects#patch` route does.
 *   - The contract publishes no file operation at all, so `attachFile()` uses
 *     OpenRegister's `FileService` directly. Recorded as a gap.
 *
 * "Publish" and "withdraw" are not separate operations either — a publication
 * is live when `publicatiedatum` is a past date, and withdrawn when
 * `depublicatiedatum` is a past date (per the schema's own field
 * descriptions); both are set through {@see self::updatePublication()}.
 *
 * `resolveCatalog()` is the one call that stays on HTTP: it reads
 * OpenCatalogi's OWN catalog listing, which is not OpenRegister's Objects API
 * and is therefore outside ADR-080 D2/D3.
 *
 * @category Service
 * @package  OCA\Procest\Service\WooPublication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-publication-in-process-object-writes/specs/woo-publication-via-opencatalogi/spec.md
 * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d1
 * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\WooPublication;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SettingsService;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Writes OpenCatalogi's publication model through OpenRegister in process.
 *
 * @spec openspec/changes/woo-publication-in-process-object-writes/specs/woo-publication-via-opencatalogi/spec.md
 */
class OpenCatalogiApiClient {

	/**
	 * OpenCatalogi's public catalog-listing endpoint (discovery only, D-Fallback).
	 *
	 * This is OpenCatalogi's own app API. It is deliberately the only route
	 * this class still fetches over HTTP.
	 *
	 * @var string
	 */
	private const CATALOGI_PATH = '/index.php/apps/opencatalogi/api/catalogi';

	/**
	 * Request timeout in seconds, for the one remaining HTTP call.
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 15;

	/**
	 * The single domain error every failure of this client surfaces as.
	 *
	 * `WooPublicationService` catches `Throwable` around each call and maps it
	 * to `['available' => false, 'reason' => 'opencatalogi_api_error']`, so
	 * keeping this exact message keeps that behaviour identical across the
	 * transport change.
	 *
	 * @var string
	 */
	private const DOMAIN_ERROR = 'opencatalogi_api_error';

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService HTTP client factory (catalog discovery only).
	 * @param IURLGenerator $urlGenerator Resolves this Nextcloud instance's own base URL.
	 * @param IAppConfig $appConfig App config (service-account credentials for discovery).
	 * @param SettingsService $settingsService Resolves OpenRegister's ObjectService/FileService.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a publication object in OpenCatalogi's publication register.
	 *
	 * @param string $register The publication register slug.
	 * @param string $schema The publication schema slug.
	 * @param array<string, mixed> $payload The publication fields.
	 *
	 * @return array<string, mixed> The created object.
	 *
	 * @throws RuntimeException 'opencatalogi_api_error' on any write failure.
	 *
	 * @spec openspec/changes/woo-publication-in-process-object-writes/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function createPublication(string $register, string $schema, array $payload): array {
		return $this->storeObject(register: $register, schema: $schema, object: $payload, uuid: null);
	}//end createPublication()

	/**
	 * Update an existing publication object — used for republish and for
	 * setting `depublicatiedatum` on withdraw.
	 *
	 * PATCH semantics, done as a read-merge-write. This is not a stylistic
	 * choice: `ObjectServiceInterface::saveObject()` is PUT-semantic (a
	 * property absent from the payload is written as null), and
	 * `ObjectServiceInterface::updateObject()` does NOT merge despite its
	 * docblock — its body assigns `$data['id']` and calls `saveObject()`. The
	 * merging write, `patchObject()`, is not on the published contract. With a
	 * single-key payload such as withdraw's `['depublicatiedatum' => now]`, a
	 * bare save would erase the publication's title, summary, dates and
	 * category while reporting success. OpenRegister's own `objects#patch`
	 * route merges for exactly this reason; this reproduces it.
	 *
	 * @param string $register The publication register slug.
	 * @param string $schema The publication schema slug.
	 * @param string $id The publication object id.
	 * @param array<string, mixed> $payload The fields to update.
	 *
	 * @return array<string, mixed> The updated object.
	 *
	 * @throws RuntimeException 'opencatalogi_api_error' on any read/write failure.
	 *
	 * @spec openspec/changes/woo-publication-in-process-object-writes/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function updatePublication(string $register, string $schema, string $id, array $payload): array {
		$stored = $this->readObjectData(register: $register, schema: $schema, id: $id);

		return $this->storeObject(
			register: $register,
			schema: $schema,
			object: array_merge($stored, $payload),
			uuid: $id,
		);
	}//end updatePublication()

	/**
	 * Create a `document` object linked to a publication.
	 *
	 * @param string $register The register slug (same register as the publication).
	 * @param string $schema The document schema slug.
	 * @param array<string, mixed> $payload The document fields (must include `publication`).
	 *
	 * @return array<string, mixed> The created document object.
	 *
	 * @throws RuntimeException 'opencatalogi_api_error' on any write failure.
	 *
	 * @spec openspec/changes/woo-publication-in-process-object-writes/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function attachDocument(string $register, string $schema, array $payload): array {
		return $this->storeObject(register: $register, schema: $schema, object: $payload, uuid: null);
	}//end attachDocument()

	/**
	 * Attach file bytes to an object (publication or document).
	 *
	 * `ObjectServiceInterface` publishes no file operation, so this goes
	 * through OpenRegister's `FileService::addFile()`, which is precisely what
	 * OpenRegister's own `files#create` route calls. `addFile()` accepts the
	 * object UUID as a string and resolves it itself, and it base64-decodes
	 * string content (and strips a `data:` prefix) before writing — so the
	 * base64 body this method receives is passed through unchanged, exactly as
	 * the HTTP route passed it.
	 *
	 * `$mimeType` is accepted for call-shape compatibility and is NOT used:
	 * `FileService::addFile()` has no MIME parameter and
	 * `FilesController::create()` reads only `name`/`filename`, `content`,
	 * `share` and `tags` out of the request body — so the HTTP route this
	 * replaces discarded it too. It stays on the signature because dropping a
	 * parameter would change the call shape at its one caller.
	 *
	 * @param string $register The register slug (unused by the in-process file API — the object id is the anchor).
	 * @param string $schema The schema slug (unused by the in-process file API — the object id is the anchor).
	 * @param string $objectId The object id to attach the file to.
	 * @param string $fileName The file name.
	 * @param string $base64Content The base64-encoded file content.
	 * @param string $mimeType The file MIME type (accepted, not used — see above).
	 *
	 * @return array<string, mixed> The stored file's metadata.
	 *
	 * @throws RuntimeException 'opencatalogi_api_error' on any attach failure.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) register/schema/mimeType are call-shape compatibility — see the docblock.
	 *
	 * @spec openspec/changes/woo-publication-in-process-object-writes/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function attachFile(
		string $register,
		string $schema,
		string $objectId,
		string $fileName,
		string $base64Content,
		string $mimeType,
	): array {
		$fileService = $this->settingsService->getFileService();
		if ($fileService === null) {
			$this->logger->warning(
				'OpenCatalogiApiClient: OpenRegister FileService unavailable',
				['app' => Application::APP_ID, 'objectId' => $objectId],
			);
			throw new RuntimeException(self::DOMAIN_ERROR);
		}

		try {
			$file = $fileService->addFile(
				objectEntity: $objectId,
				fileName: $fileName,
				content: $base64Content,
				share: false,
				tags: [],
			);

			$formatted = $fileService->formatFile($file);
			if (is_array($formatted) === true) {
				return $formatted;
			}

			return [];
		} catch (Throwable $e) {
			$this->logger->warning(
				'OpenCatalogiApiClient: file attach failed',
				[
					'app' => Application::APP_ID,
					'objectId' => $objectId,
					'fileName' => $fileName,
					'error' => $e->getMessage(),
				],
			);
			throw new RuntimeException(self::DOMAIN_ERROR, 0, $e);
		}//end try
	}//end attachFile()

	/**
	 * Best-effort discovery of a WOO-flagged OpenCatalogi catalog.
	 *
	 * Never gates publication — see design.md "Fallback". A failure here is
	 * logged and swallowed; the caller keeps using the configured
	 * register/schema defaults regardless.
	 *
	 * This reads OpenCatalogi's own app API, not OpenRegister's Objects API,
	 * so it stays an HTTP call.
	 *
	 * @return array<string, mixed>|null The first `hasWooSitemap: true` catalog, or null.
	 *
	 * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#fallback
	 */
	public function resolveCatalog(): ?array {
		try {
			$result = $this->fetchCatalogi();
		} catch (Throwable $e) {
			$this->logger->info(
				'OpenCatalogiApiClient::resolveCatalog: discovery call failed, continuing with defaults',
				['app' => Application::APP_ID, 'error' => $e->getMessage()],
			);
			return null;
		}

		$catalogs = ($result['results'] ?? $result['data'] ?? null);
		if ($catalogs === null) {
			$catalogs = $result;
		}

		if (is_array($catalogs) === false) {
			return null;
		}

		foreach ($catalogs as $catalog) {
			if (is_array($catalog) === true && ($catalog['hasWooSitemap'] ?? false) === true) {
				return $catalog;
			}
		}

		return null;
	}//end resolveCatalog()

	/**
	 * Resolve OpenRegister's ObjectService, or fail with the domain error.
	 *
	 * `WooPublicationService::checkAvailability()` already refuses to call this
	 * client when OpenRegister is unavailable, so a null here is exceptional
	 * rather than routine — but it must not be dereferenced, and it must
	 * surface the same error the transport failures do.
	 *
	 * @return object The OpenRegister ObjectService.
	 *
	 * @throws RuntimeException 'opencatalogi_api_error' when OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/woo-publication-in-process-object-writes/specs/woo-publication-via-opencatalogi/spec.md
	 */
	private function requireObjectService(): object {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$this->logger->warning(
				'OpenCatalogiApiClient: OpenRegister ObjectService unavailable',
				['app' => Application::APP_ID],
			);
			throw new RuntimeException(self::DOMAIN_ERROR);
		}

		return $objectService;
	}//end requireObjectService()

	/**
	 * Read an object's stored property data, for the merge half of a PATCH.
	 *
	 * Uses `findSilent()` rather than `find()` so a publication update does not
	 * write a read entry into the audit trail — the same choice OpenRegister's
	 * `objects#patch` route makes. `_rbac` and `_multitenancy` stay at their
	 * contract defaults (both `true`), which is STRICTER than that route's
	 * internal read: the merge cannot pull in an object the caller may not see.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $id The object id.
	 *
	 * @return array<string, mixed> The stored property data.
	 *
	 * @throws RuntimeException 'opencatalogi_api_error' when the object cannot be read.
	 *
	 * @spec openspec/changes/woo-publication-in-process-object-writes/specs/woo-publication-via-opencatalogi/spec.md
	 */
	private function readObjectData(string $register, string $schema, string $id): array {
		$objectService = $this->requireObjectService();

		try {
			$stored = $objectService->findSilent(
				id: $id,
				register: $register,
				schema: $schema,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'OpenCatalogiApiClient: could not read the object to patch',
				['app' => Application::APP_ID, 'id' => $id, 'error' => $e->getMessage()],
			);
			throw new RuntimeException(self::DOMAIN_ERROR, 0, $e);
		}

		return $this->objectData(entity: $stored);
	}//end readObjectData()

	/**
	 * Persist an object through the published contract and return it as an array.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $object The object data to store.
	 * @param string|null $uuid The uuid to update, or null to create.
	 *
	 * @return array<string, mixed> The stored object, with its id.
	 *
	 * @throws RuntimeException 'opencatalogi_api_error' on any write failure.
	 *
	 * @spec openspec/changes/woo-publication-in-process-object-writes/specs/woo-publication-via-opencatalogi/spec.md
	 */
	private function storeObject(string $register, string $schema, array $object, ?string $uuid): array {
		$objectService = $this->requireObjectService();

		try {
			$saved = $objectService->saveObject(
				object: $object,
				register: $register,
				schema: $schema,
				uuid: $uuid,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'OpenCatalogiApiClient: object write failed',
				[
					'app' => Application::APP_ID,
					'register' => $register,
					'schema' => $schema,
					'uuid' => ($uuid ?? ''),
					'error' => $e->getMessage(),
				],
			);
			throw new RuntimeException(self::DOMAIN_ERROR, 0, $e);
		}//end try

		$data = $this->objectData(entity: $saved);
		$identifier = $this->objectIdentifier(entity: $saved);
		if ($identifier !== null) {
			$data['id'] = $identifier;
		}

		return $data;
	}//end storeObject()

	/**
	 * The property data of whatever OpenRegister returned.
	 *
	 * `ObjectServiceInterface` returns `ObjectEntityInterface`, whose
	 * `getObject(): array` is the property data. The service is resolved from
	 * the container as an untyped `object`, so the shape is checked rather than
	 * assumed — and an already-array return is accepted for the same reason.
	 *
	 * @param mixed $entity Whatever the object service returned.
	 *
	 * @return array<string, mixed> The property data.
	 *
	 * @throws RuntimeException 'opencatalogi_api_error' when the return cannot be read.
	 */
	private function objectData(mixed $entity): array {
		if (is_array($entity) === true) {
			return $entity;
		}

		if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
			$data = $entity->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		$this->logger->warning(
			'OpenCatalogiApiClient: OpenRegister returned an unreadable object shape',
			['app' => Application::APP_ID, 'type' => get_debug_type($entity)],
		);
		throw new RuntimeException(self::DOMAIN_ERROR);
	}//end objectData()

	/**
	 * The stored object's identifier, which the caller reads back as `id`.
	 *
	 * @param mixed $entity Whatever the object service returned.
	 *
	 * @return string|null The uuid, or null when the shape carries none.
	 */
	private function objectIdentifier(mixed $entity): ?string {
		if (is_object($entity) === true && method_exists($entity, 'getUuid') === true) {
			$uuid = $entity->getUuid();
			if (is_string($uuid) === true && $uuid !== '') {
				return $uuid;
			}
		}

		if (is_array($entity) === true) {
			$identifier = ($entity['id'] ?? $entity['uuid'] ?? null);
			if (is_string($identifier) === true && $identifier !== '') {
				return $identifier;
			}
		}

		return null;
	}//end objectIdentifier()

	/**
	 * GET OpenCatalogi's catalog listing and decode it.
	 *
	 * The only HTTP call left in this class. OpenCatalogi's routes return plain
	 * JSON, not an OCS envelope, so no envelope unwrapping is needed.
	 *
	 * @return array<string, mixed> The decoded listing.
	 *
	 * @throws RuntimeException 'opencatalogi_api_error' on any transport/decode failure.
	 */
	private function fetchCatalogi(): array {
		$url = rtrim($this->urlGenerator->getBaseUrl(), '/') . self::CATALOGI_PATH;

		$options = [
			'timeout' => self::TIMEOUT_SECONDS,
			'headers' => [
				'OCS-APIREQUEST' => 'true',
				'Accept' => 'application/json',
			],
		];

		$serviceUid = $this->appConfig->getValueString(Application::APP_ID, 'opencatalogi_service_uid', '');
		$serviceAppPass = $this->appConfig->getValueString(Application::APP_ID, 'opencatalogi_service_app_password', '');
		if ($serviceUid !== '' && $serviceAppPass !== '') {
			$options['auth'] = [$serviceUid, $serviceAppPass];
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->get($url, $options);

			$decoded = json_decode((string)$response->getBody(), true);
			if (is_array($decoded) === false) {
				throw new RuntimeException(self::DOMAIN_ERROR);
			}

			return $decoded;
		} catch (RuntimeException $e) {
			throw $e;
		} catch (Throwable $e) {
			$this->logger->warning(
				'OpenCatalogiApiClient: catalog discovery request failed',
				['app' => Application::APP_ID, 'url' => $url, 'error' => $e->getMessage()],
			);
			throw new RuntimeException(self::DOMAIN_ERROR, 0, $e);
		}//end try
	}//end fetchCatalogi()
}//end class
