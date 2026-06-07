<?php

/**
 * Procest DSO Intake Controller
 *
 * Receives STAM 2.0 vergunningaanvraag payloads from the Digitaal Stelsel
 * Omgevingswet (DSO/Omgevingsloket) via OpenConnector and creates cases.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vth-module/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\DsoIntakeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for DSO/Omgevingsloket intake.
 *
 * Exposes a public endpoint for DSO callbacks via OpenConnector.
 * Signature validation is performed using the configured DSO secret.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/vth-module/tasks.md#task-3
 */
class DSOIntakeController extends Controller
{

    /**
     * Header carrying the DSO HMAC-SHA256 signature.
     */
    private const SIGNATURE_HEADER = 'X-DSO-Signature';

    /**
     * Config key for the DSO webhook secret.
     */
    private const DSO_SECRET_KEY = 'dso_webhook_secret';

    /**
     * Constructor.
     *
     * @param string           $appName          The app name
     * @param IRequest         $request          The request
     * @param DsoIntakeService $dsoIntakeService DSO intake service
     * @param IAppConfig       $appConfig        App config (DSO webhook secret)
     * @param LoggerInterface  $logger           Logger
     *
     * @spec openspec/changes/vth-module/tasks.md#task-3
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DsoIntakeService $dsoIntakeService,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Receive a DSO vergunningaanvraag and create a case.
     *
     * This is a public endpoint intended for DSO callbacks routed via
     * OpenConnector. Payload signature is validated via HMAC-SHA256.
     * Invalid signatures result in 401; any other errors result in 500.
     *
     * @return JSONResponse Created case data or error
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @spec openspec/changes/vth-module/tasks.md#task-3
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function intake(): JSONResponse
    {
        // NC's concrete Request class exposes getContent() as a protected
        // method accessed here via the abstract framework contract.
        // In production, the DI container injects OC\AppFramework\Http\Request
        // which does implement this method.
        $rawBody = method_exists(object_or_class: $this->request, method: 'getContent')
            ? $this->request->getContent() // phpcs:disable
            : '';

        if ($this->validateSignature(body: (string) $rawBody) === false) {
            $this->logger->warning(
                'DSO intake: invalid or missing signature',
                ['app' => Application::APP_ID]
            );
            return new JSONResponse(
                ['message' => 'Invalid or missing DSO signature'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $payload = $rawBody !== '' ? json_decode(json: (string) $rawBody, associative: true) : null;
        if (is_array($payload) === false) {
            // Fall back to parsed request params (e.g. Content-Type: application/json).
            $payload = $this->request->getParams();
            if (empty($payload) === true) {
                return new JSONResponse(
                    ['message' => 'Invalid or empty JSON payload'],
                    Http::STATUS_BAD_REQUEST
                );
            }
        }

        try {
            $result = $this->dsoIntakeService->processAanvraag(dsoMessage: $payload);

            $this->logger->info(
                'DSO intake: case created '.($result['caseId'] ?? 'unknown'),
                ['app' => Application::APP_ID]
            );

            return new JSONResponse(data: $result, statusCode: Http::STATUS_CREATED);
        } catch (Throwable $e) {
            $this->logger->error(
                'DSO intake failed: '.$e->getMessage(),
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return new JSONResponse(
                ['message' => 'DSO intake processing failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end intake()

    /**
     * Validate the DSO HMAC-SHA256 signature on the request body.
     *
     * If no DSO secret is configured, all requests are allowed through
     * (useful for test environments and OpenConnector-signed requests where
     * OpenConnector itself has already validated the DSO origin).
     *
     * @param string $body Raw request body
     *
     * @return bool True if signature is valid or no secret is configured
     */
    private function validateSignature(string $body): bool
    {
        // Read the configured secret from app config (canonical Nextcloud
        // pattern); fall back to the DSO_WEBHOOK_SECRET environment variable
        // for parity with OpenConnector-fronted deployments.
        $configuredSecret = $this->appConfig->getValueString(
            Application::APP_ID,
            self::DSO_SECRET_KEY,
            (string) (getenv('DSO_WEBHOOK_SECRET') ?: '')
        );

        if ($configuredSecret === '') {
            return true;
        }

        $receivedSig = $this->request->getHeader(self::SIGNATURE_HEADER);
        if ($receivedSig === '') {
            return false;
        }

        $expectedSig = 'sha256='.hash_hmac(algo: 'sha256', data: $body, key: $configuredSecret);

        return hash_equals(known_string: $expectedSig, user_string: $receivedSig);
    }//end validateSignature()
}//end class
