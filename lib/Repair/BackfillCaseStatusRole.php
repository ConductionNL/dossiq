<?php

/**
 * Dossiq repair step: give existing cases the role of the status they sit in.
 *
 * `case.statusRole` is computed by OpenRegister from the linked status type's
 * `role`, and OpenRegister computes it when a case is SAVED. A case that was
 * last saved before the field existed has none, so the simple case page shows
 * it no what-now card and no stage button until somebody happens to edit it.
 *
 * OpenRegister's own `occ openregister:rematerialise-calculations` is the tool
 * for this, and on 5 October 2026 it failed for every case: it writes as
 * "Anonymous", and the case schema refuses an anonymous update. This step does
 * the same job in system context, the way dossiq's other backfills write.
 *
 * What it writes, and what it leaves alone:
 * - only a case whose status type DECLARES a role, and whose `statusRole`
 *   differs from it. A case type nobody annotated is skipped, and so is a case
 *   that is already right, which is what makes a second run a no-op;
 * - no value of its own: `statusRole` is readOnly, so the case is re-saved
 *   unchanged and OpenRegister computes the field on that save. It
 *   changes no status, so it is no transition: `StatusTransitionService` is not
 *   involved, and the notifications the case schema declares (assigned on
 *   create, declared major on an `isMajor` change, moved on a transition) have
 *   nothing to fire on. The applicant is told nothing.
 *
 * It never throws. One row that refuses is logged and counted, and the
 * upgrade goes on.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/specs/case-management/spec.md#REQ-CM-77
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

/**
 * Backfill `statusRole` on the cases that exist.
 *
 * @spec openspec/specs/case-management/spec.md#REQ-CM-77
 */
class BackfillCaseStatusRole extends BackfillReferencedCaseValue {

	/**
	 * The step's name in the upgrade output.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/case-management/spec.md#REQ-CM-77
	 */
	public function getName(): string {
		return 'Give existing cases the role of the status they sit in (statusRole)';
	}//end getName()

	/**
	 * The status role this case should carry, or '' when it needs no write.
	 *
	 * @param array<string, mixed>  $case  The stored case.
	 * @param array<string, string> $roles Role by status type uuid.
	 *
	 * @return string The role to write, or ''.
	 *
	 * @spec openspec/specs/case-management/spec.md#REQ-CM-77
	 */
	public function roleToWrite(array $case, array $roles): string {
		return $this->valueToWrite(case: $case, values: $roles);
	}//end roleToWrite()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The field.
	 */
	protected function field(): string {
		return 'statusRole';
	}//end field()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The reference field.
	 */
	protected function referenceField(): string {
		return 'status';
	}//end referenceField()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The config key.
	 */
	protected function targetSchemaKey(): string {
		return 'status_type_schema';
	}//end targetSchemaKey()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The property.
	 */
	protected function targetProperty(): string {
		return 'role';
	}//end targetProperty()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The words.
	 */
	protected function missingWhat(): string {
		return 'a role';
	}//end missingWhat()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The words.
	 */
	protected function schemasWhat(): string {
		return 'the case or status type schema';
	}//end schemasWhat()
}//end class
