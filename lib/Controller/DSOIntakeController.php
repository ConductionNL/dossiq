<?php

/**
 * Procest DSO Intake Controller
 *
 * Receives signed STAM 2.0 vergunningaanvraag payloads from the
 * DSO/Omgevingsloket via OpenConnector and creates omgevingsvergunning cases.
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
use RuntimeException;
use Throwable;

/**
 * Controller for DSO vergunningaanvraag intake.
 *
 * @spec openspec/changes/vth-module/tasks.md#task-3
 *
 * @psalm-suppress UnusedClass
 */
class DSOIntakeController extends Controller
{

    /**
     * Constructor.
     *
     * @param string           $appName          App name
     * @param IRequest         $request          HTTP request
     * @param DsoIntakeService $dsoIntakeService DSO intake service
     * @param IAppConfig       $appConfig        App config (Wilco #6 HMAC fix)
     * @param LoggerInterface  $logger           Logger
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
     * Accepts signed STAM 2.0 JSON payload from OpenConnector.
     * Returns 401 on invalid/missing signature.
     * Returns 201 with created case ID on success.
     *
     * @return JSONResponse
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
        $content = $this->request->getContent();
        if ($content === '' || $content === false) {
            return new JSONResponse(['error' => 'Empty request body'], Http::STATUS_BAD_REQUEST);
        }

        $payload = json_decode($content, true);
        if (is_array($payload) === false) {
            return new JSONResponse(['error' => 'Invalid JSON payload'], Http::STATUS_BAD_REQUEST);
        }

        // Wilco #6 / procest#17 fix (2026-06-06): verify HMAC. Previously
        // only the presence of the X-DSO-Signature header was checked —
        // any client could send `X-DSO-Signature: anything` to bypass the
        // gate. Now we compute HMAC-SHA256 over the raw request body
        // using the shared secret in IAppConfig and reject 401 on
        // mismatch with constant-time comparison.
        $signature = $this->request->getHeader('X-DSO-Signature');
        if ($signature === '') {
            $this->logger->warning(
                'Procest DSO intake: missing X-DSO-Signature header',
                ['app' => Application::APP_ID],
            );
            return new JSONResponse(
                ['error' => 'Missing DSO signature header'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $secret = $this->appConfig->getValueString(Application::APP_ID, 'dso_signing_secret', '');
        if ($secret === '') {
            // Fail-closed: a missing or unconfigured secret cannot be used
            // to verify any signature. Reject all intakes until an admin
            // configures the secret in IAppConfig (key
            // `procest.dso_signing_secret`).
            $this->logger->error(
                'Procest DSO intake: dso_signing_secret is not configured — rejecting (fail-closed)',
                ['app' => Application::APP_ID],
            );
            return new JSONResponse(
                ['error' => 'DSO signing not configured on server'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }

        $expected = hash_hmac(algo: 'sha256', data: $content, key: $secret);
        if (hash_equals($expected, $signature) === false) {
            $this->logger->warning(
                'Procest DSO intake: invalid X-DSO-Signature (HMAC mismatch)',
                ['app' => Application::APP_ID],
            );
            return new JSONResponse(
                ['error' => 'Invalid DSO signature'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        try {
            $mapped = $this->dsoIntakeService->map(dsoMessage: $payload);
            $result = $this->dsoIntakeService->createCase(mappedData: $mapped);
        } catch (RuntimeException $e) {
            $this->logger->error(
                'Procest DSO intake failed: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest DSO intake unexpected error: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            return new JSONResponse(
                ['error' => 'DSO intake failed'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }

        return new JSONResponse($result, Http::STATUS_CREATED);
    }//end intake()
}//end class
