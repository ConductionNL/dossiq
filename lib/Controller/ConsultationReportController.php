<?php

/**
 * Dossiq consultation report controller.
 *
 *  - GET /api/consultations/due-soon
 *  - GET /api/consultations/bottlenecks
 *
 * The two Adviseringsbeheer reports. The due-soon list holds only requests on
 * cases the caller may read; the bottleneck report is for coordinators.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/consultation-management/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Consultation\ConsultationAccessGuard;
use OCA\Dossiq\Service\Consultation\ConsultationReports;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The due-soon list and the bottleneck report.
 *
 * @spec openspec/specs/consultation-management/spec.md
 */
class ConsultationReportController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                  $appName     The app name.
	 * @param IRequest                $request     The request.
	 * @param ConsultationReports     $reports     The two reports.
	 * @param ConsultationAccessGuard $accessGuard Who may read which request.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ConsultationReports $reports,
		private readonly ConsultationAccessGuard $accessGuard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The waiting consultations due within the warning offset.
	 *
	 * @return JSONResponse `{results, warningOffsetDays}`
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/consultation-management/spec.md#requirement-advice-requests-due-within-the-warning-offset-are-listed
	 */
	public function dueSoon(): JSONResponse {
		$authError = $this->accessGuard->requireUser();
		if ($authError !== null) {
			return $authError;
		}

		return new JSONResponse($this->reports->getDueSoonConsultations(mayRead: $this->accessGuard->readableFilter()));
	}//end dueSoon()

	/**
	 * Per advisory body, the late share over 30 days against the bottleneck threshold.
	 *
	 * @return JSONResponse `{threshold, windowDays, bodies}`
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/consultation-management/spec.md#requirement-advisory-bodies-past-the-bottleneck-threshold-are-flagged
	 */
	public function bottlenecks(): JSONResponse {
		$authError = $this->accessGuard->requireCoordinator();
		if ($authError !== null) {
			return $authError;
		}

		return new JSONResponse($this->reports->getBottlenecks());
	}//end bottlenecks()
}//end class
