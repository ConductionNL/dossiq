<?php

/**
 * The group of similar reports one case was placed in.
 *
 * A read only: a case is placed in hermiq's grouping once, when it is created
 * (ReportGroupingJob). Opening it here reads the group as it now stands, with
 * the count and the near-duplicates beside it.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-near-duplicates-are-visible-beside-the-group
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Ai\ReportGroupingConsumer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reads the report group of one case.
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-near-duplicates-are-visible-beside-the-group
 */
class ReportGroupController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                 $appName     The application name.
	 * @param IRequest               $request     The request.
	 * @param ReportGroupingConsumer $grouping    Reads the group.
	 * @param IUserSession           $userSession Resolves the requesting user.
	 * @param IL10N                  $l10n        Translations.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ReportGroupingConsumer $grouping,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The group one case is in, with its count and near-duplicates.
	 *
	 * A case the caller cannot read answers 404, like one that does not exist.
	 *
	 * @return JSONResponse `{declared, available, group}`, or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-near-duplicates-are-visible-beside-the-group
	 */
	public function show(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		$caseId = trim((string)$this->request->getParam('caseId', ''));
		if ($caseId === '') {
			return new JSONResponse(
				['error' => $this->l10n->t('caseId is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$answer = $this->grouping->currentGroup(caseId: $caseId);
		if ($answer === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Case not found')],
				Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse($answer);
	}//end show()
}//end class
