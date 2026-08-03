<?php

/**
 * Procest MandaatController
 *
 * REST API controller for validating mandates in the besluitvorming workflow.
 * Delegates mandate validation to MandaatValidationService.
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
 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\MandaatValidationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for mandate validation endpoints.
 *
 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-10
 */
class MandaatController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                   $appName                  The application name.
     * @param IRequest                 $request                  The request object.
     * @param MandaatValidationService $mandaatValidationService The mandate validation service.
     * @param IUserSession             $userSession              The user session.
     * @param LoggerInterface          $logger                   The logger.
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly MandaatValidationService $mandaatValidationService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Check whether the signing user holds a valid mandate for a case.
     *
     * NOTE: As of procest-delegate-contract-decision, the mandate/route-stage
     * assignee model is now owned by decidesk (Person|GovernanceBody assignee,
     * ambtelijk↔politiek route seeds). This endpoint still validates local
     * mandaat constraints but new mandate-decision flows should be raised via
     * ContractDecisionDelegationService with the appropriate mandateContext.
     *
     * @param string $id The case UUID.
     *
     * @return JSONResponse Validation result envelope or an error response.
     *
     * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-10
     * @spec openspec/specs/contract-decision-delegation/spec.md
     */
    #[NoAdminRequired]
    public function mandaatCheck(string $id): JSONResponse
    {
        // Mandate validation: local Awb constraints remain owned by procest;
        // route-stage assignee decisions are delegated to decidesk (ADR-019).
        try {
            $this->authorizeMandaatAccess(caseId: $id, user: $this->userSession->getUser());
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNAUTHORIZED);
        }

        $signingUserId = (string) $this->request->getParam('signingUserId', '');

        if (empty($signingUserId) === true) {
            return new JSONResponse(
                ['error' => 'Missing required parameter: signingUserId'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $result = $this->mandaatValidationService->validate(
                caseId: $id,
                signingUserId: $signingUserId,
            );
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error(
                'MandaatController::mandaatCheck failed',
                ['caseId' => $id, 'exception' => $e->getMessage()]
            );
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end mandaatCheck()

    /**
     * Authorize the current user for mandate check access on a specific case.
     *
     * Per ADR-005 Rule 3: unauthenticated users must be denied immediately.
     *
     * @param string     $caseId The case UUID being accessed.
     * @param IUser|null $user   The authenticated user from IUserSession.
     *
     * @return void
     *
     * @throws OCSForbiddenException When the user is not authenticated.
     */
    private function authorizeMandaatAccess(string $caseId, ?IUser $user): void
    {
        if ($user === null) {
            // Log the denial with the case it targeted — an anonymous probe of
            // the mandate surface is exactly what an audit needs to see, and
            // without the case id the alert is not actionable.
            $this->logger->warning(
                'MandaatController: unauthenticated mandaat check denied',
                ['caseId' => $caseId]
            );
            throw new OCSForbiddenException('Not authenticated');
        }
    }//end authorizeMandaatAccess()
}//end class
