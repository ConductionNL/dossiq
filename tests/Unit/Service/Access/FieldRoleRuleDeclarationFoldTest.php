<?php

/**
 * FieldRoleRuleDeclaration::foldInto and the declarations it skips.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
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
 * @spec openspec/specs/security-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\FieldRoleRuleDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * Role entries are appended to a status block unless the status already
 * names the field under the same kind; malformed declarations are skipped.
 *
 * @covers \OCA\Dossiq\Service\Access\FieldRoleRuleDeclaration
 */
class FieldRoleRuleDeclarationFoldTest extends TestCase {

	/**
	 * A role entry is appended beside the status's own entry, each keeping
	 * its own groups, and a kind the status lacks is added.
	 *
	 * @return void
	 */
	public function testRoleEntriesAreAppendedNotMerged(): void {
		$fields = (new FieldRoleRuleDeclaration())->foldInto(
			fields: ['hidden' => [['fields' => ['amount'], 'groups' => ['intake'], 'condition' => 'x > 1']]],
			roles: [
				'hidden'   => [['fields' => ['qualityScore'], 'groups' => ['behandelaars']]],
				'readOnly' => [['fields' => ['confidentiality'], 'groups' => ['behandelaars']]],
			]
		);

		$this->assertSame(
			[
				'hidden'   => [
					['fields' => ['amount'], 'groups' => ['intake'], 'condition' => 'x > 1'],
					['fields' => ['qualityScore'], 'groups' => ['behandelaars']],
				],
				'readOnly' => [['fields' => ['confidentiality'], 'groups' => ['behandelaars']]],
			],
			$fields
		);
	}//end testRoleEntriesAreAppendedNotMerged()

	/**
	 * A field the status already names under the same kind keeps the status
	 * entry alone; under another kind the role entry is still added.
	 *
	 * @return void
	 */
	public function testAFieldTheStatusAlreadyNamesIsLeftToTheStatus(): void {
		$status = [
			'hidden'   => [['fields' => ['amount', 'iban'], 'groups' => ['intake'], 'condition' => 'x > 1', 'message' => 'Not yet.']],
			'readOnly' => ['not an entry'],
		];

		$fields = (new FieldRoleRuleDeclaration())->foldInto(
			fields: $status,
			roles: [
				'hidden'   => [
					['fields' => ['iban'], 'groups' => ['behandelaars']],
					['fields' => ['qualityScore', 'amount'], 'groups' => ['behandelaars']],
				],
				'readOnly' => [['fields' => ['iban'], 'groups' => ['behandelaars']]],
			]
		);

		$this->assertSame($status['hidden'], $fields['hidden']);
		$this->assertSame(['not an entry', ['fields' => ['iban'], 'groups' => ['behandelaars']]], $fields['readOnly']);
	}//end testAFieldTheStatusAlreadyNamesIsLeftToTheStatus()

	/**
	 * No role rules leave the status block as it is.
	 *
	 * @return void
	 */
	public function testNoRolesLeaveTheBlockAlone(): void {
		$status = ['hidden' => [['fields' => ['amount'], 'groups' => ['intake']]]];

		$this->assertSame($status, (new FieldRoleRuleDeclaration())->foldInto(fields: $status, roles: []));
		$this->assertSame([], (new FieldRoleRuleDeclaration())->foldInto(fields: [], roles: []));
	}//end testNoRolesLeaveTheBlockAlone()

	/**
	 * A rule that is not an object is skipped, and list entries that are
	 * nested lists or objects are not group names.
	 *
	 * @return void
	 */
	public function testMalformedRulesAndGroupEntriesAreSkipped(): void {
		$caseType = [
			'fieldRoleRules' => [
				'hidden qualityScore',
				['field' => 'qualityScore', 'rule' => 'hidden', 'groups' => [['nested'], new \stdClass(), ' behandelaars ', 'behandelaars'], 'heldBy' => [['x'], 'quality']],
			],
		];
		$declaration = new FieldRoleRuleDeclaration();

		$this->assertSame(
			['hidden' => [['fields' => ['qualityScore'], 'groups' => ['behandelaars']]]],
			$declaration->lifecycleFields(caseType: $caseType)
		);
		$this->assertSame(
			['qualityScore' => ['update' => [['group' => 'quality']], 'read' => [['group' => 'quality']]]],
			$declaration->propertyAuthorization(caseType: $caseType)
		);
	}//end testMalformedRulesAndGroupEntriesAreSkipped()
}//end class
