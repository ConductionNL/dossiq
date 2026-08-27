<?php

/**
 * Dossiq ZGW rules dispatcher.
 *
 * The routing table that turns a (zgwApi, resource, action) triple into the
 * one rules method that owns it. Split out of ZgwBusinessRulesService so that
 * service keeps only the cross-cutting concerns it applies *before* routing —
 * catalogi concept protection and closed-zaak protection — while the knowledge
 * of which register owns which resource, and which of its methods handles
 * create vs update vs patch, lives here.
 *
 * An unrouted (resource, action) pair is a pass-through, not an error: the ZGW
 * APIs expose many resources that carry no dossiq-side business rule at all.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Service\ZgwBrcRulesService;
use OCA\Dossiq\Service\ZgwDrcRulesService;
use OCA\Dossiq\Service\ZgwZrcRulesService;
use OCA\Dossiq\Service\ZgwZtcRulesService;

/**
 * Routes a validated ZGW request to the rules service that owns its resource.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZgwRulesDispatcher {
	/**
	 * Constructor.
	 *
	 * @param ZgwZrcRulesService $zrcRules ZRC (Zaken) rules
	 * @param ZgwZtcRulesService $ztcRules ZTC (Catalogi) rules
	 * @param ZgwDrcRulesService $drcRules DRC (Documenten) rules
	 * @param ZgwBrcRulesService $brcRules BRC (Besluiten) rules
	 * @param ZgwZrcZaakinformatieobjectRules $zioRules ZRC zaakinformatieobjecten rules
	 * @param ZgwZtcResultaattypeRules $rtoRules ZTC resultaattypen rules
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ZgwZrcRulesService $zrcRules,
		private readonly ZgwZtcRulesService $ztcRules,
		private readonly ZgwDrcRulesService $drcRules,
		private readonly ZgwBrcRulesService $brcRules,
		private readonly ZgwZrcZaakinformatieobjectRules $zioRules,
		private readonly ZgwZtcResultaattypeRules $rtoRules,
	) {
	}//end __construct()

	/**
	 * Set the per-request context on every rules service this dispatcher routes to.
	 *
	 * Every service reachable from dispatch() is listed here. A service that is
	 * routed but not contexted silently loses every cross-resource lookup it
	 * makes, which reads exactly like "the rule passed".
	 *
	 * @param object|null $objectService The OpenRegister ObjectService
	 * @param array|null $mappingConfig The mapping config
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function setContext(?object $objectService, ?array $mappingConfig): void {
		$this->zrcRules->setContext($objectService, $mappingConfig);
		$this->ztcRules->setContext($objectService, $mappingConfig);
		$this->drcRules->setContext($objectService, $mappingConfig);
		$this->brcRules->setContext($objectService, $mappingConfig);
		$this->zioRules->setContext($objectService, $mappingConfig);
		$this->rtoRules->setContext($objectService, $mappingConfig);
	}//end setContext()

	/**
	 * Dispatch to the appropriate per-register rule service.
	 *
	 * @param string $zgwApi The ZGW API group
	 * @param string $resource The ZGW resource name
	 * @param string $action The action
	 * @param array $body The request body
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function dispatch(
		string $zgwApi,
		string $resource,
		string $action,
		array $body,
		?array $existingObject,
	): array {
		$valid = [
			'valid' => true,
			'status' => 200,
			'detail' => '',
			'enrichedBody' => $body,
		];

		// --- Zaken API (ZRC) ---
		if ($zgwApi === 'zaken') {
			return $this->dispatchZrc(
				resource: $resource,
				action: $action,
				body: $body,
				existingObject: $existingObject
			);
		}

		// --- Catalogi API (ZTC) ---
		if ($zgwApi === 'catalogi') {
			return $this->dispatchZtc(
				resource: $resource,
				action: $action,
				body: $body,
				existingObject: $existingObject
			);
		}

		// --- Documenten API (DRC) ---
		if ($zgwApi === 'documenten') {
			return $this->dispatchDrc(
				resource: $resource,
				action: $action,
				body: $body,
				existingObject: $existingObject
			);
		}

		// --- Besluiten API (BRC) ---
		if ($zgwApi === 'besluiten') {
			return $this->dispatchBrc(
				resource: $resource,
				action: $action,
				body: $body,
				existingObject: $existingObject
			);
		}

		return $valid;
	}//end dispatch()

	/**
	 * Dispatch ZRC (Zaken API) rules.
	 *
	 * The zaakinformatieobjecten sub-resource is routed out first because it is
	 * the one ZRC resource owned by a different collaborator
	 * ({@see ZgwZrcZaakinformatieobjectRules}) than the zaak itself.
	 *
	 * @param string $resource The resource name
	 * @param string $action The action
	 * @param array $body The request body
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function dispatchZrc(string $resource, string $action, array $body, ?array $existingObject): array {
		if ($resource === 'zaakinformatieobjecten') {
			return $this->dispatchZrcZaakinformatieobjecten(
				action: $action,
				body: $body,
				existingObject: $existingObject
			);
		}

		return match (true) {
			$resource === 'zaken' && $action === 'create'
				=> $this->zrcRules->rulesZakenCreate($body),
			$resource === 'zaken' && $action === 'update'
				=> $this->zrcRules->rulesZakenUpdate($body, $existingObject),
			$resource === 'zaken' && $action === 'patch'
				=> $this->zrcRules->rulesZakenPatch($body, $existingObject),
			$resource === 'statussen' && $action === 'create'
				=> $this->zrcRules->rulesStatussenCreate($body),
			$resource === 'resultaten' && $action === 'create'
				=> $this->zrcRules->rulesResultatenCreate($body),
			$resource === 'rollen' && $action === 'create'
				=> $this->zrcRules->rulesRollenCreate($body),
			$resource === 'zaakeigenschappen' && $action === 'create'
				=> $this->zrcRules->rulesZaakeigenschappenCreate($body),
			default => $this->isValid(body: $body),
		};//end match
	}//end dispatchZrc()

	/**
	 * Dispatch ZRC zaakinformatieobjecten (document-relation) rules.
	 *
	 * @param string $action The action
	 * @param array $body The request body
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function dispatchZrcZaakinformatieobjecten(string $action, array $body, ?array $existingObject): array {
		return match ($action) {
			'create' => $this->zioRules->rulesZaakinformatieobjectenCreate($body),
			'update' => $this->zioRules->rulesZaakinformatieobjectenUpdate($body, $existingObject),
			'patch' => $this->zioRules->rulesZaakinformatieobjectenPatch($body, $existingObject),
			default => $this->isValid(body: $body),
		};
	}//end dispatchZrcZaakinformatieobjecten()

	/**
	 * Dispatch ZTC (Catalogi API) rules.
	 *
	 * @param string $resource The resource name
	 * @param string $action The action
	 * @param array $body The request body
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The validation result
	 *
	 * @psalm-suppress UnusedParam — $existingObject reserved for update validation rules
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $existingObject reserved for update rules
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function dispatchZtc(string $resource, string $action, array $body, ?array $existingObject): array {
		return match (true) {
			$resource === 'zaaktypen' && $action === 'create'
				=> $this->ztcRules->rulesZaaktypenCreate($body),
			$resource === 'besluittypen' && $action === 'create'
				=> $this->ztcRules->rulesBesluittypenCreate($body),
			$resource === 'zaaktype-informatieobjecttypen' && $action === 'create'
				=> $this->ztcRules->rulesZaaktypeinformatieobjecttypenCreate($body),
			$resource === 'resultaattypen' && $action === 'create'
				=> $this->rtoRules->rulesResultaattypenCreate($body),
			default => $this->isValid(body: $body),
		};
	}//end dispatchZtc()

	/**
	 * Dispatch DRC (Documenten API) rules.
	 *
	 * @param string $resource The resource name
	 * @param string $action The action
	 * @param array $body The request body
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function dispatchDrc(string $resource, string $action, array $body, ?array $existingObject): array {
		return match (true) {
			$resource === 'enkelvoudiginformatieobjecten' && $action === 'create'
				=> $this->drcRules->rulesEnkelvoudiginformatieobjectenCreate($body),
			$resource === 'enkelvoudiginformatieobjecten' && $action === 'update'
				=> $this->drcRules->rulesEnkelvoudiginformatieobjectenUpdate($body, $existingObject),
			$resource === 'enkelvoudiginformatieobjecten' && $action === 'patch'
				=> $this->drcRules->rulesEnkelvoudiginformatieobjectenPatch($body, $existingObject),
			$resource === 'enkelvoudiginformatieobjecten' && $action === 'destroy'
				=> $this->drcRules->rulesEnkelvoudiginformatieobjectenDestroy($body, $existingObject),
			$resource === 'objectinformatieobjecten' && $action === 'create'
				=> $this->drcRules->rulesObjectinformatieobjectenCreate($body),
			default => $this->isValid(body: $body),
		};
	}//end dispatchDrc()

	/**
	 * Dispatch BRC (Besluiten API) rules.
	 *
	 * @param string $resource The resource name
	 * @param string $action The action
	 * @param array $body The request body
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function dispatchBrc(string $resource, string $action, array $body, ?array $existingObject): array {
		return match (true) {
			$resource === 'besluiten' && $action === 'create'
				=> $this->brcRules->rulesBesluitenCreate($body),
			$resource === 'besluiten' && $action === 'update'
				=> $this->brcRules->rulesBesluitenUpdate($body, $existingObject),
			$resource === 'besluiten' && $action === 'patch'
				=> $this->brcRules->rulesBesluitenPatch($body, $existingObject),
			$resource === 'besluitinformatieobjecten' && $action === 'create'
				=> $this->brcRules->rulesBesluitinformatieobjectenCreate($body),
			default => $this->isValid(body: $body),
		};
	}//end dispatchBrc()

	/**
	 * Build a successful validation result (pass-through).
	 *
	 * @param array $body The (possibly enriched) request body
	 *
	 * @return array{valid: bool, status: int, detail: string, enrichedBody: array} The pass-through result
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function isValid(array $body): array {
		return [
			'valid' => true,
			'status' => 200,
			'detail' => '',
			'enrichedBody' => $body,
		];
	}//end isValid()
}//end class
