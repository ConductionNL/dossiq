<?php

/**
 * Dossiq DeadlineEndingFirstController.
 *
 * The running statutory terms that end first (board DqTermijnen, "Verloopt
 * het eerst").
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/termijn-reporting/spec.md#requirement-the-term-dashboard-lists-the-terms-that-end-first
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\ReportingAudienceGuard;
use OCA\Dossiq\Service\Reporting\ReportingAudience;
use OCA\Dossiq\Service\Termijn\TermsEndingFirst;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The terms that end first, behind the reporting audience gate.
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/termijn-reporting/spec.md#requirement-the-term-dashboard-lists-the-terms-that-end-first
 */
class DeadlineEndingFirstController extends Controller {
	use ReportingAudienceGuard;

	/**
	 * Constructor.
	 *
	 * @param string            $appName     App id.
	 * @param IRequest          $request     Request.
	 * @param IUserSession      $userSession User session.
	 * @param LoggerInterface   $logger      Logger.
	 * @param ReportingAudience $audience    Who may read a figure about every case.
	 * @param TermsEndingFirst  $terms       The running terms that end first.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ReportingAudience $audience,
		private readonly TermsEndingFirst $terms,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /api/deadlines/ending-first?limit=n: the running statutory terms,
	 * soonest end first, for a caller in the reporting audience.
	 *
	 * @param int $limit How many terms (default 5, at most 25).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/termijn-reporting/spec.md#requirement-the-term-dashboard-lists-the-terms-that-end-first
	 */
	#[NoAdminRequired]
	public function index(int $limit = TermsEndingFirst::DEFAULT_LIMIT): JSONResponse {
		$denied = $this->refuseOutsideReportingAudience(userSession: $this->userSession, audience: $this->audience);
		if ($denied !== null) {
			return $denied;
		}

		try {
			return new JSONResponse($this->terms->list(limit: $limit));
		} catch (Throwable $e) {
			$this->logger->error('Terms ending first could not be read', ['error' => $e->getMessage()]);

			return new JSONResponse(['message' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()
}//end class
