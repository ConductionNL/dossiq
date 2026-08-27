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
	 * Constructor.
	 *
	 * @param ZgwZtcRulesService $ztcRules ZTC (Catalogi) rules, used by the concept/publish/destroy guards
	 * @param ZgwRulesDispatcher $dispatcher Routes a validated request to the rules service that owns it
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ZgwZtcRulesService $ztcRules,
		private readonly ZgwRulesDispatcher $dispatcher,
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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    — ZGW scope flag from middleware
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
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
		// Set context on the ZTC rules this service guards with directly, and
		// on every rules service the dispatcher can route to.
		$this->ztcRules->setContext($objectService, $mappingConfig);
		$this->dispatcher->setContext($objectService, $mappingConfig);

		// ---- ZTC cross-cutting concerns (concept protection) ----
		if ($zgwApi === 'catalogi') {
			$draftCheck = $this->applyCatalogiDraftRules(
				resource: $resource,
				action: $action,
				body: $body,
				existingObject: $existingObject,
				parentCaseTypeDraft: $parentCaseTypeDraft
			);
			if ($draftCheck !== null) {
				return $draftCheck;
			}

			$publishGuard = $this->guardCaseTypePublish(
				resource: $resource,
				action: $action,
				body: $body,
				existingObject: $existingObject,
				mappingConfig: $mappingConfig
			);
			if ($publishGuard !== null) {
				return $publishGuard;
			}

			$destroyGuard = $this->guardCaseTypeDestroy(
				resource: $resource,
				action: $action,
				body: $body,
				existingObject: $existingObject,
				mappingConfig: $mappingConfig
			);
			if ($destroyGuard !== null) {
				return $destroyGuard;
			}
		}//end if

		// ---- ZRC cross-cutting concern: closed zaak protection (zrc-007) ----
		if ($caseClosed === true && $hasGeforceerd === false) {
			return [
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
		}

		// ---- Delegate to per-register rule services ----
		return $this->dispatcher->dispatch(
			zgwApi: $zgwApi,
			resource: $resource,
			action: $action,
			body: $body,
			existingObject: $existingObject
		);
	}//end validate()

	/**
	 * Apply the ZTC concept defaults/preservation and the ztc-009/ztc-010
	 * published-type protection.
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $action The action
	 * @param array $body The request body, enriched in place
	 * @param array|null $existingObject The existing object data
	 * @param bool|null $parentCaseTypeDraft Whether the parent zaaktype isDraft
	 *
	 * @return array|null The guard response, or null when the request may proceed
	 */
	private function applyCatalogiDraftRules(
		string $resource,
		string $action,
		array &$body,
		?array $existingObject,
		?bool $parentCaseTypeDraft,
	): ?array {
		// Default concept=true for new concept resources.
		if ($action === 'create') {
			$body = $this->ztcRules->defaultConcept($body, $resource);
		}

		// Preserve concept on update/patch (only changeable via /publish).
		if (in_array($action, ['update', 'patch'], true) === true) {
			$body = $this->ztcRules->preserveConcept($body, $resource, $existingObject);
		}

		// Ztc-009/ztc-010: Protect published types from modification.
		return $this->ztcRules->checkConceptProtection(
			$resource,
			$action,
			$body,
			$existingObject,
			$parentCaseTypeDraft
		);
	}//end applyCatalogiConceptRules()

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
		if ($resource !== 'zaaktypen'
			|| in_array($action, ['update', 'patch'], true) === false
			|| isset($body['isDraft']) === false
			|| (bool)$body['isDraft'] !== false
			|| is_array($existingObject) === false
			|| (bool)($existingObject['isDraft'] ?? false) !== true
		) {
			return null;
		}

		$register = (string)($mappingConfig['sourceRegister'] ?? '');
		$caseTypeId = (string)($existingObject['id'] ?? '');
		if (in_array('', [$register, $caseTypeId], true) === true) {
			return null;
		}

		$publishErrors = $this->ztcRules->validatePublish($register, $caseTypeId);
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
	}//end guardZaaktypePublish()

	/**
	 * CT-01d — destroy guard: block deletion of a caseType that still has
	 * active (non-final) cases. Allow closed-only with the caller's explicit
	 * confirmation flag.
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $action The action
	 * @param array $body The request body
	 * @param array|null $existingObject The existing object data
	 * @param array|null $mappingConfig The mapping config
	 *
	 * @return array|null The guard response, or null when the delete may proceed
	 */
	private function guardCaseTypeDestroy(
		string $resource,
		string $action,
		array $body,
		?array $existingObject,
		?array $mappingConfig,
	): ?array {
		if ($resource !== 'zaaktypen'
			|| $action !== 'destroy'
			|| is_array($existingObject) === false
		) {
			return null;
		}

		$register = (string)($mappingConfig['sourceRegister'] ?? '');
		$caseTypeId = (string)($existingObject['id'] ?? '');
		$confirmed = (bool)($body['_confirm'] ?? false);
		if (in_array('', [$register, $caseTypeId], true) === true) {
			return null;
		}

		$delGuard = $this->ztcRules->validateDeletion($register, $caseTypeId);
		if ($delGuard['blocked'] === true) {
			return [
				'valid' => false,
				'status' => 409,
				'detail' => (string)$delGuard['message'],
				'code' => 'destroy_blocked_active_cases',
				'enrichedBody' => $body,
			];
		}

		if ($delGuard['requiresConfirmation'] === true && $confirmed === false) {
			return [
				'valid' => false,
				'status' => 409,
				'detail' => (string)$delGuard['message'],
				'code' => 'destroy_requires_confirmation',
				'enrichedBody' => $body,
			];
		}

		return null;
	}//end guardZaaktypeDestroy()
}//end class
