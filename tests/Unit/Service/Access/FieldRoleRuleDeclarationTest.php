<?php

/**
 * What a case type's per-role field rules become.
 *
 * The assertion that earns its place here is the one about POLARITY. One
 * declared rule is published into two blocks that read their group lists in
 * opposite directions: the lifecycle entry names who LOSES the field, the
 * property block names who KEEPS it. Swap them and OpenRegister withholds the
 * field from exactly the people who were meant to keep it, hands it to the
 * people who were not, and reports nothing at all. Both halves are asserted as
 * whole lists rather than as "does not contain the handler", because a rule
 * that vanished entirely also does not contain the handler.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
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
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\FieldRoleRuleDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Access\FieldRoleRuleDeclaration
 *
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */
class FieldRoleRuleDeclarationTest extends TestCase {

	/**
	 * A case type declaring the five fields of the worked example.
	 *
	 * @return array<string, mixed> The case type row.
	 */
	private function caseType(): array {
		return [
			'fieldRoleRules' => [
				[
					'field' => 'qualityScore',
					'rule' => 'hidden',
					'groups' => ['behandelaars'],
					'heldBy' => ['dossiq-coordinators', 'dossiq-quality'],
					'reason' => 'The quality officer scores the handling, so the handler does not read their own score.',
				],
				[
					'field' => 'confidentiality',
					'rule' => 'readOnly',
					'groups' => ['behandelaars'],
					'heldBy' => ['dossiq-coordinators', 'dossiq-quality'],
				],
			],
		];
	}//end caseType()

	/**
	 * The lifecycle entry names the groups the rule is taken FROM.
	 *
	 * @return void
	 */
	public function testTheLifecycleEntryCarriesTheRestrictedGroups(): void {
		$fields = (new FieldRoleRuleDeclaration())->lifecycleFields(caseType: $this->caseType());

		$this->assertSame(['hidden', 'readOnly'], array_keys($fields));
		$this->assertSame(
			[
				[
					'fields' => ['qualityScore'],
					'groups' => ['behandelaars'],
					'message' => 'The quality officer scores the handling, so the handler does not read their own score.',
				],
			],
			$fields['hidden']
		);
		$this->assertSame(
			[['fields' => ['confidentiality'], 'groups' => ['behandelaars']]],
			$fields['readOnly']
		);
	}//end testTheLifecycleEntryCarriesTheRestrictedGroups()

	/**
	 * The property block names the groups that KEEP the field.
	 *
	 * The whole list is asserted, not the absence of the handler. A rule that
	 * failed to publish at all would also lack the handler, and would leave the
	 * field readable by everybody.
	 *
	 * @return void
	 */
	public function testThePropertyBlockCarriesTheHoldingGroups(): void {
		$blocks = (new FieldRoleRuleDeclaration())->propertyAuthorization(caseType: $this->caseType());

		$this->assertSame(
			[
				'update' => [['group' => 'dossiq-coordinators'], ['group' => 'dossiq-quality']],
				'read' => [['group' => 'dossiq-coordinators'], ['group' => 'dossiq-quality']],
			],
			$blocks['qualityScore']
		);
	}//end testThePropertyBlockCarriesTheHoldingGroups()

	/**
	 * A read-only rule leaves the read alone and refuses the change.
	 *
	 * @return void
	 */
	public function testAReadOnlyRuleRestrictsTheWriteAndNotTheRead(): void {
		$blocks = (new FieldRoleRuleDeclaration())->propertyAuthorization(caseType: $this->caseType());

		$this->assertSame(['update'], array_keys($blocks['confidentiality']));
		$this->assertSame(
			[['group' => 'dossiq-coordinators'], ['group' => 'dossiq-quality']],
			$blocks['confidentiality']['update']
		);
	}//end testAReadOnlyRuleRestrictsTheWriteAndNotTheRead()

