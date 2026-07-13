<?php

/**
 * Procest OpenCatalogi API Client.
 *
 * The single thin HTTP boundary for every outbound call this app makes to
 * OpenCatalogi's publication model. OpenCatalogi is called as a local peer
 * app on the same Nextcloud instance, the same way `LibresignApiClient`
 * calls LibreSign (`OCP\Http\Client\IClientService`).
 *
 * Unlike the LibreSign integration, the routes used here are NOT an
 * assumption: OpenCatalogi's own `PublicationsController` exposes no write
 * endpoint (index/show/uses/used/attachments/download only — confirmed
 * against `origin/development` in the opencatalogi repo). Both OpenCatalogi's
 * own backend (`PublicationService::getObjectService()`) and frontend
 * (`src/store/modules/object.js`) create/update/withdraw publications through
 * OpenRegister's generic Objects API instead
 * (`/index.php/apps/openregister/api/objects/{register}/{schema}[/{id}]`,
 * confirmed against `openregister/appinfo/routes.php`:
 * `objects#create`/`objects#patch`/`files#create`). This client follows the
 * exact same path, addressing the register/schema OpenCatalogi ships by
 * default (`lib/Settings/publication_register.json`: register slug
 * `publication`, schemas `publication`/`document`).
 *
 * "Publish" and "withdraw" are not separate endpoints either — a publication
 * is live when `publicatiedatum` is a past date, and withdrawn when
 * `depublicatiedatum` is a past date (per the schema's own field
 * descriptions); both are set via `objects#patch`.
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
 * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d1
 * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\WooPublication;

use OCA\Procest\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Thin HTTP client for OpenRegister's Objects API, scoped to the
 * register/schema OpenCatalogi's publication model owns.
 *
 * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d1
 */
class OpenCatalogiApiClient
{

    /**
     * OpenRegister objects endpoint template (register/schema, no id).
     *
     * @var string
     */
    private const OBJECTS_PATH = '/index.php/apps/openregister/api/objects/%s/%s';

    /**
     * OpenRegister single-object endpoint template.
     *
     * @var string
     */
    private const OBJECT_PATH = '/index.php/apps/openregister/api/objects/%s/%s/%s';

    /**
     * OpenRegister object-file-attach endpoint template.
     *
     * @var string
     */
    private const OBJECT_FILES_PATH = '/index.php/apps/openregister/api/objects/%s/%s/%s/files';

    /**
     * OpenCatalogi's public catalog-listing endpoint (discovery only, D-Fallback).
     *
     * @var string
     */
    private const CATALOGI_PATH = '/index.php/apps/opencatalogi/api/catalogi';

    /**
     * Request timeout in seconds.
     *
     * @var int
     */
    private const TIMEOUT_SECONDS = 15;

