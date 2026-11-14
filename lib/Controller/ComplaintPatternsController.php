<?php

/**
 * Dossiq ComplaintPatternsController.
 *
 * The complaint patterns of a quarter (board DqProcesanalyse, "Klachten:
 * terugkerende patronen"), as JSON or as the PDF system report.
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
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\ReportingAudienceGuard;
use OCA\Dossiq\Kernel\Contracts\ComplaintPatternsInterface;
use OCA\Dossiq\Kernel\Contracts\ComplaintPatternsPdfInterface;
use OCA\Dossiq\Kernel\Contracts\ReportingAudienceInterface;
use OCA\Dossiq\Service\Complaint\QuarterPeriod;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The complaint patterns, behind the reporting audience gate.
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
 *
 * @SuppressWarnings(PHPMD.StaticAccess) QuarterPeriod::fromParam is a named constructor of a value object.
 */
class ComplaintPatternsController extends Controller {
	use ReportingAudienceGuard;

	/**
	 * Constructor.
	 *
	 * @param string               $appName     App id.
	 * @param IRequest             $request     Request.
	 * @param IUserSession         $userSession User session.
	 * @param LoggerInterface      $logger      Logger.
	 * @param ReportingAudienceInterface    $audience    Who may read a figure about every case.
	 * @param ComplaintPatternsInterface    $patterns    The patterns of a quarter.
	 * @param ComplaintPatternsPdfInterface $pdf         The patterns as a PDF report.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ReportingAudienceInterface $audience,
		private readonly ComplaintPatternsInterface $patterns,
		private readonly ComplaintPatternsPdfInterface $pdf,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /api/complaints/patterns?quarter=YYYY-Qn[&format=pdf]
	 *
	 * @param string $quarter The quarter; the last full one when empty.
	 * @param string $format  `pdf` for the system report as a download.
	 *
	 * @return Response
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
	 */
	#[NoAdminRequired]
	public function index(string $quarter = '', string $format = ''): Response {
		$denied = $this->refuseOutsideReportingAudience(userSession: $this->userSession, audience: $this->audience);
		if ($denied !== null) {
			return $denied;
		}

		$period = QuarterPeriod::fromParam(param: $quarter);
		if ($period === null) {
			return new JSONResponse(['message' => 'quarter must read YYYY-Qn'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$answer = $this->patterns->forQuarter(period: $period);
		} catch (Throwable $e) {
			$this->logger->error('Complaint patterns could not be read', ['error' => $e->getMessage()]);

			return new JSONResponse(['message' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($format !== 'pdf' || ($answer['available'] ?? false) !== true) {
			return new JSONResponse($answer);
		}

		$name = 'complaint-patterns-'.$period->year.'-Q'.$period->quarter.'.pdf';

		return new DataDownloadResponse($this->pdf->render(patterns: $answer), $name, 'application/pdf');
	}//end index()
}//end class
