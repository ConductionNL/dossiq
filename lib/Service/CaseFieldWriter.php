<?php

/**
 * Write a flow handler's OWN fields to a stored case, without clobbering anyone else's.
 *
 * WHY THIS EXISTS. A flow handler receives the case as a SNAPSHOT: the flow
 * item's json, taken when the run's token reached the step. Between that
 * snapshot and the handler's save, other writers act on the STORED case — the
 * step before it in the same run, a person in the UI, another run. Every
 * handler used to save the whole snapshot back (`saveObject()` is PUT-semantic:
 * a property absent from the payload is written as null), so the save erased
 * whatever landed after the snapshot was taken. Measured live on the closure
 * rig (case a53cfc92/dc16d6dd, audits 512→515 and 725→728, same second):
 * MergeTemplateHandler wrote `besluitDocument` to storage, and SetStatusHandler
 * — one step later, holding the older snapshot — full-saved it away again.
 *
 * SO NOTHING HERE SAVES A SNAPSHOT. The handler hands over ONLY the fields it
 * owns, and they are applied to the STORED case:
 *
 *  - through `ObjectService::patchObject()` when the installed OpenRegister has
 *    it — the fleet's PATCH-semantic seam, which reads, merges and saves under
 *    one roof (schema validation, audit trail and events all still apply);
 *  - otherwise by re-reading the stored case via `find()` and saving the fresh
 *    read with the handler's fields applied. A read-then-save window remains on
 *    this path, but the DETERMINISTIC clobber — old snapshot over new data — is
 *    gone, which is the defect class this closes.
 *
 * The non-flow path (StatusTransitionService) already re-reads before writing;
 * this gives the flow-handler path the same discipline.
 *
 * Nothing in it is specific to a case: it addresses the stored object by id
 * and applies the fields it is given. InformatieobjectStatusLifecycle writes
 * a document's status through it for the same reason, because a status-only
 * `saveObject()` dropped every other property of the document.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * Applies a handler's own field changes to the stored case, never the snapshot.
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */
class CaseFieldWriter {

	use SearchesObjects;

	/**
	 * Apply these changes to the stored case.
	 *
	 * The read that decides what is saved and the save itself run under one
	 * acting identity: on the flow path the engine's RegistryStepDispatcher
	 * executes the calling handler inside `ObjectService::runAs()`
	 * (openregister#3332), and on the interactive path the ambient session
	 * user answers the permission checks.
	 *
	 * @param object               $objectService The resolved OpenRegister ObjectService.
	 * @param string               $register      The register holding the case.
	 * @param string               $schema        The case schema.
	 * @param array<string, mixed> $case          The handler's case snapshot; only its identity is used.
	 * @param array<string, mixed> $changes       The fields this handler owns, and nothing else.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the snapshot carries no case id, the stored
	 *                          case cannot be re-read, or the object service
	 *                          offers no seam this writer can use.
	 *
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	public function write(object $objectService, string $register, string $schema, array $case, array $changes): void {

		if ($changes === []) {
			return;
		}

		$caseId = $this->caseId(case: $case);
		if ($caseId === '') {
			// A snapshot with no identity cannot address the stored case, and
			// saving the snapshot itself would CREATE a duplicate — the quiet
			// failure this class of fix exists to remove. Refuse loudly.
			throw new RuntimeException('case_snapshot_has_no_id');
		}

		// The PATCH seam every partial write in lib/ shares:
		// `patchObject()` where OpenRegister has it, otherwise a fresh read
		// with only these fields applied. See SearchesObjects.
		$this->patchObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $caseId,
			changes: $changes,
		);
	}//end write()


	/**
	 * The stored identity this snapshot points at.
	 *
	 * @param array<string, mixed> $case The case snapshot.
	 *
	 * @return string The case id, or '' when the snapshot names none.
	 */
	private function caseId(array $case): string {
		$id = (string) ($case['id'] ?? ($case['uuid'] ?? ''));
		if ($id !== '') {
			return $id;
		}

		$self = ($case['@self'] ?? null);
		if (is_array($self) === true) {
			return (string) ($self['id'] ?? ($self['uuid'] ?? ''));
		}

		return '';
	}//end caseId()
}//end class
