<?php

/**
 * Dossiq PaymentScheduleController.
 *
 * `GET /api/cases/{caseId}/payment-schedule`: the instalments of the payment
 * schedules on the case's decisions, each with its state, and their totals.
 * The case page draws its payment schedule and amounts cards from it.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-a-handler-runs-a-grant-from-its-case-page-req-sub-case-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Money\PaymentScheduleOverview;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * The payment schedule of a case, for a reader of that case.
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-a-handler-runs-a-grant-from-its-case-page-req-sub-case-01
 */
class PaymentScheduleController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                  $appName     The app name.
	 * @param IRequest                $request     The request.
	 * @param PaymentScheduleOverview $overview    Reads the schedule.
	 * @param IUserSession            $userSession The user session.
	 * @param CaseAccessGuard         $caseAccess  Per-case authorization guard.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PaymentScheduleOverview $overview,
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccess,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The instalments and totals of the case's payment schedules.
	 *
	 * A case the user may not read answers 403 before anything is read.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-a-handler-runs-a-grant-from-its-case-page-req-sub-case-01
	 */
	#[NoAdminRequired]
	public function forCase(string $caseId): JSONResponse {
		$caseId = trim($caseId);
		$user   = $this->userSession->getUser();
		if ($user === null || $this->caseAccess->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'You may not read this case.'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse($this->overview->forCase(caseId: $caseId, today: new DateTimeImmutable('today')));
		} catch (Throwable) {
			return new JSONResponse(['message' => 'The payment schedule could not be read. Try again later.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end forCase()
}//end class
