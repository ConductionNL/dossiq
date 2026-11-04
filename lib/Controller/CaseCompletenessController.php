<?php

/**
 * Dossiq CaseCompletenessController.
 *
 * `GET /api/cases/{caseId}/completeness`: the completeness items the case
 * type declares, each ticked against the case's documents and fields, for the
 * "Volledigheid van de aanvraag" card.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-completeness-checklist/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Intake\CaseCompleteness;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers how complete the application of a case is.
 *
 * @spec openspec/changes/case-completeness-checklist/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
 */
class CaseCompletenessController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request       The request.
	 * @param CaseAccessGuard        $caseAccess    Whether the caller may read the case.
	 * @param CaseStatusStore        $cases         Reads the case.
	 * @param CaseTypeResolver       $caseTypes     Reads the effective case type.
	 * @param ZaakdossierService     $dossier       Reads the case's documents.
	 * @param InformatieobjectReader $reader        Which documents the caller may read.
	 * @param CaseCompleteness       $completeness  Ticks the items.
	 * @param IUserSession           $userSession   The caller.
	 * @param LoggerInterface        $logger        Logs a failed read.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseAccessGuard $caseAccess,
		private readonly CaseStatusStore $cases,
		private readonly CaseTypeResolver $caseTypes,
		private readonly ZaakdossierService $dossier,
		private readonly InformatieobjectReader $reader,
		private readonly CaseCompleteness $completeness,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The completeness of the case's application.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse `{ items, present, total, missing, verdict }`, `{ items: [] }` without a declared list, or a refusal.
	 *
	 * @spec openspec/changes/case-completeness-checklist/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
	 */
	#[NoAdminRequired]
	public function show(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccess->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'You may not read this case.'], Http::STATUS_FORBIDDEN);
		}

		try {
			$case = $this->cases->loadCase(caseId: $caseId);
			if ($case === null) {
				return new JSONResponse(['message' => 'Case not found.'], Http::STATUS_NOT_FOUND);
			}

			$caseTypeId = $case['caseType'] ?? '';
			if (is_array($caseTypeId) === true) {
				$caseTypeId = ($caseTypeId['id'] ?? '');
			}

			$items = $this->completeness->itemsOf(caseType: $this->caseTypes->effectiveCaseType(caseTypeId: (string)$caseTypeId));
			if ($items === []) {
				return new JSONResponse(['items' => []]);
			}

			$documents = ($this->dossier->getDossierForCase(caseId: $caseId)['informatieobjecten'] ?? []);
			$readable  = $this->reader->filterForUser(user: $user, informatieobjecten: $documents);

			return new JSONResponse(
				$this->completeness->check(items: $items, case: $case, documents: $documents, readable: $readable)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: completeness of case ' . $caseId . ' could not be read: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);

			return new JSONResponse(['message' => 'The completeness could not be read.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}//end try
	}//end show()
}//end class
