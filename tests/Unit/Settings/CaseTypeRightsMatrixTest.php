<?php

/**
 * The case-type rights matrix: department by role by confidentiality.
 *
 * 🔴 WHAT THIS TEST IS ACTUALLY GUARDING.
 *
 * xxllnc sets case-type rights as department by role, separately per
 * confidentiality level. The tempting shortcut is to fold the level into the
 * role name, `behandelaar-vertrouwelijk` beside `behandelaar`, because it
 * needs no schema change at all. It gives a role per level per department,
 * which is the combinatorial explosion every mandate matrix eventually becomes
 * and which nobody can audit once it has happened.
 *
 * A folded name is invisible to every other check: the schema validates, the
 * seed loads, the UI renders. Only a test that reads the DECLARED ROLE NAMES
 * and looks for a level inside them can see it, which is what the second case
 * below does.
 *
 * The third guards the other half. Confidentiality is only an axis if it uses
 * the SAME vocabulary the case and the case type already use. A second
 * spelling of `zaakvertrouwelijk` would mean a row and a case could never be
 * compared without a translation table, and a translation table between two
 * lists of levels is where a level quietly goes missing.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped register definitions and asserts the matrix has three axes.
 *
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
class CaseTypeRightsMatrixTest extends TestCase {

	/**
	 * The main register definition.
	 *
	 * @var string
	 */
	private const REGISTER = __DIR__ . '/../../../lib/Settings/dossiq_register.json';

	/**
	 * The mandate-matrix register fragment.
	 *
	 * @var string
	 */
	private const MANDATE = __DIR__ . '/../../../lib/Settings/register.d/61-mandaat-matrix.json';

	/**
	 * Read one shipped definition.
	 *
	 * @param string $path The file.
	 *
	 * @return array<string, mixed> The decoded definition.
	 */
	private function definition(string $path): array {
		$decoded = json_decode((string)file_get_contents($path), true);
		self::assertIsArray($decoded, sprintf('%s does not parse as JSON.', basename($path)));

		return $decoded;
	}//end definition()

	/**
	 * The `caseType` schema as shipped.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function caseType(): array {
		return $this->definition(path: self::REGISTER)['components']['schemas']['caseType'];
	}//end caseType()

	/**
	 * A rights row carries all three axes, and confidentiality is one of them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	public function testARightsRowIsDepartmentByRoleByConfidentiality(): void {
		$row = $this->caseType()['properties']['rightsMatrix']['items'];

		self::assertSame(
			['department', 'role', 'confidentiality', 'actions'],
			$row['required'],
			'A row missing an axis is a matrix with a dimension nobody declared.',
		);
		self::assertSame('array', $row['properties']['actions']['type']);
	}//end testARightsRowIsDepartmentByRoleByConfidentiality()

	/**
	 * Confidentiality speaks the vocabulary the case already speaks.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	public function testConfidentialityUsesTheVocabularyTheCaseUses(): void {
		$schemas = $this->definition(path: self::REGISTER)['components']['schemas'];
		$onTheCaseType = $schemas['caseType']['properties']['confidentiality']['enum'];
		$onTheRow = $schemas['caseType']['properties']['rightsMatrix']['items']
			['properties']['confidentiality']['enum'];

		self::assertSame(
			$onTheCaseType,
			$onTheRow,
			'A second list of levels is a translation table, and a level goes missing in one.',
		);
	}//end testConfidentialityUsesTheVocabularyTheCaseUses()

	/**
	 * No declared role name carries a confidentiality level.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	public function testNoRoleNameEncodesAConfidentialityLevel(): void {
		$levels = $this->caseType()['properties']['confidentiality']['enum'];

		$declared = [];
		foreach ($this->definition(path: self::MANDATE)['components']['schemas'] as $schema) {
			foreach (($schema['properties'] ?? []) as $name => $property) {
				if (is_array($property) === false || isset($property['enum']) === false) {
					continue;
				}

				if (str_contains(strtolower((string)$name), 'role') === false) {
					continue;
				}

				$declared = array_merge($declared, $property['enum']);
			}
		}

		self::assertNotSame([], $declared, 'No role vocabulary was found, so this test proves nothing.');

		foreach ($declared as $role) {
			foreach ($levels as $level) {
				self::assertStringNotContainsStringIgnoringCase(
					(string)$level,
					(string)$role,
					sprintf(
						'The role "%s" encodes the confidentiality level "%s". '
						. 'Confidentiality is an axis of the rights matrix, not part of a role name: '
						. 'folding it in gives a role per level per department.',
						$role,
						$level
					)
				);
			}
		}
	}//end testNoRoleNameEncodesAConfidentialityLevel()

	/**
	 * A case type belongs to a group, and the group is a schema of its own.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	public function testACaseTypeCanBelongToAGroupThatIsGrantedOnce(): void {
		$caseType = $this->caseType();
		self::assertSame(
			'caseTypeGroup',
			$caseType['properties']['caseTypeGroup']['$ref'],
			'A group named by a bare string is a group nothing can resolve.',
		);

		$mandate = $this->definition(path: self::MANDATE);
		self::assertContains(
			'caseTypeGroup',
			$mandate['components']['registers']['dossiq']['schemas'],
			'A schema that is defined but not registered is never created on an instance.',
		);
		self::assertArrayHasKey('caseTypeGroup', $mandate['components']['schemas']);

		self::assertArrayHasKey(
			'caseTypeGroups',
			$mandate['components']['schemas']['mandate']['properties']['terms']['properties'],
			'A mandate that can only name case types makes a samenwerkingsverband name thirty of them.',
		);
	}//end testACaseTypeCanBelongToAGroupThatIsGrantedOnce()
}//end class
