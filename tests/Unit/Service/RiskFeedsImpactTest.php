<?php

/**
 * The assessed level feeds the impact axis. It does not become a fifth
 * priority word.
 *
 * REQ-MRK-02, last scenario. Four priority vocabularies is the defect
 * `case-priority-impact-urgency` D-6 names, and a risk level is the fifth one
 * unless it feeds the axis that change already defines.
 *
 * 🔴 THE ASSERTION THAT MATTERS IS THE NEGATIVE ONE. It is easy to write a
 * test that a rising risk level raises the priority, and to pass it with code
 * that writes the level straight into `priority`. So every assertion below
 * also checks that what came out is one of the four declared priority values,
 * and one test drives EVERY level through and asserts the same thing, because
 * `critical` is exactly the value a shortcut would leak.
 *
 * The derivation still runs through `derive()` and the case type's matrix. The
 * level supplies one INPUT to it, which is why the two case types below,
 * reading the same assessment, are allowed to answer differently.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CasePriorityService;
use OCA\Dossiq\Service\CaseRiskAssessmentService;
use OCA\Dossiq\Service\CaseTypeResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RiskFeedsImpactTest extends TestCase {

	/**
	 * The service, over a case type that answers what the test needs.
	 *
	 * @param array<string, mixed> $caseType The effective case type.
	 *
	 * @return CasePriorityService The service.
	 */
	private function service(array $caseType): CasePriorityService {
		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturn($caseType);

		// The risk service is REAL rather than a double, over the same resolver:
		// the point of this file is that an assessed level reaches the matrix
		// and never reaches `priority` directly, and a double for the half that
		// does the mapping would let that pass without being true.
		return new CasePriorityService(
			resolver: $resolver,
			logger: new NullLogger(),
			risk: new CaseRiskAssessmentService(resolver: $resolver, logger: new NullLogger())
		);
	}//end service()

	/**
	 * A case carrying an assessed level.
	 *
	 * @param string $level   The assessed level.
	 * @param string $impact  What the impact field says, which the assessment
	 *                        is meant to override.
	 * @param string $urgency How soon it matters.
	 *
	 * @return array<string, mixed> The case.
	 */
	private function caseWith(string $level, string $impact = 'low', string $urgency = 'medium'): array {
		return [
			'caseType' => 'ct-1',
			'impact' => $impact,
			'urgency' => $urgency,
			'riskAssessment' => [
				'level' => $level,
				'ground' => 'Two incidents at the address in twelve months',
				'assessor' => 'nadia',
			],
		];
	}//end caseWith()

	/**
	 * A case type that asked for it reads impact from the assessment.
	 *
	 * @return void
	 */
	public function testARisingLevelRaisesTheDerivedPriority(): void {
		$service = $this->service(caseType: ['impactFromRisk' => true]);

		$low = $service->resolve(case: $this->caseWith(level: 'low'));
		$high = $service->resolve(case: $this->caseWith(level: 'high'));

		self::assertSame(expected: 'low', actual: $low['impact']);
		self::assertSame(expected: 'high', actual: $high['impact']);
		self::assertGreaterThan(
			expected: $low['priorityOrder'],
			actual: $high['priorityOrder'],
			message: 'a higher assessed level derives a higher priority'
		);
	}//end testARisingLevelRaisesTheDerivedPriority()

	/**
	 * Every level derives a priority inside the four words, `critical`
	 * included.
	 *
	 * This is the assertion a shortcut fails. Writing the level into
	 * `priority` would pass the test above and leak `critical` here.
	 *
	 * @return void
	 */
	public function testNoLevelEverBecomesAPriorityWord(): void {
		$service = $this->service(caseType: ['impactFromRisk' => true]);

		foreach (CaseRiskAssessmentService::LEVELS as $level) {
			foreach (CasePriorityService::URGENCY_VALUES as $urgency) {
				$resolved = $service->resolve(case: $this->caseWith(level: $level, urgency: $urgency));

				self::assertContains(
					needle: $resolved['priority'],
					haystack: CasePriorityService::PRIORITY_VALUES,
					message: sprintf('level %s with urgency %s wrote a priority outside the vocabulary', $level, $urgency)
				);
				self::assertContains(
					needle: $resolved['priorityDerived'],
					haystack: CasePriorityService::PRIORITY_VALUES
				);
				self::assertContains(
					needle: $resolved['impact'],
					haystack: CasePriorityService::IMPACT_VALUES,
					message: sprintf('level %s produced an impact outside the axis', $level)
				);
			}
		}
	}//end testNoLevelEverBecomesAPriorityWord()

	/**
	 * A case type that did not ask for it keeps reading the impact field.
	 *
	 * @return void
	 */
	public function testACaseTypeThatDidNotAskIsUnchanged(): void {
		$service = $this->service(caseType: []);

		$resolved = $service->resolve(case: $this->caseWith(level: 'critical', impact: 'low'));

		self::assertSame(
			expected: 'low',
			actual: $resolved['impact'],
			message: 'the impact field still answers where the case type declared nothing'
		);
	}//end testACaseTypeThatDidNotAskIsUnchanged()

	/**
	 * A case type that asked for it, on a case with no assessment, falls back.
	 *
	 * Deriving from a missing assessment would leave the case with no priority
	 * at all, and a case with no priority drops out of every sorted list.
	 *
	 * @return void
	 */
	public function testNoAssessmentFallsBackToTheImpactField(): void {
		$service = $this->service(caseType: ['impactFromRisk' => true]);

		$resolved = $service->resolve(case: ['caseType' => 'ct-1', 'impact' => 'high', 'urgency' => 'high']);

		self::assertSame(expected: 'high', actual: $resolved['impact']);
		self::assertContains(needle: $resolved['priority'], haystack: CasePriorityService::PRIORITY_VALUES);
	}//end testNoAssessmentFallsBackToTheImpactField()

	/**
	 * Two case types read the same assessment differently, because the matrix
	 * is still the thing that decides.
	 *
	 * @return void
	 */
	public function testTheMatrixStillDecides(): void {
		$lenient = $this->service(
			caseType: [
				'impactFromRisk' => true,
				'priorityMatrix' => [['impact' => 'high', 'urgency' => 'medium', 'priority' => 'normal']],
			]
		);
		$strict = $this->service(
			caseType: [
				'impactFromRisk' => true,
				'priorityMatrix' => [['impact' => 'high', 'urgency' => 'medium', 'priority' => 'urgent']],
			]
		);

		$case = $this->caseWith(level: 'critical', urgency: 'medium');

		self::assertSame(expected: 'normal', actual: $lenient->resolve(case: $case)['priority']);
		self::assertSame(expected: 'urgent', actual: $strict->resolve(case: $case)['priority']);
	}//end testTheMatrixStillDecides()

	/**
	 * The level-to-impact map lands inside the impact axis for every level.
	 *
	 * A map entry naming a value the axis does not have would silently fall
	 * back to the impact field, which reads as the declaration not working
	 * rather than as a typo.
	 *
	 * @return void
	 */
	public function testEveryMappedImpactIsOnTheAxis(): void {
		foreach (CaseRiskAssessmentService::LEVELS as $level) {
			self::assertArrayHasKey(
				key: $level,
				array: CaseRiskAssessmentService::LEVEL_IMPACT,
				message: sprintf('level %s maps onto no impact', $level)
			);
			self::assertContains(
				needle: CaseRiskAssessmentService::LEVEL_IMPACT[$level],
				haystack: CasePriorityService::IMPACT_VALUES
			);
		}
	}//end testEveryMappedImpactIsOnTheAxis()
}//end class
