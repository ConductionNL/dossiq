<?php

/**
 * Dossiq mail parties report controller.
 *
 * Answers the parties of a case's collected mail (REQ-WRR-004) for any case
 * type. Who may read it is decided here, in the body: `#[NoAdminRequired]`
 * lets any signed-in user reach the method, which then needs the
 * organisation's parties switch (REQ-WRR-001) and read access to the case.
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
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Zaakdossier\MailPartiesReport;
use OCA\Dossiq\Woo\WooReportSwitches;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The parties report of one case's collected mail, with its CSV.
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
 */
class MailPartiesReportController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request       The request.
	 * @param WooReportSwitches $switches      The organisation's report switches.
	 * @param CaseAccessGuard   $accessGuard   Whether the caller may read the case.
	 * @param MailPartiesReport $partiesReport The parties of a case's collected mail.
	 * @param IUserSession      $userSession   The user session.
	 * @param IL10N             $l10n          The translations.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly WooReportSwitches $switches,
		private readonly CaseAccessGuard $accessGuard,
		private readonly MailPartiesReport $partiesReport,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Counts per sender, recipient and domain of the case's collected mail, plus unreadable.
	 *
	 * `?format=csv` answers the same rows as a CSV download.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse|DataDownloadResponse The report, or the refusal.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse|DataDownloadResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->switches->isOn(switch: WooReportSwitches::PARTIES) === false) {
			return new JSONResponse(
				['error' => 'woo-report-off', 'message' => $this->l10n->t('Your organisation has not switched this report on.')],
				Http::STATUS_FORBIDDEN
			);
		}

		if ($this->accessGuard->hasCaseReadAccess(caseId: $id, user: $user) === false) {
			return new JSONResponse(
				['error' => 'case-not-readable', 'message' => $this->l10n->t('You cannot read this case, so its report cannot be shown.')],
				Http::STATUS_FORBIDDEN
			);
		}

		$report = $this->partiesReport->forCase(caseId: $id);
		if ($this->request->getParam('format', '') === 'csv') {
			return new DataDownloadResponse($this->partiesReport->toCsv(report: $report), 'mail-parties.csv', 'text/csv; charset=utf-8');
		}

		return new JSONResponse(['case' => $id] + $report);
	}//end show()
}//end class