	/**
	 * A group written into both lists loses the field.
	 *
	 * @return void
	 */
	public function testAGroupInBothListsLosesTheField(): void {
		$declaration = new FieldRoleRuleDeclaration();
		$caseType = [
			'fieldRoleRules' => [
				[
					'field' => 'qualityScore',
					'rule' => 'hidden',
					'groups' => ['behandelaars'],
					'heldBy' => ['behandelaars', 'dossiq-quality'],
				],
			],
		];

		$this->assertSame(
			[['group' => 'dossiq-quality']],
			$declaration->propertyAuthorization(caseType: $caseType)['qualityScore']['read']
		);
		$this->assertSame(
			['behandelaars'],
			$declaration->lifecycleFields(caseType: $caseType)['hidden'][0]['groups']
		);
	}//end testAGroupInBothListsLosesTheField()

	/**
	 * A rule restricting nobody publishes no lifecycle entry.
	 *
	 * An entry with no `groups` applies to everyone, administrators included,
	 * so publishing one for a blank list would take the field off the whole
	 * instance.
	 *
	 * @return void
	 */
	public function testARuleRestrictingNobodyPublishesNoLifecycleEntry(): void {
		$fields = (new FieldRoleRuleDeclaration())->lifecycleFields(
			caseType: [
				'fieldRoleRules' => [
					['field' => 'qualityScore', 'rule' => 'hidden', 'groups' => ['', '  ']],
				],
			]
		);

		$this->assertSame([], $fields);
	}//end testARuleRestrictingNobodyPublishesNoLifecycleEntry()

	/**
	 * A rule naming no holder publishes no property block.
	 *
	 * The mirror of the case above: `read: []` is a non-empty authorization key
	 * holding nobody, which strips the field for every non-administrator.
	 *
	 * @return void
	 */
	public function testARuleNamingNoHolderPublishesNoPropertyBlock(): void {
		$blocks = (new FieldRoleRuleDeclaration())->propertyAuthorization(
			caseType: [
				'fieldRoleRules' => [
					['field' => 'qualityScore', 'rule' => 'hidden', 'groups' => ['behandelaars']],
				],
			]
		);

		$this->assertSame([], $blocks);
	}//end testARuleNamingNoHolderPublishesNoPropertyBlock()

	/**
	 * A rule naming a kind this app does not know is dropped.
	 *
	 * @return void
	 */
	public function testARuleOfAnUnknownKindIsDropped(): void {
		$declaration = new FieldRoleRuleDeclaration();
		$caseType = [
			'fieldRoleRules' => [
				['field' => 'qualityScore', 'rule' => 'required', 'groups' => ['behandelaars'], 'heldBy' => ['x']],
				['field' => '', 'rule' => 'hidden', 'groups' => ['behandelaars'], 'heldBy' => ['x']],
			],
		];

		$this->assertSame([], $declaration->lifecycleFields(caseType: $caseType));
		$this->assertSame([], $declaration->propertyAuthorization(caseType: $caseType));
	}//end testARuleOfAnUnknownKindIsDropped()

	/**
	 * Two rules on one field are published as one block.
	 *
	 * @return void
	 */
	public function testTwoRulesOnOneFieldMergeIntoOneBlock(): void {
		$blocks = (new FieldRoleRuleDeclaration())->propertyAuthorization(
			caseType: [
				'fieldRoleRules' => [
					[
						'field' => 'qualityScore',
						'rule' => 'readOnly',
						'groups' => ['behandelaars'],
						'heldBy' => ['dossiq-quality'],
					],
					[
						'field' => 'qualityScore',
						'rule' => 'hidden',
						'groups' => ['kcc'],
						'heldBy' => ['dossiq-quality', 'dossiq-coordinators'],
					],
				],
			]
		);

		$this->assertSame(
			[['group' => 'dossiq-quality'], ['group' => 'dossiq-coordinators']],
			$blocks['qualityScore']['update']
		);
		$this->assertSame(
			[['group' => 'dossiq-quality'], ['group' => 'dossiq-coordinators']],
			$blocks['qualityScore']['read']
		);
	}//end testTwoRulesOnOneFieldMergeIntoOneBlock()

	/**
	 * A case type declaring nothing publishes nothing.
	 *
	 * @return void
	 */
	public function testACaseTypeDeclaringNothingPublishesNothing(): void {
		$declaration = new FieldRoleRuleDeclaration();

		$this->assertSame([], $declaration->lifecycleFields(caseType: []));
		$this->assertSame([], $declaration->propertyAuthorization(caseType: ['fieldRoleRules' => 'nonsense']));
	}//end testACaseTypeDeclaringNothingPublishesNothing()
}//end class