    /**
     * Constructor.
     *
     * @param IClientService  $clientService HTTP client factory.
     * @param IURLGenerator   $urlGenerator  Resolves this Nextcloud instance's own base URL.
     * @param IAppConfig      $appConfig     App config (service-account credentials).
     * @param LoggerInterface $logger        Structured logger.
     */
    public function __construct(
        private readonly IClientService $clientService,
        private readonly IURLGenerator $urlGenerator,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a publication object in OpenCatalogi's publication register.
     *
     * @param string               $register The publication register slug.
     * @param string               $schema   The publication schema slug.
     * @param array<string, mixed> $payload  The publication fields.
     *
     * @return array<string, mixed> The created object.
     *
     * @throws RuntimeException 'opencatalogi_api_error' on any transport/decode failure.
     *
     * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d1
     */
    public function createPublication(string $register, string $schema, array $payload): array
    {
        return $this->call(method: 'POST', path: sprintf(self::OBJECTS_PATH, $register, $schema), payload: $payload);
    }//end createPublication()

    /**
     * Update (patch) an existing publication object — used for republish and
     * for setting `depublicatiedatum` on withdraw.
     *
     * @param string               $register The publication register slug.
     * @param string               $schema   The publication schema slug.
     * @param string               $id       The publication object id.
     * @param array<string, mixed> $payload  The fields to update.
     *
     * @return array<string, mixed> The updated object.
     *
     * @throws RuntimeException 'opencatalogi_api_error' on any transport/decode failure.
     *
     * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d1
     */
    public function updatePublication(string $register, string $schema, string $id, array $payload): array
    {
        return $this->call(
            method: 'PATCH',
            path: sprintf(self::OBJECT_PATH, $register, $schema, $id),
            payload: $payload,
        );
    }//end updatePublication()

    /**
     * Create a `document` object linked to a publication.
     *
     * @param string               $register The register slug (same register as the publication).
     * @param string               $schema   The document schema slug.
     * @param array<string, mixed> $payload  The document fields (must include `publication`).
     *
     * @return array<string, mixed> The created document object.
     *
     * @throws RuntimeException 'opencatalogi_api_error' on any transport/decode failure.
     *
     * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d1
     */
    public function attachDocument(string $register, string $schema, array $payload): array
    {
        return $this->call(method: 'POST', path: sprintf(self::OBJECTS_PATH, $register, $schema), payload: $payload);
    }//end attachDocument()

    /**
     * Attach file bytes to an object (publication or document) via
     * OpenRegister's generic per-object file API.
     *
     * @param string $register      The register slug.
     * @param string $schema        The schema slug.
     * @param string $objectId      The object id to attach the file to.
     * @param string $fileName      The file name.
     * @param string $base64Content The base64-encoded file content.
     * @param string $mimeType      The file MIME type.
     *
     * @return array<string, mixed> The file-attach response.
     *
     * @throws RuntimeException 'opencatalogi_api_error' on any transport/decode failure.
     *
     * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d1
     */
    public function attachFile(
        string $register,
        string $schema,
        string $objectId,
        string $fileName,
        string $base64Content,
        string $mimeType,
    ): array {
        $payload = [
            'name'     => $fileName,
            'content'  => $base64Content,
            'mimeType' => $mimeType,
        ];

        return $this->call(
            method: 'POST',
            path: sprintf(self::OBJECT_FILES_PATH, $register, $schema, $objectId),
            payload: $payload,
        );
    }//end attachFile()

    /**
     * Best-effort discovery of a WOO-flagged OpenCatalogi catalog.
     *
     * Never gates publication — see design.md "Fallback". A failure here is
     * logged and swallowed; the caller keeps using the configured
     * register/schema defaults regardless.
     *
     * @return array<string, mixed>|null The first `hasWooSitemap: true` catalog, or null.
     *
     * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#fallback
     */
    public function resolveCatalog(): ?array
    {
        try {
            $result = $this->call(method: 'GET', path: self::CATALOGI_PATH, payload: null);
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
     * Perform the HTTP call and decode the response.
     *
     * Every route this client addresses (OpenRegister's Objects API,
     * OpenCatalogi's public catalog listing) returns plain JSON, not an OCS
     * envelope — unlike LibreSign's `/ocs/v2.php` routes — so no envelope
     * unwrapping is needed here.
     *
     * @param string                    $method  'GET', 'POST', or 'PATCH'.
     * @param string                    $path    The route path (leading slash).
     * @param array<string, mixed>|null $payload The JSON body for POST/PATCH requests.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException 'opencatalogi_api_error' on any transport/decode failure.
     */
    private function call(string $method, string $path, ?array $payload): array
    {
        $url = rtrim($this->urlGenerator->getBaseUrl(), '/').$path;

        $options = [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'OCS-APIREQUEST' => 'true',
                'Accept'         => 'application/json',
            ],
        ];

        $serviceUid     = $this->appConfig->getValueString(Application::APP_ID, 'opencatalogi_service_uid', '');
        $serviceAppPass = $this->appConfig->getValueString(Application::APP_ID, 'opencatalogi_service_app_password', '');
        if ($serviceUid !== '' && $serviceAppPass !== '') {
            $options['auth'] = [$serviceUid, $serviceAppPass];
        }

        if ($payload !== null) {
            $options['json'] = $payload;
        }

        try {
            $client   = $this->clientService->newClient();
            $response = match ($method) {
                'POST'  => $client->post($url, $options),
                'PATCH' => $client->patch($url, $options),
                default => $client->get($url, $options),
            };

            $decoded = json_decode((string) $response->getBody(), true);
            if (is_array($decoded) === false) {
                throw new RuntimeException('opencatalogi_api_error');
            }

            return $decoded;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->warning(
                'OpenCatalogiApiClient: request failed',
                ['app' => Application::APP_ID, 'url' => $url, 'method' => $method, 'error' => $e->getMessage()],
            );
            throw new RuntimeException('opencatalogi_api_error', 0, $e);
        }//end try
    }//end call()
}//end class
