<?php

/**
 * A stale assessment says so, and an absent one is indistinguishable from a
 * refused one.
 *
 * REQ-MRK-02. Two things are pinned here.
 *
 * THE STALENESS IS ANSWERED AGAINST TODAY, NOT AGAINST THE LAST SAVE. An
 * assessment does not become due for review when somebody edits the case, so
 * stamping `dueForReview` on the write would leave an assessment whose review
 * date passed last month reading as current until an unrelated edit happened
 * to touch the case. Every assertion below passes its own `now`, which is also
 * what keeps this file from turning red on one particular Tuesday.
 *
 * 🔴 A READER WITHOUT THE PERMISSION AND A CASE WITHOUT AN ASSESSMENT MUST
 * ANSWER THE SAME THING. OpenRegister filters the property out of the case
 * before any dossiq code sees it, so what arrives is a case with no
 * `riskAssessment` key. If `describe()` answered those two differently, a
 * surface could tell a reader that an assessment exists which they may not
 * read, which is most of what the permission was for. So the assertion here is
 * that both answer `present: false` and carry no level, no ground and no
 * assessor.
 *
 * The declaration itself is checked against the shipped register fragment, the
 * way `CasePriorityDeclarationTest` pins the priority declaration: the group
 * this class names and the group the property declares are two copies of one
 * fact, and the copy that drifts silently is always the one in PHP.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseRiskAssessmentService;
use PHPUnit\Framework\TestCase;

class CaseRiskAssessmentTest extends TestCase {

	/**
	 * The service.
	 *
	 * @return CaseRiskAssessmentService The service.
	 */
	private function service(): CaseRiskAssessmentService {
		return new CaseRiskAssessmentService();
	}//end service()

	/**
	 * A whole assessment.
	 *
	 * @param string $level      The assessed level.
	 * @param string $reviewDate When it should be looked at again.
	 *
	 * @return array<string, mixed> The case.
	 */
	private function caseWith(string $level, string $reviewDate): array {
		return [
			'riskAssessment' => [
				'level' => $level,
				'ground' => 'Two incidents at the address in twelve months',
				'assessor' => 'nadia',
				'assessedAt' => '2026-01-15',
				'reviewDate' => $reviewDate,
			],
		];
	}//end caseWith()

	/**
	 * The app root.
	 *
	 * @return string The absolute path.
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The whole assessment is readable when it is there.
	 *
	 * @return void
	 */
	public function testThePermittedReaderSeesTheLevelAndItsGround(): void {
		$assessment = $this->service()->describe(
			case: $this->caseWith(level: 'high', reviewDate: '2027-01-15'),
			now: new DateTimeImmutable('2026-06-01')
		);

		self::assertTrue(condition: $assessment['present']);
		self::assertSame(expected: 'high', actual: $assessment['level']);
		self::assertSame(expected: 'Two incidents at the address in twelve months', actual: $assessment['ground']);
		self::assertSame(expected: 'nadia', actual: $assessment['assessor']);
		self::assertSame(expected: '2026-01-15', actual: $assessment['assessedAt']);
		self::assertSame(expected: '2027-01-15', actual: $assessment['reviewDate']);
		self::assertFalse(condition: $assessment['dueForReview']);
	}//end testThePermittedReaderSeesTheLevelAndItsGround()

	/**
	 * A reader the platform filtered the property out for gets exactly what a
	 * case with no assessment gets.
	 *
	 * @return void
	 */
	public function testAFilteredPropertyReadsAsNoAssessmentAtAll(): void {
		$service = $this->service();
		$now = new DateTimeImmutable('2026-06-01');

		// What arrives for a reader without the group: the key is GONE.
		$filtered = $service->describe(case: ['title' => 'Melding geluidsoverlast'], now: $now);
		// What arrives on a case nobody ever assessed.
		$never = $service->describe(case: ['title' => 'Melding geluidsoverlast', 'riskAssessment' => []], now: $now);

		self::assertSame(expected: $filtered, actual: $never, message: 'the two must be indistinguishable');
		self::assertFalse(condition: $filtered['present']);
		self::assertSame(expected: '', actual: $filtered['level']);
		self::assertSame(expected: '', actual: $filtered['ground']);
		self::assertSame(expected: '', actual: $filtered['assessor']);
	}//end testAFilteredPropertyReadsAsNoAssessmentAtAll()

	/**
	 * An assessment whose review date has passed reads as due for review.
	 *
	 * The day named is the day it is still good for, which is how every other
	 * date in this app reads, so the review date itself is not yet due.
	 *
	 * @return void
	 */
	public function testAStaleAssessmentSaysSo(): void {
		$service = $this->service();

		self::assertTrue(
			condition: $service->describe(
				case: $this->caseWith(level: 'medium', reviewDate: '2026-05-31'),
				now: new DateTimeImmutable('2026-06-01')
			)['dueForReview'],
			message: 'a review date behind today is due'
		);

		self::assertFalse(
			condition: $service->describe(
				case: $this->caseWith(level: 'medium', reviewDate: '2026-06-01'),
				now: new DateTimeImmutable('2026-06-01')
			)['dueForReview'],
			message: 'the day named is the day it is still good for'
		);

		self::assertFalse(
			condition: $service->describe(
				case: $this->caseWith(level: 'medium', reviewDate: ''),
				now: new DateTimeImmutable('2026-06-01')
			)['dueForReview'],
			message: 'an assessment with no review date is not stale'
		);

		self::assertFalse(
			condition: $service->describe(
				case: $this->caseWith(level: 'medium', reviewDate: 'whenever'),
				now: new DateTimeImmutable('2026-06-01')
			)['dueForReview'],
			message: 'an unreadable date is not evidence that anything is stale'
		);
	}//end testAStaleAssessmentSaysSo()

	/**
	 * A level outside the vocabulary is no level.
	 *
	 * @return void
	 */
	public function testAnInventedLevelIsNotALevel(): void {
		$service = $this->service();

		self::assertSame(expected: '', actual: $service->levelOf(assessment: ['level' => 'urgent']));
		self::assertSame(expected: '', actual: $service->levelOf(assessment: ['level' => '']));
		self::assertSame(expected: 'critical', actual: $service->levelOf(assessment: ['level' => 'critical']));
	}//end testAnInventedLevelIsNotALevel()

	/**
	 * The facetable mirror follows the assessment and is cleared with it.
	 *
	 * @return void
	 */
	public function testTheMirrorFollowsTheAssessment(): void {
		$service = $this->service();

		self::assertSame(
			expected: ['riskLevel' => 'critical'],
			actual: $service->resolve(case: $this->caseWith(level: 'critical', reviewDate: '2027-01-01'))
		);

		self::assertSame(
			expected: ['riskLevel' => null],
			actual: $service->resolve(case: ['riskAssessment' => []]),
			message: 'a case with no assessment carries no mirrored level'
		);
	}//end testTheMirrorFollowsTheAssessment()

	/**
	 * The group this class names is the group the schema declares.
	 *
	 * Two copies of one fact, and the PHP copy is the one that drifts without
	 * anything failing: a constant nobody compares to the declaration renders
	 * a plausible sentence about a group that guards nothing.
	 *
	 * @return void
	 */
	public function testTheDeclaredRuleAndTheConstantAgree(): void {
		$raw = file_get_contents($this->root() . '/lib/Settings/register.d/38-markers-and-assessments.json');
		self::assertIsString(actual: $raw, message: 'the register fragment must be readable');

		$decoded = json_decode($raw, true);
		self::assertIsArray(actual: $decoded);

		$properties = (array)$decoded['components']['schemas']['case']['properties'];

		foreach (['riskAssessment', 'riskLevel'] as $property) {
			$rules = (array)($properties[$property]['authorization']['read'] ?? []);
			self::assertNotSame(
				expected: [],
				actual: $rules,
				message: sprintf('%s must declare a read rule, or it is readable by anyone who may read the case', $property)
			);
			self::assertSame(
				expected: CaseRiskAssessmentService::READER_GROUP,
				actual: (string)($rules[0]['group'] ?? ''),
				message: sprintf('%s must be behind the group this service names', $property)
			);
		}

		self::assertSame(
			expected: CaseRiskAssessmentService::LEVELS,
			actual: (array)$properties['riskAssessment']['properties']['level']['enum'],
			message: 'the levels this service knows are the levels the schema declares'
		);
	}//end testTheDeclaredRuleAndTheConstantAgree()
}//end class
