<?php

/**
 * Procest Besluitvorming Controller
 *
 * REST endpoints for the bestuurlijke-besluitvorming workflow: activating
 * zaaktype templates (admin), compiling and confirming agendas, triggering
 * DROP/LVBB publication (retry), and validating mandaat authority.
 *
 * Identity is always derived from IUserSession; the request body is never
 * trusted for actor identity (ADR-005). Template activation is admin-gated as a
 * privileged configuration operation; case-scoped operations require an
 * authenticated user and are scoped to the resolved case (no IDOR).
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
 * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\AgendaService;
use OCA\Procest\Service\BesluitvormingTemplateService;
use OCA\Procest\Service\MandaatValidationService;
use OCA\Procest\Service\PublicationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Controller for besluitvorming-workflow operations.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — orchestrates four domain services.
 *
 * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md
 */
class BesluitvormingController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                        $appName            The app name.
     * @param IRequest                      $request            The request object.
     * @param BesluitvormingTemplateService $templateService    Template activation service.
     * @param AgendaService                 $agendaService      Agenda compiler service.
     * @param PublicationService            $publicationService DROP/LVBB dispatcher.
     * @param MandaatValidationService      $mandaatService     Mandaat validator.
     * @param IUserSession                  $userSession        The user session.
     * @param IGroupManager                 $groupManager       Group manager (admin check).
     * @param LoggerInterface               $logger             Logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly BesluitvormingTemplateService $templateService,
        private readonly AgendaService $agendaService,
        private readonly PublicationService $publicationService,
        private readonly MandaatValidationService $mandaatService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Activate a bundled besluitvorming zaaktype template (admin only).
     *
     * @param string $slug The template slug.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-001
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function activateTemplate(string $slug): JSONResponse
    {
        if ($this->requireAdmin() === false) {
            return new JSONResponse(['error' => 'Beheerdersrechten vereist'], Http::STATUS_FORBIDDEN);
        }

        try {
            return new JSONResponse($this->templateService->activate($slug), Http::STATUS_OK);
        } catch (RuntimeException $e) {
            $this->logger->warning('Procest: template activation rejected: '.$e->getMessage());
            return new JSONResponse(['error' => 'Onbekend of ongeldig template'], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Procest: template activation failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Activatie mislukt'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end activateTemplate()

    /**
     * Add a case to an agenda (classification + order).
     *
     * @param string $id The case UUID.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-004
     */
    #[NoAdminRequired]
    public function addToAgenda(string $id): JSONResponse
    {
        if ($this->requireUser() === false) {
            return new JSONResponse(['error' => 'Authenticatie vereist'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $data           = $this->getRequestBody();
            $classification = (string) ($data['behandeling'] ?? AgendaService::BEHANDELING_HAMERSTUK);
            $order          = (int) ($data['order'] ?? 1);

            return new JSONResponse($this->agendaService->addItem($id, $classification, $order), Http::STATUS_OK);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => 'Ongeldige agenda-invoer'], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Procest: addToAgenda failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Agenderen mislukt'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end addToAgenda()

    /**
     * Confirm an agenda for a list of cases on a meeting date.
     *
     * @param string $id The vergadering case UUID (route owner).
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-004
     */
    #[NoAdminRequired]
    public function confirmAgenda(string $id): JSONResponse
    {
        if ($this->requireUser() === false) {
            return new JSONResponse(['error' => 'Authenticatie vereist'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $data        = $this->getRequestBody();
            $caseIds     = (array) ($data['caseIds'] ?? []);
            $meetingDate = (string) ($data['meetingDate'] ?? '');
            if ($meetingDate === '') {
                return new JSONResponse(['error' => 'meetingDate is verplicht'], Http::STATUS_BAD_REQUEST);
            }

            $result = $this->agendaService->confirmAgenda($caseIds, $meetingDate);
            $result['vergadering'] = $id;

            return new JSONResponse($result, Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->logger->error('Procest: confirmAgenda failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Agenda bevestigen mislukt'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end confirmAgenda()

    /**
     * Generate the agenda document (hamerstukken first) for a set of cases.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-004
     */
    #[NoAdminRequired]
    public function generateAgenda(): JSONResponse
    {
        if ($this->requireUser() === false) {
            return new JSONResponse(['error' => 'Authenticatie vereist'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $data    = $this->getRequestBody();
            $caseIds = (array) ($data['caseIds'] ?? []);

            return new JSONResponse($this->agendaService->generateAgendaDocument($caseIds), Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->logger->error('Procest: generateAgenda failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Agenda genereren mislukt'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end generateAgenda()

    /**
     * Trigger (retry) DROP/LVBB publication for a case.
     *
     * @param string $id The case UUID.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-006
     */
    #[NoAdminRequired]
    public function publish(string $id): JSONResponse
    {
        if ($this->requireUser() === false) {
            return new JSONResponse(['error' => 'Authenticatie vereist'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $result = $this->publicationService->dispatch($id);
            $status = Http::STATUS_BAD_GATEWAY;
            if (($result['ok'] ?? false) === true) {
                $status = Http::STATUS_OK;
            }

            return new JSONResponse($result, $status);
        } catch (\Throwable $e) {
            $this->logger->error('Procest: publish failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Publicatie mislukt'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end publish()

    /**
     * Validate the signing official's mandate for a mandaatbesluit case.
     *
     * @param string $id The case UUID.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-007
     */
    #[NoAdminRequired]
    public function mandaatCheck(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Authenticatie vereist'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $signingId = (string) ($this->request->getParam('signingUserId') ?? $user->getUID());
            $result    = $this->mandaatService->validate($id, $signingId);

            return new JSONResponse($result, Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->logger->error('Procest: mandaatCheck failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Mandaatcontrole mislukt'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end mandaatCheck()

    /**
     * Parse the JSON request body.
     *
     * @return array<string, mixed>
     */
    private function getRequestBody(): array
    {
        $body = file_get_contents('php://input');
        if ($body === false || $body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end getRequestBody()

    /**
     * Ensure an authenticated user is present.
     *
     * @return bool
     */
    private function requireUser(): bool
    {
        return $this->userSession->getUser() !== null;
    }//end requireUser()

    /**
     * Ensure the current user is in the admin group.
     *
     * @return bool
     */
    private function requireAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        return $this->groupManager->isAdmin($user->getUID());
    }//end requireAdmin()
}//end class
