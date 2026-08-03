<?php

/**
 * Procest LibreSign API Client.
 *
 * The single thin HTTP boundary for every outbound call this app makes to
 * LibreSign (LibreCode), the Nextcloud-native eIDAS-aligned digital signing
 * app. LibreSign is called as a local peer app on the same Nextcloud
 * instance via its OCS API, using OCP\Http\Client\IClientService the same
 * way KvkApiAdapter/HaalCentraalBrpAdapter already call third-party HTTP
 * APIs elsewhere in this app.
 *
 * No LibreSign checkout was available in this environment to confirm the
 * exact route/response shapes; the routes, payload shapes, and auth
 * mechanism below follow LibreSign's published v1 OCS API from general
 * knowledge and are documented as an explicit assumption in
 * openspec/changes/libresign-besluit-signing/design.md §2. Every field name
 * is isolated to this class so a future correction is a one-file change.
 *
 * @category Service
 * @package  OCA\Procest\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 * @link https://github.com/LibreSign/libresign
 *
 * @spec openspec/specs/libresign-besluit-signing/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Beschikking;

use OCA\Procest\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Thin HTTP client for LibreSign's local OCS API.
 *
 * @spec openspec/specs/libresign-besluit-signing/spec.md
 */
class LibresignApiClient
{
    /**
     * The LibreSign "create a signature request" OCS route (assumption, see class docblock).
     *
     * @var string
     */
    private const REQUEST_SIGNATURE_PATH = '/ocs/v2.php/apps/libresign/api/v1/request-signature';

    /**
     * The LibreSign "validate/status by uuid" OCS route template (assumption, see class docblock).
     *
     * @var string
     */
    private const STATUS_PATH_TEMPLATE = '/ocs/v2.php/apps/libresign/api/v1/file/validate/uuid/%s';

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
     * Create a LibreSign signature request for a Nextcloud file.
     *
     * @param int                              $fileId       The Nextcloud file id of the PDF to sign.
     * @param string                           $documentName A human-readable document name.
     * @param array<int, array<string, mixed>> $signers      Signer entries, each
     *                                                       `{identify: {email:
     *                                                       string},
     *                                                       displayName:
     *                                                       string}`.
     *
     * @return array<string, mixed> The decoded `ocs.data` envelope (expected: uuid, status, ...).
     *
     * @throws RuntimeException 'libresign_api_error' on any transport/decode failure.
     *
     * @spec openspec/specs/libresign-besluit-signing/spec.md
     */
    public function requestSignature(int $fileId, string $documentName, array $signers): array
    {
        $payload = [
            'file'   => ['fileId' => $fileId],
            'name'   => $documentName,
            'status' => 1,
            'users'  => $signers,
        ];

        return $this->call(method: 'POST', path: self::REQUEST_SIGNATURE_PATH, payload: $payload);
    }//end requestSignature()

    /**
     * Fetch the current status of a LibreSign signature request.
     *
     * @param string $uuid The LibreSign request uuid.
     *
     * @return array<string, mixed> The decoded `ocs.data` envelope (expected: status, statusText, file, signers).
     *
     * @throws RuntimeException 'libresign_api_error' on any transport/decode failure.
     *
     * @spec openspec/specs/libresign-besluit-signing/spec.md
     */
    public function getStatus(string $uuid): array
    {
        $path = sprintf(self::STATUS_PATH_TEMPLATE, rawurlencode($uuid));

        return $this->call(method: 'GET', path: $path, payload: null);
    }//end getStatus()

    /**
     * Perform the HTTP call and unwrap the OCS envelope.
     *
     * @param string                    $method  'GET' or 'POST'.
     * @param string                    $path    The OCS route path (leading slash).
     * @param array<string, mixed>|null $payload The JSON body for POST requests.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException 'libresign_api_error' on any transport/decode failure.
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

        $serviceUid     = $this->appConfig->getValueString(Application::APP_ID, 'libresign_service_uid', '');
        $serviceAppPass = $this->appConfig->getValueString(Application::APP_ID, 'libresign_service_app_password', '');
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
                default => $client->get($url, $options),
            };

            $decoded = json_decode((string) $response->getBody(), true);
            if (is_array($decoded) === false) {
                throw new RuntimeException('libresign_api_error');
            }

            $data = ($decoded['ocs']['data'] ?? null);
            if (is_array($data) === false) {
                throw new RuntimeException('libresign_api_error');
            }

            return $data;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->warning(
                'LibresignApiClient: request failed',
                ['app' => Application::APP_ID, 'url' => $url, 'method' => $method, 'error' => $e->getMessage()],
            );
            throw new RuntimeException('libresign_api_error', 0, $e);
        }//end try
    }//end call()
}//end class
