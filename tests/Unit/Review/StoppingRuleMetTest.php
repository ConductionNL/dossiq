<?php

/**
 * Unit tests for recording a met stopping rule.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Review;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Review\StoppingRuleMet;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use OCA\Dossiq\Tests\Support\EntityAnsweringRegister;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RecordingAuditTrailMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One audit entry and one internal timeline entry when the lower bound reaches the target, once.
 *
 * @covers \OCA\Dossiq\Review\StoppingRuleMet
 * @uses \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-meeting-the-stopping-rule-is-recorded-req-wrs-003
 */
class StoppingRuleMetTest extends TestCase {

	/**
	 * The rule of the scenarios: 0.80 at 0.95.
	 */
	private const RULE = ['id' => 'rule-1', 'targetRecall' => 0.8, 'confidence' => 0.95];

	/**
	 * The audit trail.
	 *
	 * @var RecordingAuditTrailMapper
	 */
	private RecordingAuditTrailMapper $trail;

	/**
	 * The timeline.
	 *
	 * @var CaseTimeline&MockObject
	 */
	private CaseTimeline&MockObject $timeline;

	/**
	 * The recorder on a store holding case X.
	 *
	 * @return StoppingRuleMet The recorder.
	 */
	private function met(): StoppingRuleMet {
		$store = new InMemoryRegister();
		$store->seed(schema: 'case', uuid: 'case-x', row: ['title' => 'Verzoek']);
		$this->trail = new RecordingAuditTrailMapper();
		$this->timeline = $this->createMock(CaseTimeline::class);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(new EntityAnsweringRegister(register: $store));
		$settings->method('getOpenRegisterClass')->willReturn($this->trail);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? $default)
		);

		return new StoppingRuleMet(settingsService: $settings, timeline: $this->timeline, logger: $this->createMock(LoggerInterface::class));
	}//end met()

	/**
	 * A finished sample.
	 *
	 * @param float $lowerBound The recall lower bound.
	 * @param float $estimate The recall estimate.
	 *
	 * @return array<string, mixed> The sample.
	 */
	private static function sample(float $lowerBound, float $estimate): array {
		return [
			'id' => 'sample-1',
			'documents' => array_map(static fn (int $i): string => 'doc-'.$i, range(1, 200)),
			'k' => 2,
			'confidence' => 0.95,
			'recallEstimate' => $estimate,
			'recallLowerBound' => $lowerBound,
		];
	}//end sample()

	/**
	 * The scenario "Met": lower bound 0.8892 against 0.80 writes the audit entry and the timeline entry.
	 *
	 * @return void
	 */
	public function testMeetingTheRuleIsRecorded(): void {
		$met = $this->met();
		$this->timeline->expects($this->once())->method('record')->with(
			'case-x',
			TimelineKinds::STOPPING_RULE_MET,
			$this->stringContains('0.8892'),
			$this->callback(static fn (array $fields): bool => $fields['recallLowerBound'] === 0.8892 && $fields['targetRecall'] === 0.8 && $fields['n'] === 200),
			CaseTimeline::INTERNAL,
		);

		$at = $met->record(caseId: 'case-x', rule: self::RULE, sample: self::sample(lowerBound: 0.8892, estimate: 0.9615), userId: 'reviewer-a');

		$this->assertNotNull($at);
		$this->assertCount(1, $this->trail->rows);
		$this->assertSame(StoppingRuleMet::ACTION, $this->trail->rows[0]['action']);
		$this->assertSame('case-x', $this->trail->rows[0]['object']);
		$this->assertSame(0.8892, $this->trail->rows[0]['context']['recallLowerBound']);
		$this->assertSame('sample-1', $this->trail->rows[0]['context']['sample']);
		$this->assertSame('rule-1', $this->trail->rows[0]['context']['rule']);
	}//end testMeetingTheRuleIsRecorded()

	/**
	 * A sample already stamped is never recorded again.
	 *
	 * @return void
	 */
	public function testASampleIsRecordedAtMostOnce(): void {
		$met = $this->met();
		$this->timeline->expects($this->never())->method('record');

		$stamped = self::sample(lowerBound: 0.8892, estimate: 0.9615) + ['stoppingRuleMetAt' => '2026-10-10T12:00:00+00:00'];

		$this->assertNull($met->record(caseId: 'case-x', rule: self::RULE, sample: $stamped, userId: 'reviewer-a'));
		$this->assertSame([], $this->trail->rows);
	}//end testASampleIsRecordedAtMostOnce()

	/**
	 * The scenario "Not met": 0.7500 against 0.80 records nothing; without a rule nothing either.
	 *
	 * @return void
	 */
	public function testBelowTheTargetOrWithoutARuleNothingIsRecorded(): void {
		$met = $this->met();
		$this->timeline->expects($this->never())->method('record');

		$this->assertNull($met->record(caseId: 'case-x', rule: self::RULE, sample: self::sample(lowerBound: 0.75, estimate: 0.8333), userId: 'reviewer-a'));
		$this->assertNull($met->record(caseId: 'case-x', rule: null, sample: self::sample(lowerBound: 0.99, estimate: 0.99), userId: 'reviewer-a'));
		$this->assertSame([], $this->trail->rows);
	}//end testBelowTheTargetOrWithoutARuleNothingIsRecorded()

	/**
	 * A bound computed at another confidence than the rule's does not meet it.
	 *
	 * @return void
	 */
	public function testABoundAtAnotherConfidenceDoesNotMeetTheRule(): void {
		$sample = ['confidence' => 0.9] + self::sample(lowerBound: 0.95, estimate: 0.97);

		$this->assertFalse(StoppingRuleMet::isMet(rule: self::RULE, sample: $sample));
		$this->assertTrue(StoppingRuleMet::isMet(rule: self::RULE, sample: self::sample(lowerBound: 0.8, estimate: 0.9)));
	}//end testABoundAtAnotherConfidenceDoesNotMeetTheRule()

	/**
	 * When the audit trail refuses the entry, the recorder refuses and writes no timeline entry.
	 *
	 * @return void
	 */
	public function testAnEntryTheAuditTrailRefusesIsARefusal(): void {
		$met = $this->met();
		$this->trail->failsAll = true;
		$this->timeline->expects($this->never())->method('record');

		$this->expectException(RefusedException::class);
		$met->record(caseId: 'case-x', rule: self::RULE, sample: self::sample(lowerBound: 0.8892, estimate: 0.9615), userId: 'reviewer-a');
	}//end testAnEntryTheAuditTrailRefusesIsARefusal()
}//end class
