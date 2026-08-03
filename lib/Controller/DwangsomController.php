<?php

/**
 * Procest DwangsomController.
 *
 * REST surface for DwangsomBerekening state + bezwaar lifecycle +
 * beschikking-stop. Defers all business logic to
 * {@see DwangsomCalculationService} and {@see DwangsomBezwaarService}
 * (ADR-022).
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\DwangsomBezwaarService;
use OCA\Procest\Service\DwangsomCalculationService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * REST surface for DwangsomBerekening state + bezwaar.
 *
 * @psalm-suppress UnusedClass
 */
class DwangsomController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                     $appName     App id.
     * @param IRequest                   $request     Request.
     * @param DwangsomCalculationService $calc        Calculation service.
     * @param DwangsomBezwaarService     $bezwaar     Bezwaar service.
     * @param SettingsService            $settings    Settings.
     * @param IUserSession               $userSession User session.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DwangsomCalculationService $calc,
        private readonly DwangsomBezwaarService $bezwaar,
        private readonly SettingsService $settings,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Per-object authorization guard.
     *
     * @return JSONResponse|null
     */
    private function ensureAuthenticated(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
        }

        return null;
    }//end ensureAuthenticated()

    /**
     * Get a DwangsomBerekening by id.
     *
     * @param string $id Id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
     */
    public function show(string $id): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) {
            return $denied;
        }

        $objectService = $this->settings->getObjectService();
        $register      = (string) $this->settings->getConfigValue('register');
        $schema        = (string) $this->settings->getConfigValue('dwangsom_berekening_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return new JSONResponse(['message' => 'Service unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        try {
            $row = $objectService->find($id, register: $register, schema: $schema);
        } catch (Throwable $e) {
            return new JSONResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        if (is_array($row) === false) {
            return new JSONResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($row);
    }//end show()

    /**
     * Stop the berekening because a beschikking was filed.
     *
     * @param string $id Id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
     */
    public function beschikking(string $id): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) {
            return $denied;
        }

        $row = $this->calc->stopForBeschikking($id);
        if ($row === null) {
            return new JSONResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($row);
    }//end beschikking()

    /**
     * Register a bezwaar.
     *
     * @param string $id Id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
     */
    public function bezwaar(string $id): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) {
            return $denied;
        }

        $body       = $this->jsonBody();
        $grondslag  = (string) ($body['grondslag'] ?? 'AWB 7:1');
        $motivering = (string) ($body['motivering'] ?? '');

        try {
            $row = $this->bezwaar->registerBezwaar($id, $grondslag, $motivering);
            return new JSONResponse($row);
        } catch (Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end bezwaar()

    /**
     * Resolve a bezwaar with a corrected amount.
     *
     * @param string $id Id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
     */
    public function bezwaarHeroverweging(string $id): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) {
            return $denied;
        }

        $body      = $this->jsonBody();
        $newBedrag = (int) ($body['newBedragCents'] ?? -1);
        $grondslag = (string) ($body['grondslag'] ?? 'AWB 7:11');
        if ($newBedrag < 0) {
            return new JSONResponse(['message' => 'newBedragCents required and must be >= 0'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $row = $this->bezwaar->resolveBezwaar($id, $newBedrag, $grondslag);
            return new JSONResponse($row);
        } catch (Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end bezwaarHeroverweging()

    /**
     * Decode the JSON request body into an associative array.
     *
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        // OCP\IRequest::getContent() is protected on the concrete OC
        // request; read raw payload from php://input instead.
        $raw  = (string) file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (is_array($body) === true) {
            return $body;
        }

        return [];
    }//end jsonBody()
}//end class
