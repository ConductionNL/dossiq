<?php

/**
 * Procest Tenant Onboarding Controller
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use InvalidArgumentException;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\TenantOnboardingService;
use OCA\Procest\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Onboarding REST controller.
 */
class TenantOnboardingController extends Controller
{
    public function __construct(
        IRequest $request,
        private readonly TenantOnboardingService $onboarding,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }

    /**
     * GET /api/saas/tenants/{tenantId}/onboarding/progress
     *
     * @param string $tenantId Tenant UUID.
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function progress(string $tenantId): JSONResponse
    {
        return new JSONResponse(
            [
                'success'  => true,
                'progress' => $this->onboarding->getProgress($tenantId),
            ]
        );
    }

    /**
     * POST /api/saas/tenants/{tenantId}/onboarding/{step}/complete
     *
     * @param string $tenantId Tenant UUID.
     * @param string $step     Step name.
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function complete(string $tenantId, string $step): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $task = $this->onboarding->markStepComplete(
                tenantId: $tenantId,
                step: $step,
                completedBy: $user->getUID()
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        if ($task === null) {
            return new JSONResponse(['success' => false, 'error' => 'Step not found'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(['success' => true, 'task' => $task]);
    }

    /**
     * POST /api/saas/tenants/{tenantId}/onboarding/activate
     *
     * @param string $tenantId Tenant UUID.
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function activate(string $tenantId): JSONResponse
    {
        $result = $this->onboarding->activate($tenantId);
        $code   = ($result['activated'] === true) ? Http::STATUS_OK : Http::STATUS_CONFLICT;
        return new JSONResponse(['success' => $result['activated'], 'result' => $result], $code);
    }

    /**
     * POST /api/saas/tenants/{tenantId}/onboarding/initialise
     *
     * @param string $tenantId Tenant UUID.
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function initialise(string $tenantId): JSONResponse
    {
        $rows = $this->onboarding->createOnboarding($tenantId);
        return new JSONResponse(['success' => true, 'tasks' => $rows]);
    }
}
