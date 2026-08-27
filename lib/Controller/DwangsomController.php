<?php

/**
 * Dossiq DwangsomController.
 *
 * REST surface for DwangsomBerekening state + bezwaar lifecycle +
 * beschikking-stop. Defers all business logic to
 * {@see DwangsomCalculationService} and {@see DwangsomBezwaarService}
 * (ADR-022).
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\DwangsomBezwaarService;
use OCA\Dossiq\Service\DwangsomCalculationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\OwningCaseResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Throwable;

/**
 * REST surface for DwangsomBerekening state + bezwaar.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */
class DwangsomController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request Request.
	 * @param DwangsomCalculationService $calc Calculation service.
	 * @param DwangsomBezwaarService $objection Bezwaar service.
	 * @param SettingsService $settings Settings.
	 * @param IUserSession $userSession User session.
	 * @param CaseAccessGuard $caseAccess Per-case authorization guard.
	 * @param OwningCaseResolver $owningCase Resolves a berekening's owning case.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DwangsomCalculationService $calc,
		private readonly DwangsomBezwaarService $objection,
		private readonly SettingsService $settings,
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccess,
		private readonly OwningCaseResolver $owningCase,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Per-object authorization guard for a DwangsomBerekening.
	 *
	 * Replaces the former `ensureAuthenticated()`, which was documented as a
	 * "per-object authorization guard" and has never been one: it established
	 * that somebody was logged in and nothing else. Every method here is
	 * `@NoAdminRequired`, so that admitted any authenticated account to any
	 * berekening.
	 *
	 * A berekening has no owner field of its own. It belongs to a case through
	 * `termijnInstance` -> `zaak`, so the decision is delegated to
	 * {@see CaseAccessGuard} on the owning case, exactly as the other
	 * case-scoped surfaces in this app do.
	 *
	 * Fails closed at every branch: an unresolvable chain, an absent
	 * OpenRegister, or an unconfigured schema all DENY. A berekening whose
	 * owning case cannot be established is not a berekening anyone may act on.
	 *
	 * @param string $calculationId The DwangsomBerekening UUID.
	 * @param bool $mutation True for write verbs, false for reads.
	 *
	 * @return JSONResponse|null A refusal, or null when access is granted.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	private function denyUnlessMayAccess(string $calculationId, bool $mutation): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
		}

		$caseId = $this->owningCase->resolveVia(
			objectId: $calculationId,
			schemaKey: 'dwangsom_berekening_schema',
			linkField: 'deadlineInstance',
			viaSchemaKey: 'termijn_instance_schema',
			caseField: 'case'
		);

		if ($caseId !== null
			&& $this->mayAccessCase(caseId: $caseId, user: $user, mutation: $mutation) === true
		) {
			return null;
		}

		return new JSONResponse(
			['message' => 'Not authorized for dwangsom berekening ' . $calculationId],
			Http::STATUS_FORBIDDEN
		);
	}//end denyUnlessMayAccess()

	/**
	 * Apply the verb-appropriate case check.
	 *
	 * Writes need mutation access; reads take the slightly wider read access
	 * that also honours the `assignees` array. Kept separate so the read check
	 * is never consulted on a write verb.
	 *
	 * @param string $caseId The owning case UUID.
	 * @param IUser $user The authenticated user.
	 * @param bool $mutation True for write verbs, false for reads.
	 *
	 * @return bool True when the user may proceed.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	private function mayAccessCase(string $caseId, IUser $user, bool $mutation): bool {
		if ($mutation === true) {
			return $this->caseAccess->hasCaseMutationAccess(caseId: $caseId, user: $user);
		}

		return $this->caseAccess->hasCaseReadAccess(caseId: $caseId, user: $user);
	}//end mayAccessCase()

	/**
	 * Get a DwangsomBerekening by id.
	 *
	 * @param string $id Id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function show(string $id): JSONResponse {
		$denied = $this->denyUnlessMayAccess(calculationId: $id, mutation: false);
		if ($denied !== null) {
			return $denied;
		}

		$objectService = $this->settings->getObjectService();
		$register = (string)$this->settings->getConfigValue('register');
		$schema = (string)$this->settings->getConfigValue('dwangsom_berekening_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return new JSONResponse(['message' => 'Service unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		try {
			$row = $objectService->find($id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			return new JSONResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$calculation = $this->normalise(value: $row);
		if ($calculation === null) {
			return new JSONResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($calculation);
	}//end show()

	/**
	 * Normalise an OpenRegister result into a plain array.
	 *
	 * `ObjectService::find()` is declared `: ?ObjectEntity` and never returns an
	 * array, so the previous `is_array($row) === false` test was true for every
	 * existing berekening and `show()` answered 404 to everyone, its own case
	 * assignee included. Normalise instead of testing.
	 *
	 * `is_callable()` rather than `method_exists()`: ObjectEntity reaches
	 * several accessors through `Entity::__call()`, for which `method_exists()`
	 * is false.
	 *
	 * @param mixed $value The value returned by ObjectService.
	 *
	 * @return array<string, mixed>|null The array form, or null when absent.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	private function normalise(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === false || is_callable([$value, 'jsonSerialize']) === false) {
			return null;
		}

		$serialised = $value->jsonSerialize();
		if (is_array($serialised) === false) {
			return null;
		}

		return $serialised;
	}//end normalise()

	/**
	 * Stop the berekening because a beschikking was filed.
	 *
	 * @param string $id Id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function beschikking(string $id): JSONResponse {
		$denied = $this->denyUnlessMayAccess(calculationId: $id, mutation: true);
		if ($denied !== null) {
			return $denied;
		}

		$row = $this->calc->stopForBeschikking($id);
		if ($row === null) {
			return new JSONResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($row);
	}//end beschikking()

	/**
	 * Register a bezwaar.
	 *
	 * @param string $id Id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function bezwaar(string $id): JSONResponse {
		$denied = $this->denyUnlessMayAccess(calculationId: $id, mutation: true);
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();
		$basis = (string)($body['basis'] ?? 'AWB 7:1');
		$rationale = (string)($body['rationale'] ?? '');

		try {
			$row = $this->objection->registerBezwaar($id, $basis, $rationale);
			return new JSONResponse($row);
		} catch (Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end bezwaar()

	/**
	 * Resolve a bezwaar with a corrected amount.
	 *
	 * @param string $id Id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function bezwaarHeroverweging(string $id): JSONResponse {
		$denied = $this->denyUnlessMayAccess(calculationId: $id, mutation: true);
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();
		$newAmount = (int)($body['newBedragCents'] ?? -1);
		$basis = (string)($body['basis'] ?? 'AWB 7:11');
		if ($newAmount < 0) {
			return new JSONResponse(['message' => 'newBedragCents required and must be >= 0'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$row = $this->objection->resolveBezwaar($id, $newAmount, $basis);
			return new JSONResponse($row);
		} catch (Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end bezwaarHeroverweging()

	/**
	 * Decode the JSON request body into an associative array.
	 *
	 * @return array<string, mixed>
	 */
	private function jsonBody(): array {
		// OCP\IRequest::getContent() is protected on the concrete OC
		// request; read raw payload from php://input instead.
		$raw = (string)file_get_contents('php://input');
		$body = json_decode($raw, true);
		if (is_array($body) === true) {
			return $body;
		}

		return [];
	}//end jsonBody()
}//end class
