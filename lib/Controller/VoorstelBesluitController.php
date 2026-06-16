<?php

/**
 * Procest Voorstel Besluit Controller
 *
 * Exposes the voorstel→besluit registration node. Instead of authoring a
 * procest-local `decision` object, registering a besluit on a voorstel raises a
 * decidesk `report-adoption` Decision via the ADR-019 integration registry
 * (procest-delegate-remaining-decisions-to-decidesk, REQ-PDRD-001). procest
 * keeps the parafeerroute untouched and records the ZGW `Besluit` as a
 * projection of the decidesk outcome. FAILS CLOSED when decidesk is unavailable
 * (REQ-PDRD-002): no procest-local besluit is authored as a fallback.
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/procest-delegate-remaining-decisions-to-decidesk/specs/remaining-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\AdviceDelegationService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Controller for the voorstel besluit-registration delegation node.
 */
class VoorstelBesluitController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                $request          The HTTP request.
     * @param AdviceDelegationService $adviceDelegation Decision delegation to decidesk (ADR-019).
     * @param SettingsService         $settingsService  Schema/register + ObjectService resolver.
     * @param IUserSession            $userSession      Acting identity source.
     * @param IGroupManager           $groupManager     Group manager (admin check for the IDOR gate).
     * @param LoggerInterface         $logger           Logger.
     */
    public function __construct(
        IRequest $request,
        private readonly AdviceDelegationService $adviceDelegation,
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Register a besluit on a voorstel by raising a decidesk `report-adoption`
     * Decision. IDOR-guarded: only the voorstel owner / case assignee or an
     * admin may register the besluit. FAILS CLOSED when decidesk is unavailable.
     *
     * @param string $voorstelId The voorstel UUID.
     *
     * @return JSONResponse The decidesk decisionRef envelope, or an error.
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/procest-delegate-remaining-decisions-to-decidesk/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-001-remaining-decisionadvice-flows-are-raised-as-decidesk-decisions
     * @spec openspec/changes/procest-delegate-remaining-decisions-to-decidesk/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
     */
    #[NoAdminRequired]
    public function registerBesluit(string $voorstelId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Authenticatie vereist'], Http::STATUS_UNAUTHORIZED);
        }

        // Per-object IDOR gate (ADR-005 Rule 3 / OWASP A01:2021): read the
        // voorstel and verify the caller may act on it before raising anything.
        $voorstel = $this->loadVoorstel(voorstelId: $voorstelId);
        if ($voorstel === null) {
            return new JSONResponse(['error' => 'Voorstel niet toegankelijk'], Http::STATUS_NOT_FOUND);
        }

        if ($this->callerMayRegister(voorstel: $voorstel, uid: $user->getUID()) === false) {
            // Collapse access-denied + not-found to the same response to avoid
            // an existence-probing oracle.
            return new JSONResponse(['error' => 'Voorstel niet toegankelijk'], Http::STATUS_FORBIDDEN);
        }

        $body = $this->getRequestBody();

        try {
            $decisionRef = $this->adviceDelegation->raiseVoorstelBesluit(
                voorstelId: $voorstelId,
                payload: [
                    'externalReference' => (string) ($voorstel['case'] ?? $voorstelId),
                    'subjectLabel'      => (string) ($body['title'] ?? ($voorstel['onderwerp'] ?? '')),
                    'title'             => (string) ($body['title'] ?? ''),
                    'governingBody'     => (string) ($body['governingBody'] ?? ''),
                    'explanation'       => (string) ($body['explanation'] ?? ''),
                ],
            );
        } catch (RuntimeException $e) {
            // REQ-PDRD-002: fail closed — surface the unavailable error, do NOT
            // author a procest-local besluit as a fallback.
            $this->logger->error(
                'Procest: voorstel besluit-registration failed closed: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return new JSONResponse(
                ['error' => 'Besluitdienst niet beschikbaar: '.$e->getMessage()],
                Http::STATUS_SERVICE_UNAVAILABLE,
            );
        }

        return new JSONResponse(
            ['voorstelId' => $voorstelId, 'decisionRef' => $decisionRef, 'status' => 'awaiting-decidesk'],
            Http::STATUS_ACCEPTED,
        );
    }//end registerBesluit()

    /**
     * Load a voorstel via OpenRegister, or null when unavailable / not found.
     *
     * @param string $voorstelId The voorstel UUID.
     *
     * @return array<string,mixed>|null The voorstel, or null.
     */
    private function loadVoorstel(string $voorstelId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register      = $this->settingsService->getConfigValue(key: 'register');
        $voorstelSchema = $this->settingsService->getConfigValue(key: 'voorstel_schema');
        if ($register === '' || $voorstelSchema === '') {
            return null;
        }

        try {
            $voorstel = $objectService->find($voorstelId, register: $register, schema: $voorstelSchema);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest: voorstel lookup failed during IDOR gate: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return null;
        }

        return is_array($voorstel) === true ? $voorstel : null;
    }//end loadVoorstel()

    /**
     * Whether the caller may register a besluit on the voorstel.
     *
     * Admins always may. Otherwise the caller must be the voorstel owner
     * (@self.owner) or its recorded assignee / behandelaar.
     *
     * @param array<string,mixed> $voorstel The voorstel record.
     * @param string              $uid      The caller UID.
     *
     * @return bool
     */
    private function callerMayRegister(array $voorstel, string $uid): bool
    {
        if ($this->groupManager->isAdmin($uid) === true) {
            return true;
        }

        $owner     = (string) ($voorstel['@self']['owner'] ?? '');
        $assignee  = (string) ($voorstel['assignee'] ?? ($voorstel['behandelaar'] ?? ''));

        return ($owner !== '' && $owner === $uid) || ($assignee !== '' && $assignee === $uid);
    }//end callerMayRegister()

    /**
     * Decode the JSON request body safely.
     *
     * @return array<string,mixed>
     */
    private function getRequestBody(): array
    {
        $content = $this->request->getContent();
        if ($content === '' || $content === false) {
            return [];
        }

        $decoded = json_decode((string) $content, true);
        return is_array($decoded) === true ? $decoded : [];
    }//end getRequestBody()
}//end class
