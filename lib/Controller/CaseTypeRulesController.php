<?php

/**
 * Dossiq Case Type Rules Controller.
 *
 * A case type's rules as "If ... / then ..." rows, for the Rules tab of the
 * case type page. Its own controller because CaseTypeController is at the
 * collaborator count phpmd allows.
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
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\Contracts\CaseTypeRuleSentencesInterface;
use OCA\Dossiq\Kernel\Contracts\ReadableObjectsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Answers a case type's rules in words.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
 */
class CaseTypeRulesController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string         $appName The app name.
	 * @param IRequest       $request The request.
	 * @param CaseTypeRuleSentencesInterface $sentences Writes the rules as rows.
	 * @param ReadableObjectsInterface $readable Whether a caller is signed in and may read the requested object.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseTypeRuleSentencesInterface $sentences,
		private readonly ReadableObjectsInterface $readable,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The rules of a case type as rows.
	 *
	 * Ordinary users read case types on the case page. The case type is first
	 * read with the caller's own rights; one the caller may not read answers
	 * 404, the same as a missing one.
	 *
	 * @param string $id The case type id.
	 *
	 * @return JSONResponse The rows, or 404 when the case type cannot be read.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
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

		$rules = $this->sentences->rulesFor(caseTypeId: $id);
		if ($rules === null) {
			return new JSONResponse(['error' => 'Case type not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($rules);
	}//end index()
}//end class
