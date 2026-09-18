<?php

/**
 * Dossiq Case Split Controller.
 *
 *  - GET  /api/case/{caseId}/split
 *  - POST /api/case/{caseId}/split
 *
 * The HTTP half of a split. The rules shipped first and without one:
 * `CaseSplitPolicy` and `CaseSplitPlan` were merged complete, tested and
 * reachable from nothing, and the change that shipped them said so rather than
 * leaving it to be discovered. This is that surface.
 *
 * The GET answers what this case holds, per part the case type allows, so the
 * picker offers only the parts that can actually be divided: a checkbox for
 * something the server will refuse is a checkbox that wastes a split.
 *
 * The refusal sentence is the policy's, passed through verbatim. It already
 * names what may STILL be divided, and a handler told only what they may not
 * do guesses at the rest, and the guess is usually "nothing".
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
 * @spec openspec/changes/case-split-surface/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Cases\CaseSplitPerformer;
use OCA\Dossiq\Service\Cases\CaseSplitPolicy;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Offers the division, and performs it.
 *
 * @spec openspec/changes/case-split-surface/specs/case-management/spec.md#requirement-a-handler-can-split-a-case-from-its-own-page-req-cm-48
 */
class CaseSplitController extends Controller {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param string             $appName         The app name.
	 * @param IRequest           $request         The HTTP request.
	 * @param CaseSplitPerformer $performer       Reads, opens and performs.
	 * @param CaseSplitPolicy    $policy          What a case type allows.
	 * @param CaseAccessGuard    $caseAccessGuard Per-case authorization (fails closed).
	 * @param SettingsService    $settingsService Reads the case and its type.
	 * @param IUserSession       $userSession     The current session.
	 * @param IL10N              $l10n            The translations.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseSplitPerformer $performer,
		private readonly CaseSplitPolicy $policy,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * What this case holds that a split may divide.
	 *
	 * @param string $caseId The case.
	 *
	 * @return JSONResponse The allowed parts and their items.
	 *
	 * @spec openspec/changes/case-split-surface/specs/case-management/spec.md#requirement-a-handler-can-split-a-case-from-its-own-page-req-cm-48
	 */
	#[NoAdminRequired]
	public function divisible(string $caseId): JSONResponse {
		$refusal = $this->requireHandler(caseId: $caseId);
		if ($refusal !== null) {
			return $refusal;
		}

		$source = $this->readCase(caseId: $caseId);
		if ($source === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('This case no longer exists.')],
				Http::STATUS_NOT_FOUND
			);
		}

		$caseType = $this->readCaseType(source: $source);

		return new JSONResponse(
			[
				'allowed' => $this->policy->allowedFor(caseType: $caseType),
				'parts' => $this->performer->divisible(caseId: $caseId, caseType: $caseType),
			]
		);
	}//end divisible()

	/**
	 * Divide this case in two.
	 *
	 * @param string $caseId The case being split.
	 *
	 * @return JSONResponse The new case and what moved, or the refusal.
	 *
	 * @spec openspec/changes/case-split-surface/specs/case-management/spec.md#requirement-a-handler-can-split-a-case-from-its-own-page-req-cm-48
	 */
	#[NoAdminRequired]
	public function split(string $caseId): JSONResponse {
		$refusal = $this->requireHandler(caseId: $caseId);
		if ($refusal !== null) {
			return $refusal;
		}

		$source = $this->readCase(caseId: $caseId);
		if ($source === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('This case no longer exists.')],
				Http::STATUS_NOT_FOUND
			);
		}

		$selection = [];
		foreach (CaseSplitPolicy::PARTS as $part) {
			$selection[$part] = array_values(
				array_filter(
					array_map('strval', (array)$this->request->getParam($part, [])),
					static fn (string $id): bool => (trim($id) !== '')
				)
			);
		}

		$outcome = $this->performer->perform(
			source: $source,
			caseType: $this->readCaseType(source: $source),
			selection: $selection,
			title: trim((string)$this->request->getParam('title', ''))
		);

		$refused = (string)($outcome['refused'] ?? '');
		if ($refused !== '') {
			// The policy wrote this sentence at the point it knew why, so it
			// travels to the handler unchanged (ADR-050).
			return new JSONResponse(['error' => $refused], Http::STATUS_CONFLICT);
		}

		return new JSONResponse(
			[
				'case' => ($outcome['case'] ?? []),
				'moved' => (int)($outcome['moved'] ?? 0),
				// Rows that turned out to sit on another case. Named rather
				// than dropped: a selection that half happened with no word
				// about the rest is the state nobody can reconstruct later.
				'refusedRows' => ($outcome['refusedRows'] ?? []),
			]
		);
	}//end split()

	/**
	 * Refuse anyone who may not change this case.
	 *
	 * @param string $caseId The case.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function requireHandler(string $caseId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('You are not signed in.')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('You do not handle this case.')],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end requireHandler()

	/**
	 * Read one case.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<string, mixed>|null The case.
	 */
	private function readCase(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			return $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId
			);
		} catch (Throwable $e) {
			return null;
		}
	}//end readCase()

	/**
	 * The case type behind a case, or null.
	 *
	 * Null is what `CaseSplitPolicy` reads as "everything may be divided",
	 * which is the right direction and only here: the declaration is an
	 * administrator's restriction on a default that was always permissive, so
	 * failing to read it must not invent a restriction nobody declared.
	 *
	 * @param array<string, mixed> $source The case.
	 *
	 * @return array<string, mixed>|null The case type.
	 */
	private function readCaseType(array $source): ?array {
		$reference = ($source['caseType'] ?? null);
		$id = (is_array($reference) === true ? (string)($reference['id'] ?? '') : trim((string)$reference));
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_type_schema');
		if ($id === '' || $objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			return $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $id
			);
		} catch (Throwable $e) {
			return null;
		}
	}//end readCaseType()
}//end class
