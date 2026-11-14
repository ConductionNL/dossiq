<?php

/**
 * ZTC rules for concept and published types
 *
 * A new zaaktype, besluittype or informatieobjecttype starts as a concept, keeps
 * its stored concept on update, and once published refuses changes to itself
 * (ztc-009) and to the sub-resources of a zaaktype (ztc-010).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

/**
 * Concept defaults and the protection of published types.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZtcConceptRules {
	/**
	 * Constructor.
	 *
	 * @param ZgwRuleResults $results Builds the answers of a rule.
	 */
	public function __construct(
		private readonly ZgwRuleResults $results,
	) {
	}//end __construct()

	/**
	 * ZTC resources that are subject to concept/published protection.
	 *
	 * @var array<string>
	 */
	public const CONCEPT_RESOURCES = [
		'zaaktypen',
		'besluittypen',
		'informatieobjecttypen',
	];

	/**
	 * ZTC sub-resources tied to a parent zaaktype (ztc-010).
	 *
	 * @var array<string>
	 */
	public const ZAAKTYPE_SUB_RESOURCES = [
		'statustypen',
		'resultaattypen',
		'roltypen',
		'eigenschappen',
		'zaaktype-informatieobjecttypen',
	];

	/**
	 * Check concept/published protection for ZTC resources (ztc-009, ztc-010).
	 *
	 * Published types (concept=false) cannot be modified or deleted.
	 * Sub-resources of published zaaktypen cannot be modified or deleted
	 * (except CREATE for most sub-resources per VNG test exceptions).
	 *
	 * Implements:
	 * - ztc-009: Protect published (concept=false) types from modification/deletion.
	 *   Exception: PATCH with only eindeGeldigheid is allowed on published types.
	 *
	 * - ztc-010: Protect sub-resources of published zaaktypen.
	 *   Per VNG tests ztc-010i/k/l/m, CREATE is allowed for eigenschappen, roltypen,
	 *   statustypen, and ZIOTs on published zaaktypen. Only resultaattypen creation
	 *   is blocked (ztc-010j). Update/patch/destroy remain blocked for ALL.
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $action The action (create/update/patch/destroy)
	 * @param array $body The request body
	 * @param array|null $existingObject The existing object data
	 * @param bool|null $parentCaseTypeDraft Whether the parent zaaktype isDraft
	 *
	 * @return array|null Validation error result, or null if check passes
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function checkConceptProtection(
		string $resource,
		string $action,
		array $body,
		?array $existingObject,
		?bool $parentCaseTypeDraft,
	): ?array {
		$change = new ZtcChange(resource: $resource, action: $action, body: $body);

		// Ztc-009: Direct concept resources (zaaktypen, besluittypen, informatieobjecttypen).
		if (in_array($resource, self::CONCEPT_RESOURCES, true) === true) {
			return $this->checkDirectDraftProtection(change: $change, existingObject: $existingObject);
		}

		// Ztc-010: Sub-resources of zaaktypen.
		if (in_array($resource, self::ZAAKTYPE_SUB_RESOURCES, true) === true
			&& $parentCaseTypeDraft === false
		) {
			return $this->publishedParentRefusal(change: $change);
		}

		return null;
	}//end checkConceptProtection()

	/**
	 * The refusal for a change to a sub-resource of a published zaaktype (ztc-010).
	 *
	 * Creating a sub-resource is allowed, except a resultaattype; updating,
	 * patching and destroying any of them is not.
	 *
	 * @param ZtcChange $change The change.
	 *
	 * @return array|null Validation error result, or null if the change may pass
	 */
	private function publishedParentRefusal(ZtcChange $change): ?array {
		// Allow creation of all sub-resources except resultaattypen.
		if ($change->action === 'create' && $change->resource !== 'resultaattypen') {
			return null;
		}

		// Block resultaattypen creation + update/patch/destroy for all sub-resources.
		if (in_array($change->action, ['create', 'update', 'patch', 'destroy'], true) === false) {
			return null;
		}

		$detail = 'Het is niet toegestaan om typen van een gepubliceerd zaaktype aan te passen.';
		return $this->results->error(
			status: 400,
			detail: $detail,
			invalidParams: [
				$this->results->fieldError(fieldName: 'nonFieldErrors', code: 'non-concept-zaaktype', reason: $detail),
			]
		);
	}//end publishedParentRefusal()

	/**
	 * Default concept=true for new ZTC concept resources.
	 *
	 * When creating a new zaaktype, besluittype, or informatieobjecttype,
	 * concept defaults to true if not explicitly set.
	 *
	 * @param array $body The request body
	 * @param string $resource The resource name
	 *
	 * @return array The body with concept defaulted
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function defaultConcept(array $body, string $resource): array {
		if (in_array($resource, self::CONCEPT_RESOURCES, true) === true
			&& array_key_exists('concept', $body) === false
		) {
			$body['concept'] = true;
		}

		return $body;
	}//end defaultConcept()

	/**
	 * Preserve existing concept value on update/patch.
	 *
	 * Concept can only be changed via the /publish endpoint, not via PUT/PATCH.
	 *
	 * @param array $body The request body
	 * @param string $resource The resource name
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The body with concept preserved
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function preserveConcept(array $body, string $resource, ?array $existingObject): array {
		if ($existingObject === null
			|| in_array($resource, self::CONCEPT_RESOURCES, true) === false
		) {
			return $body;
		}

		$existingDraft = $existingObject['isDraft'] ?? ($existingObject['concept'] ?? true);
		if ($existingDraft === 'true' || $existingDraft === '1' || $existingDraft === 1) {
			$existingDraft = true;
		} elseif ($existingDraft === 'false' || $existingDraft === '0' || $existingDraft === 0) {
			$existingDraft = false;
		}

		$body['concept'] = $existingDraft;

		return $body;
	}//end preserveConcept()

	/**
	 * Check if a direct concept resource is published (ztc-009).
	 *
	 * Published types (concept=false) cannot be modified or deleted,
	 * except PATCH with only eindeGeldigheid.
	 *
	 * @param ZtcChange  $change         The change.
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array|null Validation error, or null if OK
	 */
	private function checkDirectDraftProtection(ZtcChange $change, ?array $existingObject): ?array {
		if ($existingObject === null || self::isPublished(existingObject: $existingObject) === false) {
			return null;
		}

		// Ztc-009c/g/k: PATCH with only geldigheid fields is allowed on published types.
		if ($change->action === 'patch' && self::onlyEndsValidity(body: $change->body) === true) {
			return null;
		}

		$resourceLabel = rtrim($change->resource, 'n');
		$detail = "Het is niet toegestaan om een {$resourceLabel} met concept=false " . $this->actionLabel(action: $change->action) . '.';
		return $this->results->error(
			status: 400,
			detail: $detail,
			invalidParams: [
				$this->results->fieldError(fieldName: 'nonFieldErrors', code: 'non-concept-object', reason: $detail),
			]
		);
	}//end checkDirectDraftProtection()

	/**
	 * Whether a stored type is published: its draft flag, else its concept flag, reads false.
	 *
	 * @param array $existingObject The existing object data
	 *
	 * @return bool True for a published type.
	 */
	private static function isPublished(array $existingObject): bool {
		$isDraft = $existingObject['isDraft'] ?? ($existingObject['concept'] ?? true);

		return in_array($isDraft, ['false', false, '0', 0], true);
	}//end isPublished()

	/**
	 * Whether a patch only sets the validity, ending it.
	 *
	 * @param array $body The request body
	 *
	 * @return bool True when it carries endValidity and no other content.
	 */
	private static function onlyEndsValidity(array $body): bool {
		$metadataKeys = ['_route', 'zgwApi', 'resource', 'uuid', 'concept'];
		$allowedKeys = ['endValidity', 'startValidity', 'beginObject'];
		$contentKeys = array_values(array_diff(array_keys($body), $metadataKeys, $allowedKeys));

		return (count($contentKeys) === 0 && array_key_exists('endValidity', $body) === true);
	}//end onlyEndsValidity()

	/**
	 * Get a Dutch action label for error messages.
	 *
	 * @param string $action The action
	 *
	 * @return string The Dutch label
	 */
	private function actionLabel(string $action): string {
		return match ($action) {
			'update' => 'bij te werken',
			'patch' => 'deels bij te werken',
			'destroy' => 'te verwijderen',
			default => 'aan te passen',
		};
	}//end actionLabel()
}//end class
