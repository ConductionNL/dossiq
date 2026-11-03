<?php

/**
 * Dossiq Case Type Terms Controller.
 *
 * A case type's terms as sentences, for the Terms card on the Phases and tasks
 * tab of the case type page. Its own controller because CaseTypeController is
 * at the collaborator count phpmd allows.
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Access\ReadableObjects;
use OCA\Dossiq\Service\CaseType\CaseTypeTerms;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Answers a case type's terms in words.
 *
 * @spec openspec/specs/case-types/spec.md
 */
class CaseTypeTermsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string         $appName The app name.
	 * @param IRequest       $request The request.
	 * @param CaseTypeTerms $terms  Groups the workflow steps under the statuses.
	 * @param ReadableObjects $readable Whether a caller is signed in and may read the requested object.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseTypeTerms $terms,
		private readonly ReadableObjects $readable,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The terms of a case type in words.
	 *
	 * Ordinary users read case types on the case page. The case type is first
	 * read with the caller's own rights; one the caller may not read answers
	 * 404, the same as a missing one.
	 *
	 * @param string $id The case type id.
	 *
	 * @return JSONResponse The terms, or 404 when the case type cannot be read.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	#[NoAdminRequired]
	public function index(string $id): JSONResponse {
		if ($this->readable->hasCaller() === false) {
			return new JSONResponse(['error' => 'Sign in to read this case type.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$readable = $this->readable->findsAsCaller(schema: 'caseType', id: $id);
		} catch (Throwable) {
			return new JSONResponse(['error' => 'The case type could not be read. Try again later.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($readable === false) {
			return new JSONResponse(['error' => 'Case type not found'], Http::STATUS_NOT_FOUND);
		}

		$terms = $this->terms->termsFor(caseTypeId: $id);
		if ($terms === null) {
			return new JSONResponse(['error' => 'Case type not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($terms);
	}//end index()
}//end class
