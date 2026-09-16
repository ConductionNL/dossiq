<?php

/**
 * The states a case type publishes onto the case schema.
 *
 * Two assertions here are about failures that are invisible from the editor,
 * and they are the reason this test exists at all. A state keyed by status NAME
 * matches nothing at runtime, because `case.status` carries the statusType
 * uuid. And a merge that only ever adds keeps enforcing a rule the
 * administrator deleted, on a schema every other case type also writes to.
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

use OCA\Dossiq\Service\Access\FieldRoleRuleDeclaration;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use OCA\Dossiq\Service\Status\CaseStateFieldRuleProjector;
use OCA\Dossiq\Service\Status\StatusFieldRuleDeclaration;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Status\CaseStateFieldRuleProjector
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
class CaseStateFieldRuleProjectorTest extends TestCase {

	/**
	 * A projector reading the rows handed to it.
	 *
	 * @param array<int, array<string, mixed>> $statusTypes The statusType rows.
	 * @param array<int, array<string, mixed>> $properties  The propertyDefinition rows.
	 * @param array<string, mixed>             $caseType    The case type row, for its role rules.
	 *
	 * @return CaseStateFieldRuleProjector The projector.
	 */
	private function projector(
		array $statusTypes,
		array $properties = [],
		array $caseType = []
	): CaseStateFieldRuleProjector {
		$store = $this->createMock(CaseTypeStore::class);
		$store->method('readCaseType')->willReturn($caseType);
		$store->method('rowsOfType')->willReturnCallback(
			static fn (string $schemaKey, string $caseTypeId): array => (
				$schemaKey === 'status_type_schema' ? $statusTypes : $properties
			)
		);
		$store->method('rowId')->willReturnCallback(
			static fn (array $row): string => (string)($row['id'] ?? '')
		);
		$store->method('referenceId')->willReturnCallback(
			static fn (mixed $value): string => (is_array($value) === true
				? (string)($value['id'] ?? '')
				: (string)$value)
		);

		return new CaseStateFieldRuleProjector(
			store: $store,
			declaration: new StatusFieldRuleDeclaration(),
			roles: new FieldRoleRuleDeclaration(),
			slugs: $this->createMock(SchemaSlugResolver::class),
			container: $this->createMock(ContainerInterface::class),
			logger: new NullLogger(),
		);
	}//end projector()

	/**
	 * A state is keyed by the statusType uuid, not by its name.
	 *
	 * @return void
	 */
	public function testAStateIsKeyedByTheStatusTypeUuid(): void {
		$states = $this->projector(
			[
				[
					'id' => '0c4b-uuid',
					'name' => 'Besluitvorming',
					'fieldRules' => [['rule' => 'required', 'field' => 'motivering']],
				],
			]
		)->statesOf(caseTypeId: 'ct');

		$this->assertSame(['0c4b-uuid'], array_keys($states));
		$this->assertSame(
			['required' => [['fields' => ['motivering']]]],
			$states['0c4b-uuid']['fields']
		);
	}//end testAStateIsKeyedByTheStatusTypeUuid()

	/**
	 * A role rule lands in every state the case type declares.
	 *
	 * The rule is about who is asking, not about where the case is, so a state
	 * that carries it in one status and not the next would let a handler read
	 * the field by moving the case along.
	 *
	 * @return void
	 */
	public function testARoleRuleLandsInEveryState(): void {
		$states = $this->projector(
			[
				['id' => 'intake-uuid', 'name' => 'Intake'],
				['id' => 'closed-uuid', 'name' => 'Afgehandeld'],
			],
			[],
			[
				'fieldRoleRules' => [
					[
						'field' => 'qualityScore',
						'rule' => 'hidden',
						'groups' => ['behandelaars'],
						'heldBy' => ['dossiq-quality'],
					],
				],
			]
		)->statesOf(caseTypeId: 'ct');

		$this->assertSame(['intake-uuid', 'closed-uuid'], array_keys($states));
		foreach ($states as $state) {
			$this->assertSame(
				['hidden' => [['fields' => ['qualityScore'], 'groups' => ['behandelaars']]]],
				$state['fields']
			);
		}
	}//end testARoleRuleLandsInEveryState()

	/**
	 * A status rule on the same field and kind keeps the role rule out.
	 *
	 * The status rule may carry a condition and a message; the role rule
	 * carries neither. Publishing both would let the unconditional entry decide
	 * a case the author wrote a condition for.
	 *
	 * @return void
	 */
	public function testAStatusRuleOnTheSameFieldKeepsTheRoleRuleOut(): void {
		$states = $this->projector(
			[
				[
					'id' => 'intake-uuid',
					'fieldRules' => [
						[
							'rule' => 'hidden',
							'field' => 'qualityScore',
							'groups' => ['kcc'],
						],
					],
				],
			],
			[],
			[
				'fieldRoleRules' => [
					[
						'field' => 'qualityScore',
						'rule' => 'hidden',
						'groups' => ['behandelaars'],
						'heldBy' => ['dossiq-quality'],
					],
				],
			]
		)->statesOf(caseTypeId: 'ct');

		$this->assertSame(
			['hidden' => [['fields' => ['qualityScore'], 'groups' => ['kcc']]]],
			$states['intake-uuid']['fields']
		);
	}//end testAStatusRuleOnTheSameFieldKeepsTheRoleRuleOut()

	/**
	 * A role rule on another field is published beside the status rule.
	 *
	 * @return void
	 */
	public function testARoleRuleOnAnotherFieldIsPublishedBesideTheStatusRule(): void {
		$states = $this->projector(
			[
				[
					'id' => 'intake-uuid',
					'fieldRules' => [['rule' => 'required', 'field' => 'motivering']],
				],
			],
			[],
			[
				'fieldRoleRules' => [
					[
						'field' => 'confidentiality',
						'rule' => 'readOnly',
						'groups' => ['behandelaars'],
						'heldBy' => ['dossiq-coordinators'],
					],
				],
			]
		)->statesOf(caseTypeId: 'ct');

		$this->assertSame(
			[
				'required' => [['fields' => ['motivering']]],
				'readOnly' => [['fields' => ['confidentiality'], 'groups' => ['behandelaars']]],
			],
			$states['intake-uuid']['fields']
		);
	}//end testARoleRuleOnAnotherFieldIsPublishedBesideTheStatusRule()

	/**
	 * A status that asks nothing of any field gets no entry.
	 *
	 * @return void
	 */
	public function testAStatusThatAsksNothingGetsNoEntry(): void {
		$states = $this->projector(
			[
				['id' => 's1', 'name' => 'Received'],
				['id' => 's2', 'name' => 'Besluitvorming', 'fieldRules' => [['rule' => 'hidden', 'field' => 'qualityScore']]],
			]
		)->statesOf(caseTypeId: 'ct');

		$this->assertSame(['s2'], array_keys($states));
	}//end testAStatusThatAsksNothingGetsNoEntry()

	/**
	 * A property required from a status is published as required there.
	 *
	 * @return void
	 */
	public function testAPropertyRequiredFromAStatusIsPublished(): void {
		$states = $this->projector(
			[['id' => 's2', 'name' => 'Besluitvorming']],
			[['name' => 'kadastraalNummer', 'requiredAtStatus' => 's2']]
		)->statesOf(caseTypeId: 'ct');

		$this->assertSame(
			['required' => [['fields' => ['kadastraalNummer']]]],
			$states['s2']['fields']
		);
	}//end testAPropertyRequiredFromAStatusIsPublished()

	/**
	 * The status's own rule wins over the property's plain one.
	 *
	 * The status rule may carry a condition, groups and a sentence; the
	 * property's carries none of the three, and publishing both would let the
	 * unconditional one decide.
	 *
	 * @return void
	 */
	public function testTheStatusRuleWinsOverThePropertyFlag(): void {
		$states = $this->projector(
			[
				[
					'id' => 's2',
					'fieldRules' => [
						[
							'rule' => 'required',
							'field' => 'motivering',
							'condition' => ['kind' => 'fieldEquals', 'field' => 'outcome', 'value' => 'refused'],
						],
					],
				],
			],
			[['name' => 'motivering', 'requiredAtStatus' => 's2']]
		)->statesOf(caseTypeId: 'ct');

		$this->assertCount(1, $states['s2']['fields']['required']);
		$this->assertArrayHasKey('when', $states['s2']['fields']['required'][0]);
	}//end testTheStatusRuleWinsOverThePropertyFlag()

	/**
	 * A property pointing at a status by reference object still lands.
	 *
	 * @return void
	 */
	public function testAReferenceObjectResolvesToItsStatus(): void {
		$states = $this->projector(
			[['id' => 's2']],
			[['name' => 'bouwkosten', 'requiredAtStatus' => ['id' => 's2', 'name' => 'Besluitvorming']]]
		)->statesOf(caseTypeId: 'ct');

		$this->assertArrayHasKey('s2', $states);
	}//end testAReferenceObjectResolvesToItsStatus()

	/**
	 * A status with no id is skipped rather than published under an empty key.
	 *
	 * @return void
	 */
	public function testAStatusWithNoIdIsSkipped(): void {
		$states = $this->projector(
			[['name' => 'Besluitvorming', 'fieldRules' => [['rule' => 'required', 'field' => 'motivering']]]]
		)->statesOf(caseTypeId: 'ct');

		$this->assertSame([], $states);
	}//end testAStatusWithNoIdIsSkipped()

	/**
	 * Another case type's states survive this one being published.
	 *
	 * The failure this guards is silent: a writer that replaced the block would
	 * unpublish every other case type's rules, and nothing anywhere would say
	 * so. OpenRegister would simply stop refusing the saves they exist to refuse.
	 *
	 * @return void
	 */
	public function testAnotherCaseTypesStatesSurvive(): void {
		$merged = $this->projector([])->mergeStates(
			live: [
				'other-1' => ['fields' => ['required' => [['fields' => ['besluit']]]]],
				'mine-1' => ['fields' => ['hidden' => [['fields' => ['qualityScore']]]]],
			],
			own: ['mine-1' => ['fields' => ['required' => [['fields' => ['motivering']]]]]],
			ownKeys: ['mine-1', 'mine-2'],
		);

		$this->assertArrayHasKey('other-1', $merged);
		$this->assertSame(
			['required' => [['fields' => ['motivering']]]],
			$merged['mine-1']['fields']
		);
	}//end testAnotherCaseTypesStatesSurvive()

	/**
	 * A status whose last rule was deleted loses its entry.
	 *
	 * @return void
	 */
	public function testAStatusThatNoLongerDeclaresAnythingLosesItsEntry(): void {
		$merged = $this->projector([])->mergeStates(
			live: ['mine-1' => ['fields' => ['required' => [['fields' => ['motivering']]]]]],
			own: [],
			ownKeys: ['mine-1'],
		);

		$this->assertSame([], $merged);
	}//end testAStatusThatNoLongerDeclaresAnythingLosesItsEntry()

	/**
	 * A reconcile of the lifecycle keeps the states that were published.
	 *
	 * @return void
	 */
	public function testAReconcileKeepsThePublishedStates(): void {
		$declared = CaseStateFieldRuleProjector::carryForwardStates(
			annotationKey: 'x-openregister-lifecycle',
			declared: ['field' => 'status', 'provider' => 'OCA\\Dossiq\\Lifecycle\\CaseActionProvider'],
			live: [
				'field' => 'status',
				'states' => ['s2' => ['fields' => ['required' => [['fields' => ['motivering']]]]]],
			],
		);

		$this->assertArrayHasKey('states', $declared);
		$this->assertArrayHasKey('s2', $declared['states']);
	}//end testAReconcileKeepsThePublishedStates()

	/**
	 * A register JSON that declares states stays the authority.
	 *
	 * @return void
	 */
	public function testADeclaredStatesBlockIsNotOverwrittenByTheLiveOne(): void {
		$declared = CaseStateFieldRuleProjector::carryForwardStates(
			annotationKey: 'x-openregister-lifecycle',
			declared: ['states' => ['Received' => []]],
			live: ['states' => ['s2' => ['fields' => []]]],
		);

		$this->assertSame(['Received' => []], $declared['states']);
	}//end testADeclaredStatesBlockIsNotOverwrittenByTheLiveOne()

	/**
	 * Every other annotation block passes through untouched.
	 *
	 * @return void
	 */
	public function testAnotherAnnotationBlockPassesThrough(): void {
		$declared = CaseStateFieldRuleProjector::carryForwardStates(
			annotationKey: 'x-openregister-references',
			declared: ['caseType' => ['schema' => 'caseType']],
			live: ['states' => ['s2' => []]],
		);

		$this->assertSame(['caseType' => ['schema' => 'caseType']], $declared);
	}//end testAnotherAnnotationBlockPassesThrough()

	/**
	 * Publishing without an OpenRegister answers false rather than throwing.
	 *
	 * @return void
	 */
	public function testPublishingWithoutOpenRegisterAnswersFalse(): void {
		$this->assertFalse($this->projector([['id' => 's2']])->publish(caseTypeId: 'ct'));
		$this->assertFalse($this->projector([])->publish(caseTypeId: ''));
	}//end testPublishingWithoutOpenRegisterAnswersFalse()
}//end class
