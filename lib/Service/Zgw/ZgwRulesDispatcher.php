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
		$call = new ZgwRuleCall(resource: $resource, action: $action, body: $body, existingObject: $existingObject);

		return match ($zgwApi) {
			'zaken' => $this->dispatchZrc(call: $call),
			'catalogi' => $this->dispatchZtc(call: $call),
			'documenten' => $this->dispatchDrc(call: $call),
			'besluiten' => $this->dispatchBrc(call: $call),
			default => $this->isValid(body: $body),
		};
	}//end dispatch()

	/**
	 * Dispatch ZRC (Zaken API) rules.
	 *
	 * The zaakinformatieobjecten sub-resource is routed out first because it is
	 * the one ZRC resource owned by a different collaborator
	 * ({@see ZgwZrcZaakinformatieobjectRules}) than the zaak itself.
	 *
	 * @param ZgwRuleCall $call The request.
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function dispatchZrc(ZgwRuleCall $call): array {
		if ($call->resource === 'zaakinformatieobjecten') {
			return $this->dispatchZrcZaakinformatieobjecten(call: $call);
		}

		return match (true) {
			$call->is('zaken', 'create')
				=> $this->zrcRules->rulesZakenCreate($call->body),
			$call->is('zaken', 'update')
				=> $this->zrcRules->rulesZakenUpdate($call->body, $call->existingObject),
			$call->is('zaken', 'patch')
				=> $this->zrcRules->rulesZakenPatch($call->body, $call->existingObject),
			$call->is('statussen', 'create')
				=> $this->zrcRules->rulesStatussenCreate($call->body),
			$call->is('resultaten', 'create')
				=> $this->zrcRules->rulesResultatenCreate($call->body),
			$call->is('rollen', 'create')
				=> $this->zrcRules->rulesRollenCreate($call->body),
			$call->is('zaakeigenschappen', 'create')
				=> $this->zrcRules->rulesZaakeigenschappenCreate($call->body),
			default => $this->isValid(body: $call->body),
		};
	}//end dispatchZrc()

	/**
	 * Dispatch ZRC zaakinformatieobjecten (document-relation) rules.
	 *
	 * @param ZgwRuleCall $call The request.
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function dispatchZrcZaakinformatieobjecten(ZgwRuleCall $call): array {
		return match ($call->action) {
			'create' => $this->zioRules->rulesZaakinformatieobjectenCreate($call->body),
			'update' => $this->zioRules->rulesZaakinformatieobjectenUpdate($call->body, $call->existingObject),
			'patch' => $this->zioRules->rulesZaakinformatieobjectenPatch($call->body, $call->existingObject),
			default => $this->isValid(body: $call->body),
		};
	}//end dispatchZrcZaakinformatieobjecten()

	/**
	 * Dispatch ZTC (Catalogi API) rules.
	 *
	 * @param ZgwRuleCall $call The request.
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function dispatchZtc(ZgwRuleCall $call): array {
		return match (true) {
			$call->is('zaaktypen', 'create')
				=> $this->ztcRules->rulesZaaktypenCreate($call->body),
			$call->is('besluittypen', 'create')
				=> $this->ztcRules->rulesBesluittypenCreate($call->body),
			$call->is('zaaktype-informatieobjecttypen', 'create')
				=> $this->ztcRules->rulesZaaktypeinformatieobjecttypenCreate($call->body),
			$call->is('resultaattypen', 'create')
				=> $this->rtoRules->rulesResultaattypenCreate($call->body),
			default => $this->isValid(body: $call->body),
		};
	}//end dispatchZtc()

	/**
	 * Dispatch DRC (Documenten API) rules.
	 *
	 * @param ZgwRuleCall $call The request.
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function dispatchDrc(ZgwRuleCall $call): array {
		return match (true) {
			$call->is('enkelvoudiginformatieobjecten', 'create')
				=> $this->drcRules->rulesEnkelvoudiginformatieobjectenCreate($call->body),
			$call->is('enkelvoudiginformatieobjecten', 'update')
				=> $this->drcRules->rulesEnkelvoudiginformatieobjectenUpdate($call->body, $call->existingObject),
			$call->is('enkelvoudiginformatieobjecten', 'patch')
				=> $this->drcRules->rulesEnkelvoudiginformatieobjectenPatch($call->body, $call->existingObject),
			$call->is('enkelvoudiginformatieobjecten', 'destroy')
				=> $this->drcRules->rulesEnkelvoudiginformatieobjectenDestroy($call->body, $call->existingObject),
			$call->is('objectinformatieobjecten', 'create')
				=> $this->drcRules->rulesObjectinformatieobjectenCreate($call->body),
			default => $this->isValid(body: $call->body),
		};
	}//end dispatchDrc()

	/**
	 * Dispatch BRC (Besluiten API) rules.
	 *
	 * @param ZgwRuleCall $call The request.
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function dispatchBrc(ZgwRuleCall $call): array {
		return match (true) {
			$call->is('besluiten', 'create')
				=> $this->brcRules->rulesBesluitenCreate($call->body),
			$call->is('besluiten', 'update')
				=> $this->brcRules->rulesBesluitenUpdate($call->body, $call->existingObject),
			$call->is('besluiten', 'patch')
				=> $this->brcRules->rulesBesluitenPatch($call->body, $call->existingObject),
			$call->is('besluitinformatieobjecten', 'create')
				=> $this->brcRules->rulesBesluitinformatieobjectenCreate($call->body),
			default => $this->isValid(body: $call->body),
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
