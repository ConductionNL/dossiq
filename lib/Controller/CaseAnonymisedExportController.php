<?php

/**
 * Dossiq CaseAnonymisedExportController.
 *
 * The anonymised variant of the case list export.
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
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-case-list-can-be-exported-anonymised-req-sdc-anon-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Export\AnonymisedCaseExport;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Exports the cases of a filtered list with their identifying fields removed or generalised.
 *
 * Each case is read as the signed-in user, so the export holds no case the
 * user could not open in the list itself.
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-case-list-can-be-exported-anonymised-req-sdc-anon-01
 */
class CaseAnonymisedExportController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string               $appName     The app name.
	 * @param IRequest             $request     The request.
	 * @param AnonymisedCaseExport $export      The anonymised export.
	 * @param IUserSession         $userSession The session.
	 * @param LoggerInterface      $logger      The logger.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AnonymisedCaseExport $export,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The listed cases, anonymised.
	 *
	 * @return JSONResponse `cases`, or why nothing was exported.
	 *
	 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-case-list-can-be-exported-anonymised-req-sdc-anon-01
	 */
	#[NoAdminRequired]
	public function export(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseIds = $this->request->getParam('caseIds', []);
		if (is_array($caseIds) === false) {
			return new JSONResponse(['error' => 'caseIds must be a list'], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new JSONResponse(['cases' => $this->export->export(caseIds: $caseIds, userId: $user->getUID())]);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: the anonymised case export failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The anonymised export failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end export()
}//end class
