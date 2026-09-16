<?php

/**
 * What a status asks of the fields, in the shape OpenRegister reads.
 *
 * The assertions that earn their place here are the ones about the SPELLING
 * and about the absent key. A block keyed `readonly` and a rule published with
 * `groups: []` both look correct in a diff and both apply to nobody: the
 * resolver finds no kind it knows in the first case, and no member of an empty
 * group list in the second. Neither raises anything.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Status
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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Status;

use OCA\Dossiq\Service\Status\StatusFieldRuleDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Status\StatusFieldRuleDeclaration
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
class StatusFieldRuleDeclarationTest extends TestCase {

	/**
	 * The reader under test.
	 *
	 * @return StatusFieldRuleDeclaration The declaration reader.
	 */
	private function declaration(): StatusFieldRuleDeclaration {
		return new StatusFieldRuleDeclaration();
	}//end declaration()

	/**
	 * A status declaring nothing publishes nothing.
	 *
	 * @return void
	 */
	public function testAStatusThatDeclaresNothingPublishesNoBlock(): void {
		$this->assertSame([], $this->declaration()->fieldsBlock(statusType: ['name' => 'Received']));
		$this->assertSame([], $this->declaration()->fieldsBlock(statusType: ['fieldRules' => 'nonsense']));
	}//end testAStatusThatDeclaresNothingPublishesNoBlock()

	/**
	 * A required field is published under the key OpenRegister reads.
	 *
	 * @return void
	 */
	public function testARequiredFieldIsPublishedUnderTheRequiredKey(): void {
		$block = $this->declaration()->fieldsBlock(
			statusType: ['fieldRules' => [['rule' => 'required', 'field' => 'motivering']]]
		);

		$this->assertSame(['required' => [['fields' => ['motivering']]]], $block);
	}//end testARequiredFieldIsPublishedUnderTheRequiredKey()

	/**
	 * The three kinds keep the spelling the resolver looks for.
	 *
	 * @return void
	 */
	public function testTheThreeKindsKeepTheSpellingTheResolverLooksFor(): void {
		$block = $this->declaration()->fieldsBlock(
			statusType: [
				'fieldRules' => [
					['rule' => 'readOnly', 'field' => 'confidentiality'],
					['rule' => 'hidden', 'field' => 'qualityScore'],
					['rule' => 'required', 'field' => 'motivering'],
				],
			]
		);

		$this->assertSame(['hidden', 'readOnly', 'required'], array_keys($block));
	}//end testTheThreeKindsKeepTheSpellingTheResolverLooksFor()

	/**
	 * A rule naming no group is published without the key.
	 *
	 * @return void
	 */
	public function testARuleNamingNoGroupIsPublishedWithoutTheKey(): void {
		$block = $this->declaration()->fieldsBlock(
			statusType: ['fieldRules' => [['rule' => 'hidden', 'field' => 'qualityScore', 'groups' => ['  ']]]]
		);

		$this->assertArrayNotHasKey('groups', $block['hidden'][0]);
	}//end testARuleNamingNoGroupIsPublishedWithoutTheKey()

	/**
	 * A rule naming groups keeps them.
	 *
	 * @return void
	 */
	public function testARuleNamingGroupsKeepsThem(): void {
		$block = $this->declaration()->fieldsBlock(
			statusType: [
				'fieldRules' => [
					[
						'rule' => 'readOnly',
						'field' => 'confidentiality',
						'groups' => ['dossiq-handlers', 'dossiq-handlers', ''],
					],
				],
			]
		);

		$this->assertSame(['dossiq-handlers'], $block['readOnly'][0]['groups']);
	}//end testARuleNamingGroupsKeepsThem()

	/**
	 * A fieldEquals condition becomes a JSONLogic node reading the object.
	 *
	 * @return void
	 */
	public function testAFieldEqualsConditionBecomesJsonLogic(): void {
		$block = $this->declaration()->fieldsBlock(
			statusType: [
				'fieldRules' => [
					[
						'rule' => 'required',
						'field' => 'motivering',
						'condition' => ['kind' => 'fieldEquals', 'field' => 'outcome', 'value' => 'refused'],
					],
				],
			]
		);

		$this->assertSame(
			['==' => [['var' => 'object.outcome'], 'refused']],
			$block['required'][0]['when']
		);
	}//end testAFieldEqualsConditionBecomesJsonLogic()

	/**
	 * A fieldPresent condition becomes the truthiness of the property.
	 *
	 * @return void
	 */
	public function testAFieldPresentConditionBecomesTruthiness(): void {
		$block = $this->declaration()->fieldsBlock(
			statusType: [
				'fieldRules' => [
					[
						'rule' => 'readOnly',
						'field' => 'besluit',
						'condition' => ['kind' => 'fieldPresent', 'field' => 'besluit'],
					],
				],
			]
		);

		$this->assertSame(['!!' => ['var' => 'object.besluit']], $block['readOnly'][0]['when']);
	}//end testAFieldPresentConditionBecomesTruthiness()

	/**
	 * A documentPresent condition publishes the rule unconditionally.
	 *
	 * The alternative is a node reading a property nothing writes, which makes
	 * the rule never apply while the editor keeps showing it as declared.
	 *
	 * @return void
	 */
	public function testADocumentConditionPublishesTheRuleWithoutOne(): void {
		$block = $this->declaration()->fieldsBlock(
			statusType: [
				'fieldRules' => [
					[
						'rule' => 'required',
						'field' => 'motivering',
						'condition' => ['kind' => 'documentPresent', 'field' => 'advies'],
					],
				],
			]
		);

		$this->assertArrayNotHasKey('when', $block['required'][0]);
	}//end testADocumentConditionPublishesTheRuleWithoutOne()

	/**
	 * The declared refusal sentence rides along.
	 *
	 * @return void
	 */
	public function testTheDeclaredSentenceRidesAlong(): void {
		$block = $this->declaration()->fieldsBlock(
			statusType: [
				'fieldRules' => [
					[
						'rule' => 'required',
						'field' => 'motivering',
						'message' => 'A motivation is needed before this decision can be taken.',
					],
				],
			]
		);

		$this->assertSame(
			'A motivation is needed before this decision can be taken.',
			$block['required'][0]['message']
		);
	}//end testTheDeclaredSentenceRidesAlong()

	/**
	 * An unusable rule is dropped rather than published.
	 *
	 * @return void
	 */
	public function testAnUnusableRuleIsDropped(): void {
		$block = $this->declaration()->fieldsBlock(
			statusType: [
				'fieldRules' => [
					['rule' => 'readonly', 'field' => 'confidentiality'],
					['rule' => 'required', 'field' => '  '],
					['rule' => 'invented', 'field' => 'motivering'],
					'not an array',
				],
			]
		);

		$this->assertSame([], $block);
	}//end testAnUnusableRuleIsDropped()
}//end class
