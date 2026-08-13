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
class ParaferingAuditExportController extends Controller {
	/**
	 * Groups that may export the audit trail.
	 */
	private const ALLOWED_GROUPS = ['auditors', 'secretariaat', 'beheerders', 'admin'];

	/**
	 * Constructor.
	 *
	 * @param string $appName Nextcloud app id
	 * @param IRequest $request Incoming request
	 * @param IUserSession $userSession Current user session
	 * @param IGroupManager $groupManager Group manager (for RBAC check)
	 * @param AuditTrailService $auditTrailService The audit-trail service
	 * @param SettingsService $settingsService Procest settings bridge
	 * @param LoggerInterface $logger PSR-3 logger
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
	 * @param string $id Voorstel UUID/slug from the route
	 * @param string $format Export format (currently only "json")
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/parafering-audit-trail/spec.md
	 */
	#[NoAdminRequired]
	public function export(string $id, string $format = 'json'): JSONResponse {
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return new JSONResponse(
					['message' => 'Authentication required'],
					Http::STATUS_UNAUTHORIZED,
				);
			}

			$uid = $user->getUID();
			if ($this->isAuditorAuthorized(uid: $uid) === false) {
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

			$proposalOnderwerp = $this->resolveProposalOnderwerp(proposalId: $id);
			if ($proposalOnderwerp === null) {
				return new JSONResponse(
					['message' => 'Voorstel not found'],
					Http::STATUS_NOT_FOUND,
				);
			}

			$envelope = $this->auditTrailService->export(
				proposalId: $id,
				proposalOnderwerp: $proposalOnderwerp,
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
	 * Determine whether a user may export audit trails.
	 *
	 * @param string $uid The acting user UID
	 *
	 * @return bool
	 */
	private function isAuditorAuthorized(string $uid): bool {
		foreach (self::ALLOWED_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		// Also allow Nextcloud admins (defensive default).
		return $this->groupManager->isAdmin($uid) === true;
	}//end isAuditorAuthorized()

	/**
	 * Coerce an OpenRegister result value into an associative array.
	 *
	 * @param mixed $value Result value from ObjectService
	 *
	 * @return array<string, mixed>
	 */
	private function coerceToArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === false) {
			return [];
		}

		if (method_exists($value, 'jsonSerialize') === true) {
			$serialized = $value->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}

			return [];
		}

		if (method_exists($value, 'toArray') === true) {
			$arr = $value->toArray();
			if (is_array($arr) === true) {
				return $arr;
			}
		}

		return [];
	}//end coerceToArray()

	/**
	 * Resolve the voorstel onderwerp (or null when not found).
	 *
	 * @param string $proposalId The voorstel UUID/slug
	 *
	 * @return string|null
	 */
	private function resolveProposalOnderwerp(string $proposalId): ?string {
		try {
			$objectService = $this->settingsService->getObjectService();
			if ($objectService === null) {
				return null;
			}

			$register = $this->settingsService->getConfigValue('register');
			$schema = $this->settingsService->getConfigValue('voorstel_schema');
			if ($register === '' || $schema === '') {
				return null;
			}

			$proposal = $objectService->find($proposalId, register: $register, schema: $schema);
			if ($proposal === null) {
				return null;
			}

			$array = $this->coerceToArray(value: $proposal);

			return (string)($array['onderwerp'] ?? '');
		} catch (Throwable $e) {
			$this->logger->warning(
				'Procest: failed to resolve voorstel onderwerp for export',
				['voorstel' => $proposalId, 'exception' => $e->getMessage()],
			);

			return null;
		}//end try
	}//end resolveVoorstelOnderwerp()
}//end class
