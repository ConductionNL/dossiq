<?php

/**
 * The two retention clocks of a live case.
 *
 *  - GET  /api/case/{caseId}/retention-clocks       the two clocks, apart
 *
 * Split from {@see CaseRecycleController}: the other gestures there act on a
 * case in OpenRegister's recycle state, while this one reads a case that still
 * exists, so the ordinary case authority applies to it.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-management/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\CaseAccessRefusal;
use OCA\Dossiq\Controller\Support\LiveCaseRetentionClocks;
use OCA\Dossiq\Kernel\AppIdentity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers the lawful-purpose clock and the archive clock of a live case.
 *
 * @spec openspec/specs/case-management/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class RetentionClocksController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request The request.
	 * @param CaseAccessRefusal       $access  The per-case read refusal (401 signed out, 403 no access).
	 * @param LiveCaseRetentionClocks $clocks  The live case's two clocks.
	 * @param LoggerInterface         $logger  The logger.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseAccessRefusal $access,
		private readonly LiveCaseRetentionClocks $clocks,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The lawful-purpose clock and the archive clock of a live case.
	 *
	 * Guarded per case, because this is a read of a case that still exists and
	 * the ordinary case authority applies to it.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/case-management/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	#[NoAdminRequired]
	public function clocks(string $caseId): JSONResponse {
		$refusal = $this->access->requireReadAccess(caseId: $caseId);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$clocks = $this->clocks->forCase(caseId: $caseId);
			if ($clocks === null) {
				return new JSONResponse(['error' => 'The case does not allow this', 'code' => 'case_not_found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse($clocks);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: could not read the retention clocks: ' . $e->getMessage());

			return new JSONResponse(
				['error' => 'Could not read the retention clocks'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end clocks()
}//end class
