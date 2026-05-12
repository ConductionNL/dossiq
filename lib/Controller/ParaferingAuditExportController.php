<?php

/**
 * Parafering Audit Export Controller
 *
 * Exposes a single action endpoint that produces an Archiefwet-aligned audit
 * trail export for a voorstel. NO CRUD — this is a read-only action endpoint.
 * Listing of audit entries is served by OpenRegister's auto-exposed
 * /api/objects/&lt;register&gt;/&lt;schema&gt; route via the manifest index page.
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
 * @spec openspec/changes/parafering-audit-trail/tasks.md#T07
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\Parafering\AuditTrailService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only action controller for the parafering audit trail export.
 */
class ParaferingAuditExportController extends Controller
{
    /**
     * Groups that may export the audit trail.
     */
    private const ALLOWED_GROUPS = ['auditors', 'secretariaat', 'beheerders', 'admin'];

    /**
     * Constructor.
     *
     * @param string            $appName           Nextcloud app id
     * @param IRequest          $request           Incoming request
     * @param IUserSession      $userSession       Current user session
     * @param IGroupManager     $groupManager      Group manager (for RBAC check)
     * @param AuditTrailService $auditTrailService The audit-trail service
     * @param SettingsService   $settingsService   Procest settings bridge
     * @param LoggerInterface   $logger            PSR-3 logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly AuditTrailService $auditTrailService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Export the audit trail for a voorstel.
     *
     * @param string $id     Voorstel UUID/slug from the route
     * @param string $format Export format (currently only "json")
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function export(string $id, string $format='json'): JSONResponse
    {
        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return new JSONResponse(
                    ['message' => 'Authentication required'],
                    Http::STATUS_UNAUTHORIZED,
                );
            }

            $uid     = $user->getUID();
            $allowed = false;
            foreach (self::ALLOWED_GROUPS as $group) {
                if ($this->groupManager->isInGroup($uid, $group) === true) {
                    $allowed = true;
                    break;
                }
            }

            // Also allow Nextcloud admins (defensive default).
            if ($allowed === false && $this->groupManager->isAdmin($uid) === true) {
                $allowed = true;
            }

            if ($allowed === false) {
                return new JSONResponse(
                    ['message' => 'Audit export requires auditor role'],
                    Http::STATUS_FORBIDDEN,
                );
            }

            if ($id === '') {
                return new JSONResponse(
                    ['message' => 'voorstel id is required'],
                    Http::STATUS_BAD_REQUEST,
                );
            }

            $voorstelOnderwerp = $this->resolveVoorstelOnderwerp(voorstelId: $id);
            if ($voorstelOnderwerp === null) {
                return new JSONResponse(
                    ['message' => 'Voorstel not found'],
                    Http::STATUS_NOT_FOUND,
                );
            }

            $envelope = $this->auditTrailService->export(
                voorstelId: $id,
                voorstelOnderwerp: $voorstelOnderwerp,
                exportedBy: $uid,
            );

            if (strtolower($format) !== 'json') {
                // XML/CSV profiles deferred (per design.md); JSON is the V1 canonical format.
                $envelope['metadata']['note'] = 'Only JSON export is supported in V1';
            }

            return new JSONResponse($envelope, Http::STATUS_OK);
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: parafering audit export failed',
                ['voorstel' => $id, 'exception' => $e->getMessage()],
            );

            return new JSONResponse(
                ['message' => 'Export failed'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }//end try
    }//end export()

    /**
     * Resolve the voorstel onderwerp (or null when not found).
     *
     * @param string $voorstelId The voorstel UUID/slug
     *
     * @return string|null
     */
    private function resolveVoorstelOnderwerp(string $voorstelId): ?string
    {
        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                return null;
            }

            $register = $this->settingsService->getConfigValue('register');
            $schema   = $this->settingsService->getConfigValue('voorstel_schema');
            if ($register === '' || $schema === '') {
                return null;
            }

            $voorstel = $objectService->findObject($register, $schema, $voorstelId);
            if ($voorstel === null) {
                return null;
            }

            $array = [];
            if (is_array($voorstel) === true) {
                $array = $voorstel;
            } else if (is_object($voorstel) === true) {
                if (method_exists($voorstel, 'jsonSerialize') === true) {
                    $serialized = $voorstel->jsonSerialize();
                    if (is_array($serialized) === true) {
                        $array = $serialized;
                    }
                } else if (method_exists($voorstel, 'toArray') === true) {
                    $arr = $voorstel->toArray();
                    if (is_array($arr) === true) {
                        $array = $arr;
                    }
                }
            }

            return (string) ($array['onderwerp'] ?? '');
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest: failed to resolve voorstel onderwerp for export',
                ['voorstel' => $voorstelId, 'exception' => $e->getMessage()],
            );

            return null;
        }//end try
    }//end resolveVoorstelOnderwerp()
}//end class
