<?php

/**
 * Pointing a derived case type at its OWN copies of the rows it references.
 *
 * A case type references two of its own children by id: `initialStatus` names a
 * statusType and `workflowDefinition` names a workflowTemplate. Both are copied
 * when a case type is duplicated or versioned, and both still hold the SOURCE's
 * id the moment the new row is written, because the children get their ids only
 * after the parent exists. Reconciling them is one subject, and it lives beside
 * {@see DerivedCaseTypePayload} for the reason that class exists: what a derived
 * case type looks like is a separate question from the mechanics of copying it,
 * and keeping them apart is what stopped the duplicate and version paths
 * drifting.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Service\CaseTypeStore;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repoints a freshly derived case type at its own children.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 */
class DerivedCaseTypeReferences {

	/**
	 * Constructor.
	 *
	 * @param CaseTypeStore   $store  The app's row and reference normaliser.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly CaseTypeStore $store,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Point the new case type's own references at its OWN copies.
	 *
	 * Two references, one save. Without this the copy files new cases into the
	 * SOURCE's status row: the children are copied but `initialStatus` still
	 * holds the old id, and the two are never reconciled. Publish validation
	 * catches it (the initial status is not one of the type's own statuses) so
	 * it never reached a running case, but it made every duplicate and every
	 * new version ask the author to re-pick a status they had already picked.
	 *
	 * 🔴 AN UNMAPPABLE WORKFLOW PIN IS CLEARED, NOT LEFT POINTING BACKWARDS.
	 * `caseType.workflowDefinition` is filtered on `caseType: @objectId`, so a
	 * pin at another version's template is a route the type's own Workflow tab
	 * cannot show: the field reads filled in and the picker reads empty, and
	 * nothing says which is right. That only arises when the template copy did
	 * not run or did not carry, a duplicate or a version whose template schema
	 * is unconfigured, and in both of those the honest answer is no default
	 * route.
	 *
	 * @param object                $objectService The OpenRegister ObjectService.
	 * @param string                $register      The register slug.
	 * @param string                $schema        The case type schema id.
	 * @param array<string, mixed>  $caseType      The freshly created case type.
	 * @param array<string, string> $statusMap     Old status id to new status id.
	 * @param array<string, string> $templateMap   Old template id to new template id.
	 *
	 * @return array<string, mixed> The case type, repointed when it needed it.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function repoint(
		object $objectService,
		string $register,
		string $schema,
		array $caseType,
		array $statusMap,
		array $templateMap,
	): array {
		$changed = false;

		$initial = $this->store->referenceId(value: ($caseType['initialStatus'] ?? ''));
		if ($initial !== '' && isset($statusMap[$initial]) === true) {
			$caseType['initialStatus'] = $statusMap[$initial];
			$changed = true;
		}

		$pin = $this->store->referenceId(value: ($caseType['workflowDefinition'] ?? ''));
		if ($pin !== '') {
			$caseType['workflowDefinition'] = ($templateMap[$pin] ?? null);
			$changed = true;
		}

		if ($changed === false) {
			return $caseType;
		}

		try {
			$saved = $objectService->saveObject(
				object: $caseType,
				register: $register,
				schema: $schema,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'CaseTypeCopyService: could not repoint the copy on its own children',
				['caseType' => ($caseType['id'] ?? ''), 'exception' => $e->getMessage()]
			);
			return $caseType;
		}

		return $this->store->asRow(value: $saved);
	}//end repoint()
}//end class
