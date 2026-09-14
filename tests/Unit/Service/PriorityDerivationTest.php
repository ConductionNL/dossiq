<?php

/**
 * Unit tests for the priority derivation — the matrix, per case type.
 *
 * 🔴 THE FAILURE THIS GUARDS IS A FLAT QUEUE. `case.priority` has existed for
 * months, `facetable`, written as the literal `normal` by three services and
 * derived by nothing. A derivation that answered `normal` for every pair would
 * look exactly like the state before it: no error, no empty column, just a
 * working list in which nothing sorts above anything else. So the tests that
 * matter here are the ones that pin DIFFERENT answers to different inputs, and
 * the one that pins two case types answering differently for the same pair.
 *
 * The resolver is a double rather than a mock with expectations: what is under
 * test is the answer the matrix gives, not how many times the case type was
 * read.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CasePriorityService;
use OCA\Dossiq\Service\CaseTypeResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class PriorityDerivationTest extends TestCase {

	/**
	 * A service over a fixed set of case types.
	 *
	 * @param array<string, array<string, mixed>> $caseTypes Effective case types, keyed by id.
	 * @param boolean $throws Whether every case type read throws.
	 *
	 * @return CasePriorityService The service.
	 */
	private function service(array $caseTypes = [], bool $throws = false): CasePriorityService {
		$resolver = $this->createMock(CaseTypeResolver::class);
		if ($throws === true) {
			$resolver->method('effectiveCaseType')->willThrowException(new RuntimeException('unreadable'));
		} else {
			$resolver->method('effectiveCaseType')->willReturnCallback(
				static fn (string $caseTypeId): array => ($caseTypes[$caseTypeId] ?? [])
			);
		}

		return new CasePriorityService($resolver, new NullLogger());
	}//end service()

	/**
	 * Every cell of the instance default matrix, as the scored band it claims
	 * to be: low counts 1, medium 2, high 3, added, 2-3 low, 4 normal, 5 high,
	 * 6 urgent.
	 *
	 * The table is written out rather than computed, because a test that
	 * recomputed the rule would pass for any rule.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function defaultMatrixCases(): array {
		return [
			'low impact, low urgency' => ['low', 'low', 'low'],
			'low impact, medium urgency' => ['low', 'medium', 'low'],
			'low impact, high urgency' => ['low', 'high', 'normal'],
			'medium impact, low urgency' => ['medium', 'low', 'low'],
			'medium impact, medium urgency' => ['medium', 'medium', 'normal'],
			'medium impact, high urgency' => ['medium', 'high', 'high'],
			'high impact, low urgency' => ['high', 'low', 'normal'],
			'high impact, medium urgency' => ['high', 'medium', 'high'],
			'high impact, high urgency' => ['high', 'high', 'urgent'],
		];
	}//end defaultMatrixCases()

	/**
	 * The instance default answers every pair, and answers each one once.
	 *
	 * @param string $impact   The impact.
	 * @param string $urgency  The urgency.
	 * @param string $expected The priority the matrix must give.
	 *
	 * @dataProvider defaultMatrixCases
	 */
	public function testTheInstanceDefaultMatrixAnswersEveryPair(
		string $impact,
		string $urgency,
		string $expected,
	): void {
		self::assertSame($expected, $this->service()->derive(impact: $impact, urgency: $urgency));
	}//end testTheInstanceDefaultMatrixAnswersEveryPair()

	/**
	 * The nine cells are not all the same value. This is the mutation guard:
	 * a derivation that returned `normal` for everything would pass every
	 * single-cell assertion above that happens to expect `normal`, and this
	 * refuses it outright.
	 */
	public function testTheMatrixDoesNotAnswerTheSameValueForEveryPair(): void {
		$service = $this->service();
		$answers = [];
		foreach (CasePriorityService::IMPACT_VALUES as $impact) {
			foreach (CasePriorityService::URGENCY_VALUES as $urgency) {
				$answers[] = $service->derive(impact: $impact, urgency: $urgency);
			}
		}

		self::assertCount(9, $answers);
		self::assertSame(
			CasePriorityService::PRIORITY_VALUES,
			array_values(array_unique($answers)),
			'every declared priority must be reachable from some pair, in order'
		);
	}//end testTheMatrixDoesNotAnswerTheSameValueForEveryPair()

	/**
	 * Raising the urgency raises the priority, which is REQ-PRI-02's scenario
	 * and the thing a handler actually does.
	 */
	public function testRaisingTheUrgencyRaisesTheDerivedPriority(): void {
		$service = $this->service();

		self::assertSame('normal', $service->derive(impact: 'medium', urgency: 'medium'));
		self::assertSame('high', $service->derive(impact: 'medium', urgency: 'high'));
	}//end testRaisingTheUrgencyRaisesTheDerivedPriority()

	/**
	 * Two case types read the same pair differently, which is the whole reason
	 * the matrix belongs to the case type and not to the instance.
	 */
	public function testTwoCaseTypesReadTheSameImpactDifferently(): void {
		$service = $this->service(
			[
				'bezwaar' => [
					'id' => 'bezwaar',
					'priorityMatrix' => [
						['impact' => 'high', 'urgency' => 'low', 'priority' => 'urgent'],
					],
				],
				'melding' => [
					'id' => 'melding',
					'priorityMatrix' => [
						['impact' => 'high', 'urgency' => 'low', 'priority' => 'low'],
					],
				],
			]
		);

		$bezwaar = $service->derive(
			impact: 'high',
			urgency: 'low',
			matrix: $service->matrixFor(caseTypeId: 'bezwaar')
		);
		$melding = $service->derive(
			impact: 'high',
			urgency: 'low',
			matrix: $service->matrixFor(caseTypeId: 'melding')
		);

		self::assertSame('urgent', $bezwaar);
		self::assertSame('low', $melding);
		self::assertNotSame($bezwaar, $melding);
	}//end testTwoCaseTypesReadTheSameImpactDifferently()

	/**
	 * A case type that declares one cell inherits the other eight, rather than
	 * losing them. A replace-whole-matrix implementation would leave a type
	 * that cared about one corner with eight empty cells and a case with no
	 * priority at all.
	 */
	public function testADeclaredCellOverlaysTheDefaultRatherThanReplacingIt(): void {
		$service = $this->service(
			[
				'ct' => [
					'id' => 'ct',
					'priorityMatrix' => [
						['impact' => 'low', 'urgency' => 'low', 'priority' => 'urgent'],
					],
				],
			]
		);
		$matrix = $service->matrixFor(caseTypeId: 'ct');

		self::assertSame('urgent', $service->derive(impact: 'low', urgency: 'low', matrix: $matrix));
		self::assertSame('normal', $service->derive(impact: 'medium', urgency: 'medium', matrix: $matrix));
		self::assertSame('urgent', $service->derive(impact: 'high', urgency: 'high', matrix: $matrix));
	}//end testADeclaredCellOverlaysTheDefaultRatherThanReplacingIt()

	/**
	 * A case type declaring no matrix still derives a priority (REQ-PRI-02).
	 */
	public function testACaseTypeWithNoMatrixStillDerivesAPriority(): void {
		$service = $this->service(['ct' => ['id' => 'ct']]);

		self::assertSame(
			CasePriorityService::DEFAULT_MATRIX,
			$service->matrixFor(caseTypeId: 'ct')
		);
	}//end testACaseTypeWithNoMatrixStillDerivesAPriority()

	/**
	 * A case with no case type at all still derives a priority.
	 */
	public function testACaseWithNoCaseTypeStillDerivesAPriority(): void {
		self::assertSame(
			CasePriorityService::DEFAULT_MATRIX,
			$this->service()->matrixFor(caseTypeId: '')
		);
	}//end testACaseWithNoCaseTypeStillDerivesAPriority()

	/**
	 * An unreadable case type falls back to the instance default rather than
	 * leaving the case with no priority. A case with no priority drops out of
	 * every sorted list, which is the failure this change exists to end.
	 */
	public function testAnUnreadableCaseTypeFallsBackToTheInstanceDefault(): void {
		$service = $this->service(throws: true);

		self::assertSame(CasePriorityService::DEFAULT_MATRIX, $service->matrixFor(caseTypeId: 'ct'));
	}//end testAnUnreadableCaseTypeFallsBackToTheInstanceDefault()

	/**
	 * A declared cell naming a value outside the vocabulary is ignored rather
	 * than stored. Accepting it would put a fifth word in the priority field
	 * and break every reader of the enum at once.
	 */
	public function testACellNamingAnUnknownValueIsIgnored(): void {
		$service = $this->service(
			[
				'ct' => [
					'id' => 'ct',
					'priorityMatrix' => [
						['impact' => 'medium', 'urgency' => 'medium', 'priority' => 'blocker'],
						['impact' => 'kritiek', 'urgency' => 'medium', 'priority' => 'urgent'],
						'not an array',
					],
				],
			]
		);
		$matrix = $service->matrixFor(caseTypeId: 'ct');

		self::assertSame(CasePriorityService::DEFAULT_MATRIX, $matrix);
	}//end testACellNamingAnUnknownValueIsIgnored()

	/**
	 * A case created by intake takes the case type's declared defaults
	 * (REQ-PRI-01), and the defaults land as the derived priority.
	 */
	public function testACaseCreatedByIntakeTakesTheDeclaredDefaults(): void {
		$service = $this->service(
			[
				'ct' => ['id' => 'ct', 'defaultImpact' => 'high', 'defaultUrgency' => 'high'],
			]
		);

		self::assertSame(
			['impact' => 'high', 'urgency' => 'high'],
			$service->defaultsFor(caseTypeId: 'ct')
		);

		$resolved = $service->resolve(case: ['caseType' => 'ct']);
		self::assertSame('high', $resolved['impact']);
		self::assertSame('high', $resolved['urgency']);
		self::assertSame('urgent', $resolved['priority']);
	}//end testACaseCreatedByIntakeTakesTheDeclaredDefaults()

	/**
	 * A case type declaring no defaults leaves a case at medium and medium,
	 * which derives `normal` — the value every case in this app carries today.
	 */
	public function testACaseTypeWithNoDefaultsLeavesTheCaseWhereItWas(): void {
		$resolved = $this->service(['ct' => ['id' => 'ct']])->resolve(case: ['caseType' => 'ct']);

		self::assertSame('medium', $resolved['impact']);
		self::assertSame('medium', $resolved['urgency']);
		self::assertSame('normal', $resolved['priority']);
	}//end testACaseTypeWithNoDefaultsLeavesTheCaseWhereItWas()

	/**
	 * A default the case type declares outside the vocabulary is refused, and
	 * the instance default answers instead.
	 */
	public function testADefaultOutsideTheVocabularyIsRefused(): void {
		$service = $this->service(
			['ct' => ['id' => 'ct', 'defaultImpact' => 'catastrophic', 'defaultUrgency' => 'low']]
		);

		self::assertSame(
			['impact' => 'medium', 'urgency' => 'low'],
			$service->defaultsFor(caseTypeId: 'ct')
		);
	}//end testADefaultOutsideTheVocabularyIsRefused()

	/**
	 * A case that names its own impact and urgency keeps them over the case
	 * type's defaults. The defaults are what a case STARTS with, not a ceiling.
	 */
	public function testACaseKeepsItsOwnImpactOverTheTypeDefault(): void {
		$service = $this->service(
			['ct' => ['id' => 'ct', 'defaultImpact' => 'low', 'defaultUrgency' => 'low']]
		);

		$resolved = $service->resolve(
			case: ['caseType' => 'ct', 'impact' => 'high', 'urgency' => 'high']
		);

		self::assertSame('high', $resolved['impact']);
		self::assertSame('urgent', $resolved['priority']);
	}//end testACaseKeepsItsOwnImpactOverTheTypeDefault()

	/**
	 * The resolved block carries the declared order, and the order agrees with
	 * the priority beside it. A queue that sorts differently from how it reads
	 * is worse than one that does not sort at all.
	 */
	public function testTheResolvedOrderAgreesWithTheResolvedPriority(): void {
		$service = $this->service();

		foreach (CasePriorityService::IMPACT_VALUES as $impact) {
			foreach (CasePriorityService::URGENCY_VALUES as $urgency) {
				$resolved = $service->resolve(case: ['impact' => $impact, 'urgency' => $urgency]);
				self::assertSame(
					CasePriorityService::PRIORITY_ORDER[$resolved['priority']],
					$resolved['priorityOrder'],
					sprintf('%s / %s', $impact, $urgency)
				);
			}
		}
	}//end testTheResolvedOrderAgreesWithTheResolvedPriority()

	/**
	 * A case type reference that arrived as an expanded row, not a uuid, still
	 * finds its matrix. OpenRegister answers a `$ref` either way depending on
	 * whether the caller asked for it to be extended.
	 */
	public function testAnExpandedCaseTypeReferenceStillFindsItsMatrix(): void {
		$service = $this->service(
			['ct' => ['id' => 'ct', 'defaultImpact' => 'high', 'defaultUrgency' => 'high']]
		);

		$resolved = $service->resolve(case: ['caseType' => ['id' => 'ct', 'title' => 'Bezwaar']]);

		self::assertSame('urgent', $resolved['priority']);
	}//end testAnExpandedCaseTypeReferenceStillFindsItsMatrix()

	/**
	 * A child case type inherits its parent's matrix and defaults.
	 *
	 * Not a test of this service so much as a guard on the allow-list it
	 * depends on: `CaseTypeResolver::INHERITED_FIELDS` is an ALLOW-LIST, so a
	 * field missing from it does not fail to inherit loudly — it simply never
	 * inherits, and a child type quietly derives by the instance default while
	 * its parent's matrix sits unread.
	 */
	public function testTheInheritedFieldsAllowListCarriesTheMatrix(): void {
		$reflection = new \ReflectionClass(CaseTypeResolver::class);
		$inherited = $reflection->getConstant('INHERITED_FIELDS');

		self::assertIsArray($inherited);
		self::assertContains('priorityMatrix', $inherited);
		self::assertContains('defaultImpact', $inherited);
		self::assertContains('defaultUrgency', $inherited);
	}//end testTheInheritedFieldsAllowListCarriesTheMatrix()
}//end class
