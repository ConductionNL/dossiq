<?php

/**
 * Procest Routing Controller
 *
 * Action endpoint for manual recomputation of step assignees on a case.
 * Generic CRUD over routing rules themselves lives on the workflow template
 * and is served by the OpenRegister manifest renderer — this controller
 * only owns the engine action `POST /api/cases/{id}/reroute`.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\RoleResolverService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for routing engine actions.
 *
 * @spec openspec/changes/role-based-step-routing/tasks.md#T05
 *
 * @psalm-suppress UnusedClass
 */
class RoutingController extends Controller
{
    /**
     * Constructor.
     *
     * @param string              $appName         App name
     * @param IRequest            $request         Request
     * @param RoleResolverService $resolver        Resolver service
     * @param SettingsService     $settingsService Settings bridge
     * @param IUserSession        $userSession     User session
     * @param IGroupManager       $groupManager    Group manager
     * @param LoggerInterface     $logger          Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly RoleResolverService $resolver,
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Recompute every open step's assignees on a case.
     *
     * Loads the case, fetches its active workflowTemplate, walks the open
     * steps, normalises each routing rule and resolves it against the case.
     * Returns the list of affected step ids and their resolved assignees.
     *
     * Requires the caller to be a server admin (mapped to the spec's
     * "case admin" notion). Non-admin callers receive 403.
     *
     * @param string $id The case UUID
     *
     * @return JSONResponse
     *
     * @auth admin-only rerouting reassigns every open step on a case, so it is
     * restricted to server admins — the body enforces the same rule via
     * IGroupManager::isAdmin() and this declaration states it rather than
     * contradicting it with @NoAdminRequired.
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/role-based-step-routing/tasks.md#T05
     */
    public function reroute(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => 'Authenticatie vereist'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['error' => 'Admin-rechten op de zaak vereist'],
                Http::STATUS_FORBIDDEN,
            );
        }

        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                return new JSONResponse(
                    ['error' => 'OpenRegister is niet beschikbaar'],
                    Http::STATUS_SERVICE_UNAVAILABLE,
                );
            }

            $register     = $this->settingsService->getConfigValue('register');
            $caseSchema   = $this->settingsService->getConfigValue('case_schema');
            $workflowSlug = $this->settingsService->getConfigValue('workflow_template_schema');

            $case = $this->toArray(value: $objectService->find($id, register: $register, schema: $caseSchema));

            $workflowId = (string) ($case['workflowTemplate'] ?? '');
            $steps      = [];
            if ($workflowId !== '' && $workflowSlug !== '') {
                $workflow = $this->toArray(value: $objectService->find($workflowId, register: $register, schema: $workflowSlug));
                $steps    = $this->decodeSteps(value: $workflow['steps'] ?? '[]');
            }

            $affected = [];
            foreach ($steps as $step) {
                $rule = $this->resolver->normaliseRule($step);
                if ($rule === null) {
                    continue;
                }

                $assignees  = $this->resolver->resolve($rule, $case);
                $affected[] = [
                    'stepId'    => (string) ($step['id'] ?? ''),
                    'order'     => (int) ($step['order'] ?? 0),
                    'assignees' => $assignees,
                ];
            }

            return new JSONResponse(
                [
                    'caseId'        => $id,
                    'affectedSteps' => $affected,
                ],
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: reroute failed for case '.$id.': '.$e->getMessage(),
            );
            return new JSONResponse(
                ['error' => 'Herberekening mislukt'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }//end try
    }//end reroute()

    /**
     * Decode a JSON-encoded steps payload into an array.
     *
     * @param mixed $value The raw value (string or array)
     *
     * @return array<int, array<string, mixed>>
     */
    private function decodeSteps(mixed $value): array
    {
        if (is_string($value) === true) {
            $decoded = json_decode($value, true);
            $value   = [];
            if (is_array($decoded) === true) {
                $value = $decoded;
            }
        }

        if (is_array($value) === false) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (is_array($row) === true) {
                $rows[] = $row;
            }
        }

        return $rows;
    }//end decodeSteps()

    /**
     * Convert an arbitrary ObjectService return to an array.
     *
     * @param mixed $value The value
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true) {
            if (method_exists($value, 'jsonSerialize') === true) {
                $serialised = $value->jsonSerialize();
                if (is_array($serialised) === true) {
                    return $serialised;
                }
            }

            if (method_exists($value, 'toArray') === true) {
                $arr = $value->toArray();
                if (is_array($arr) === true) {
                    return $arr;
                }
            }

            return (array) $value;
        }

        return [];
    }//end toArray()
}//end class
