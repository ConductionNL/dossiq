<?php

/**
 * Dossiq ZGW Business Rules Service
 *
 * The entry point every ZGW write passes through. It owns the cross-cutting
 * checks that must run BEFORE any per-resource rule — catalogi concept
 * protection (ztc-009/010), the draft→published publish guard (CT-02b), the
 * destroy guard on caseTypes with active cases (CT-01d), and closed-zaak
 * protection (zrc-007) — and then hands the request to
 * {@see \OCA\Dossiq\Service\Zgw\ZgwRulesDispatcher}, which owns the routing
 * table to the per-register rule services (ZRC, ZTC, DRC, BRC).
 *
 * Cross-register rules (zrc-005, brc-005, brc-006) live in ZgwService.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Zgw\LookupNotCompleted;
use OCA\Dossiq\Service\Zgw\ZgwRuleResults;
use OCA\Dossiq\Service\Zgw\ZgwRulesDispatcher;

/**
 * Applies the cross-cutting ZGW guards, then delegates to the rules dispatcher.
 *
 * Handles cross-register concerns like concept protection (ztc-009/010)
 * and closed-zaak protection (zrc-007) before delegating.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZgwBusinessRulesService {
	/**
	 * The zrc-007 refusal of a write to a closed zaak without the geforceerd-bijwerken scope.
	 *
	 * @var array
	 */
	private const CLOSED_CASE_REFUSAL = [
		'valid' => false,
		'status' => 403,
		'detail' => 'Zaak is afgesloten. Wijzigingen zijn niet toegestaan zonder scope zaken.geforceerd-bijwerken.',
		'code' => 'permission_denied',
		'invalidParams' => [
			[
				'name' => 'nonFieldErrors',
				'code' => 'zaak-closed',
				'reason' => 'De zaak is afgesloten.',
			],
		],
		'enrichedBody' => [],
	];

	/**
	 * The HTTP status per delete guard refusal code.
	 *
	 * @var array<string, int>
	 */
	private const DESTROY_REFUSAL_STATUS = [
		'destroy_check_not_completed' => ZgwRuleResults::NOT_VERIFIED_STATUS,
		'destroy_blocked_active_cases' => 409,
		'destroy_requires_confirmation' => 409,
	];

	/**
	 * Constructor.
	 *
	 * @param ZgwZtcRulesService $ztcRules ZTC (Catalogi) rules, used by the concept/publish/destroy guards
	 * @param ZgwRulesDispatcher $dispatcher Routes a validated request to the rules service that owns it
	 * @param ZgwRuleResults $results Builds the refusal when a rule's read could not run
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ZgwZtcRulesService $ztcRules,
		private readonly ZgwRulesDispatcher $dispatcher,
		private readonly ZgwRuleResults $results,
	) {
	}//end __construct()

	/**
	 * Validate and enrich a request body before saving.
	 *
	 * @param string $zgwApi The ZGW API group (e.g. 'zaken', 'besluiten')
	 * @param string $resource The ZGW resource name (e.g. 'zaken', 'besluiten')
	 * @param string $action The action ('create', 'update', 'patch', 'destroy')
	 * @param array $body The ZGW request body (Dutch field names)
	 * @param array|null $existingObject The existing object data (for update/patch/destroy)
	 * @param object|null $objectService The OpenRegister ObjectService
	 * @param array|null $mappingConfig The mapping config
	 * @param bool|null $parentCaseTypeDraft Whether the parent zaaktype isDraft (for ztc-010)
	 * @param bool|null $caseClosed Whether the (parent) zaak is closed (for zrc-007)
	 * @param bool $hasGeforceerd Whether consumer has geforceerd-bijwerken scope
	 *
	 * @return array{valid: bool, status: int, detail: string, enrichedBody: array}
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    — ZGW scope flag from middleware
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function validate(
		string $zgwApi,
		string $resource,
		string $action,
		array $body,
		?array $existingObject = null,
		?object $objectService = null,
		?array $mappingConfig = null,
		?bool $parentCaseTypeDraft = null,
		?bool $caseClosed = null,
		bool $hasGeforceerd = true,
	): array {
		$write = new ZgwWriteCheck();
		$write->zgwApi = $zgwApi;
		$write->resource = $resource;
		$write->action = $action;
		$write->body = $body;
		$write->existingObject = $existingObject;
		$write->objectService = $objectService;
		$write->mappingConfig = $mappingConfig;
		$write->parentCaseTypeDraft = $parentCaseTypeDraft;
		$write->caseClosed = $caseClosed;
		$write->hasGeforceerd = $hasGeforceerd;

		return $this->checkWrite(write: $write);
	}//end validate()

	/**
	 * Run the guards and the per-register rules over one write.
	 *
	 * A rule that read the store and could not finish the read throws, and every
	 * such read ends here: nothing decided on it is an answer (REQ-ZBR-022).
	 *
	 * @param ZgwWriteCheck $write The write being checked
	 *
	 * @return array{valid: bool, status: int, detail: string, enrichedBody: array}
	 */
	private function checkWrite(ZgwWriteCheck $write): array {
		try {
			return $this->guardThenDispatch(write: $write);
		} catch (LookupNotCompleted $e) {
			return $this->results->notVerified(
				rule: "{$write->zgwApi}/{$write->resource} {$write->action}",
				object: $e->lookup,
				cause: $e->getMessage()
			);
		}
	}//end checkWrite()

	/**
	 * Set the rules context, return the first guard refusal, or else hand the
	 * write to the per-register rule services.
	 *
	 * @param ZgwWriteCheck $write The write being checked
	 *
	 * @return array{valid: bool, status: int, detail: string, enrichedBody: array}
	 */
	private function guardThenDispatch(ZgwWriteCheck $write): array {
		// Set context on the ZTC rules this service guards with directly, and
		// on every rules service the dispatcher can route to.
		$this->ztcRules->setContext($write->objectService, $write->mappingConfig);
		$this->dispatcher->setContext($write->objectService, $write->mappingConfig);

		return $this->firstGuardRefusal(write: $write) ?? $this->dispatcher->dispatch(
			zgwApi: $write->zgwApi,
			resource: $write->resource,
			action: $write->action,
			body: $write->body,
			existingObject: $write->existingObject
		);
	}//end guardThenDispatch()

	/**
	 * The catalogi concept guards, then the closed-zaak guard (zrc-007).
	 *
	 * @param ZgwWriteCheck $write The write being checked; its body is enriched in place
	 *
	 * @return array|null The guard response, or null when the write may proceed
	 */
	private function firstGuardRefusal(ZgwWriteCheck $write): ?array {
		if ($write->zgwApi === 'catalogi') {
			$catalogiRefusal = $this->catalogiRefusal(write: $write);
			if ($catalogiRefusal !== null) {
				return $catalogiRefusal;
			}
		}

		if ($this->isClosedCaseWithoutForce(write: $write) === true) {
			return self::CLOSED_CASE_REFUSAL;
		}

		return null;
	}//end firstGuardRefusal()

	/**
	 * Whether the write touches a closed zaak without the geforceerd-bijwerken scope (zrc-007).
	 *
	 * @param ZgwWriteCheck $write The write being checked
	 *
	 * @return bool
	 */
	private function isClosedCaseWithoutForce(ZgwWriteCheck $write): bool {
		return $write->caseClosed === true && $write->hasGeforceerd === false;
	}//end isClosedCaseWithoutForce()

	/**
	 * The ZTC cross-cutting concerns: concept protection, then the publish and destroy guards.
	 *
	 * @param ZgwWriteCheck $write The write being checked; its body is enriched in place
	 *
	 * @return array|null The guard response, or null when the write may proceed
	 */
	private function catalogiRefusal(ZgwWriteCheck $write): ?array {
		return $this->applyCatalogiDraftRules(write: $write) ?? $this->caseTypeGuardRefusal(write: $write);
	}//end catalogiRefusal()

	/**
	 * The CT-02b publish guard, then the CT-01d destroy guard.
	 *
	 * @param ZgwWriteCheck $write The write being checked
	 *
	 * @return array|null The guard response, or null when the write may proceed
	 */
	private function caseTypeGuardRefusal(ZgwWriteCheck $write): ?array {
		return $this->guardCaseTypePublish(
			resource: $write->resource,
			action: $write->action,
			body: $write->body,
			existingObject: $write->existingObject,
			mappingConfig: $write->mappingConfig
		) ?? $this->guardCaseTypeDestroy(write: $write);
	}//end caseTypeGuardRefusal()

	/**
	 * Apply the ZTC concept defaults/preservation and the ztc-009/ztc-010
	 * published-type protection.
	 *
	 * @param ZgwWriteCheck $write The write being checked; its body is enriched in place
	 *
	 * @return array|null The guard response, or null when the request may proceed
	 */
	private function applyCatalogiDraftRules(ZgwWriteCheck $write): ?array {
		// Default concept=true for new concept resources.
		if ($write->action === 'create') {
			$write->body = $this->ztcRules->defaultConcept($write->body, $write->resource);
		}

		// Preserve concept on update/patch (only changeable via /publish).
		if (in_array($write->action, ['update', 'patch'], true) === true) {
			$write->body = $this->ztcRules->preserveConcept($write->body, $write->resource, $write->existingObject);
		}

		// Ztc-009/ztc-010: Protect published types from modification.
		return $this->ztcRules->checkConceptProtection(
			$write->resource,
			$write->action,
			$write->body,
			$write->existingObject,
			$write->parentCaseTypeDraft
		);
	}//end applyCatalogiDraftRules()

	/**
	 * CT-02b — publish guard: when a caseType transitions from draft to
	 * published (isDraft toggled false), require status types + final status
	 * + validFrom before allowing the save.
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $action The action
	 * @param array $body The request body
	 * @param array|null $existingObject The existing object data
	 * @param array|null $mappingConfig The mapping config
	 *
	 * @return array|null The guard response, or null when the save may proceed
	 */
	private function guardCaseTypePublish(
		string $resource,
		string $action,
		array $body,
		?array $existingObject,
		?array $mappingConfig,
	): ?array {
		if ($this->isCaseTypeEdit(resource: $resource, action: $action) === false
			|| $this->publishesDraft(body: $body, existingObject: $existingObject) === false
		) {
			return null;
		}

		$keys = $this->caseTypeKeys(mappingConfig: $mappingConfig, existingObject: $existingObject);
		if ($keys === null) {
			return null;
		}

		return $this->publishRefusal(
			publishErrors: $this->ztcRules->validatePublish($keys['register'], $keys['caseTypeId']),
			body: $body
		);
	}//end guardCaseTypePublish()

	/**
	 * Whether the write updates or patches a zaaktype.
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $action The action
	 *
	 * @return bool
	 */
	private function isCaseTypeEdit(string $resource, string $action): bool {
		return $resource === 'zaaktypen' && in_array($action, ['update', 'patch'], true) === true;
	}//end isCaseTypeEdit()

	/**
	 * Whether the body sets isDraft false on a stored type that is still a draft.
	 *
	 * @param array $body The request body
	 * @param array|null $existingObject The existing object data
	 *
	 * @return bool
	 */
	private function publishesDraft(array $body, ?array $existingObject): bool {
		if (isset($body['isDraft']) === false || (bool)$body['isDraft'] !== false) {
			return false;
		}

		return is_array($existingObject) === true && (bool)($existingObject['isDraft'] ?? false) === true;
	}//end publishesDraft()

	/**
	 * The source register and the case type id a case type guard reads with,
	 * or null when either is unknown.
	 *
	 * @param array|null $mappingConfig The mapping config
	 * @param array|null $existingObject The stored case type
	 *
	 * @return array{register: string, caseTypeId: string}|null
	 */
	private function caseTypeKeys(?array $mappingConfig, ?array $existingObject): ?array {
		$register = (string)($mappingConfig['sourceRegister'] ?? '');
		$caseTypeId = (string)($existingObject['id'] ?? '');
		if (in_array('', [$register, $caseTypeId], true) === true) {
			return null;
		}

		return ['register' => $register, 'caseTypeId' => $caseTypeId];
	}//end caseTypeKeys()

	/**
	 * The 422 refusal for a publish with errors, or null when there are none.
	 *
	 * @param array $publishErrors What the publish check found missing
	 * @param array $body The request body
	 *
	 * @return array|null
	 */
	private function publishRefusal(array $publishErrors, array $body): ?array {
		if (count($publishErrors) === 0) {
			return null;
		}

		return [
			'valid' => false,
			'status' => 422,
			'detail' => implode('; ', $publishErrors),
			'code' => 'publish_validation_failed',
			'enrichedBody' => $body,
		];
	}//end publishRefusal()

	/**
	 * CT-01d — destroy guard: block deletion of a caseType that still has
	 * active (non-final) cases. Allow closed-only with the caller's explicit
	 * confirmation flag.
	 *
	 * @param ZgwWriteCheck $write The write being checked
	 *
	 * @return array|null The guard response, or null when the delete may proceed
	 */
	private function guardCaseTypeDestroy(ZgwWriteCheck $write): ?array {
		if ($this->isCaseTypeDelete(write: $write) === false) {
			return null;
		}

		$keys = $this->caseTypeKeys(mappingConfig: $write->mappingConfig, existingObject: $write->existingObject);
		if ($keys === null) {
			return null;
		}

		return $this->destroyRefusal(
			delGuard: $this->ztcRules->validateDeletion($keys['register'], $keys['caseTypeId']),
			body: $write->body
		);
	}//end guardCaseTypeDestroy()

	/**
	 * Whether the write deletes a stored zaaktype.
	 *
	 * @param ZgwWriteCheck $write The write being checked
	 *
	 * @return bool
	 */
	private function isCaseTypeDelete(ZgwWriteCheck $write): bool {
		return $write->resource === 'zaaktypen'
			&& $write->action === 'destroy'
			&& is_array($write->existingObject) === true;
	}//end isCaseTypeDelete()

	/**
	 * The refusal for one verdict of the delete check, or null when the delete may proceed.
	 *
	 * @param array $delGuard What the delete check answered
	 * @param array $body The request body
	 *
	 * @return array|null
	 */
	private function destroyRefusal(array $delGuard, array $body): ?array {
		$code = $this->destroyRefusalCode(delGuard: $delGuard, confirmed: (bool)($body['_confirm'] ?? false));
		if ($code === null) {
			return null;
		}

		return [
			'valid' => false,
			'status' => self::DESTROY_REFUSAL_STATUS[$code],
			'detail' => (string)$delGuard['message'],
			'code' => $code,
			'enrichedBody' => $body,
		];
	}//end destroyRefusal()

	/**
	 * Which refusal a delete check verdict leads to, or null for none.
	 *
	 * A check that could not read the cases says nothing about the request,
	 * so it is no conflict but an unverified answer.
	 *
	 * @param array $delGuard What the delete check answered
	 * @param bool $confirmed Whether the caller confirmed the delete
	 *
	 * @return string|null
	 */
	private function destroyRefusalCode(array $delGuard, bool $confirmed): ?string {
		if (($delGuard['unverified'] ?? false) === true) {
			return 'destroy_check_not_completed';
		}

		if ($delGuard['blocked'] === true) {
			return 'destroy_blocked_active_cases';
		}

		return $this->confirmationRefusalCode(delGuard: $delGuard, confirmed: $confirmed);
	}//end destroyRefusalCode()

	/**
	 * The refusal code of a delete that needs a confirmation the caller did not give, or null.
	 *
	 * @param array $delGuard What the delete check answered
	 * @param bool $confirmed Whether the caller confirmed the delete
	 *
	 * @return string|null
	 */
	private function confirmationRefusalCode(array $delGuard, bool $confirmed): ?string {
		if ($delGuard['requiresConfirmation'] === true && $confirmed === false) {
			return 'destroy_requires_confirmation';
		}

		return null;
	}//end confirmationRefusalCode()
}//end class
