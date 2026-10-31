<?php

/**
 * Dossiq repair step: give existing cases the identifier their case type's
 * version chain shares.
 *
 * `case.caseTypeIdentifier` is materialised by OpenRegister from the case
 * type's `identifier` when a case is saved, so a case saved before the field
 * existed has none, and a list or quick filter that means every version of a
 * case type ("every Woo request") would miss it. Re-saves the case in system
 * context so OpenRegister materialises the field, only where the case type
 * carries an identifier and the case differs. Idempotent, counted, non-fatal.
 * See BackfillReferencedCaseValue.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-five-steps/specs/case-type-version-chain/spec.md#requirement-a-case-carries-the-identifier-its-case-type-chain-shares
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

/**
 * Backfill `caseTypeIdentifier` on the cases that exist.
 *
 * @spec openspec/changes/woo-five-steps/specs/case-type-version-chain/spec.md#requirement-a-case-carries-the-identifier-its-case-type-chain-shares
 */
class BackfillCaseTypeIdentifier extends BackfillReferencedCaseValue {

	/**
	 * The step's name in the upgrade output.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/woo-five-steps/specs/case-type-version-chain/spec.md#requirement-a-case-carries-the-identifier-its-case-type-chain-shares
	 */
	public function getName(): string {
		return 'Give existing cases the identifier of their case type (caseTypeIdentifier)';
	}//end getName()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The field.
	 */
	protected function field(): string {
		return 'caseTypeIdentifier';
	}//end field()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The reference field.
	 */
	protected function referenceField(): string {
		return 'caseType';
	}//end referenceField()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The config key.
	 */
	protected function targetSchemaKey(): string {
		return 'case_type_schema';
	}//end targetSchemaKey()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The property.
	 */
	protected function targetProperty(): string {
		return 'identifier';
	}//end targetProperty()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The words.
	 */
	protected function missingWhat(): string {
		return 'an identifier';
	}//end missingWhat()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The words.
	 */
	protected function schemasWhat(): string {
		return 'the case or case type schema';
	}//end schemasWhat()
}//end class
