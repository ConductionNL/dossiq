<?php

/**
 * Old intake objects become cases, or are reported.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Command\DrainIntakeObjectsCommand;
use OCA\Dossiq\Service\Intake\IntakeCaseTypes;
use OCA\Dossiq\Service\Intake\IntakeObjectDrain;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The drain, driven with the shipped portal-request plan.
 *
 * @covers \OCA\Dossiq\Service\Intake\IntakeObjectDrain
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
 */
class IntakeObjectDrainTest extends TestCase {

	private const OBJECTION_TYPE = 'aaaaaaaa-1111-4111-a111-111111111111';

	/**
	 * Cases written, by the fake submit.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Sources marked, source id => case id.
	 *
	 * @var array<string, string>
	 */
	private array $marked = [];

	/**
	 * A drain whose objection case type is known and whose complaint case type is not.
	 *
	 * @return IntakeObjectDrain The drain.
	 */
	private function drain(): IntakeObjectDrain {
		$caseTypes = $this->createMock(IntakeCaseTypes::class);
		$caseTypes->method('caseTypeFor')->willReturnMap(
			[
				[IntakeCaseTypes::OBJECTION, self::OBJECTION_TYPE],
				[IntakeCaseTypes::COMPLAINT, ''],
			]
		);

		return new IntakeObjectDrain(caseTypes: $caseTypes);
	}//end drain()

	/**
	 * Run the drain over these sources.
	 *
	 * @param array<int, array<string, mixed>> $sources  The sources.
	 * @param bool                             $apply    Whether to write.
	 * @param string                           $existing The case id the reference search finds.
	 * @param array<string, mixed>|null        $answer   What the submit answers; null: a fresh id.
	 *
	 * @return array<string, array<int, mixed>> The report.
	 */
	private function drainAll(array $sources, bool $apply = true, string $existing = '', ?array $answer = null): array {
		return $this->drain()->drain(
			sources: $sources,
			plan: DrainIntakeObjectsCommand::PLAN,
			existing: static fn (array $source): string => $existing,
			submit: function (array $case) use ($answer): array {
				$this->written[] = $case;
				return ($answer ?? ['id' => 'case-' . count($this->written)]);
			},
			markSource: function (array $source, string $caseId): void {
				$this->marked[(string)$source['id']] = $caseId;
			},
			apply: $apply
		);
	}//end run()

	/**
	 * A stored objection becomes a case of the objection type, with its old id as earlier reference.
	 *
	 * @return void
	 */
	public function testAnObjectionBecomesACase(): void {
		$report = $this->drainAll(
			sources: [[
				'id' => 'pv-1',
				'kind' => 'bezwaarschrift',
				'subject' => '',
				'rationale' => 'Niet eens',
				'againstCaseId' => 'z-09128',
				'submitterRef' => 'subj-1',
				'submittedAt' => '2026-09-01T10:00:00+02:00',
			]]
		);

		$this->assertSame([['source' => 'pv-1', 'case' => 'case-1']], $report['delivered']);
		$this->assertSame(['pv-1' => 'case-1'], $this->marked);
		$case = $this->written[0];
		$this->assertSame(self::OBJECTION_TYPE, $case['caseType']);
		$this->assertSame('Bezwaar', $case['title']);
		$this->assertSame('z-09128', $case['contestedCase']);
		$this->assertSame('pv-1', $case['externalReference']);
		$this->assertSame('subj-1', $case['portalSubject']);
		$this->assertSame('2026-09-01T10:00:00+02:00', $case['receivedAt']);
		$this->assertSame('website', $case['intakeChannel']);
		$this->assertArrayNotHasKey('contestedDecision', $case);
	}//end testAnObjectionBecomesACase()

	/**
	 * A kind with no configured case type is refused with a finding, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredKindIsRefused(): void {
		$report = $this->drainAll(sources: [['id' => 'pv-2', 'kind' => 'klachtschrift', 'subject' => 'Bejegening']]);

		$this->assertSame('case-type-unknown', $report['refused'][0]['findings'][0]['code']);
		$this->assertSame([], $this->written);
	}//end testAnUnconfiguredKindIsRefused()

	/**
	 * A delivered source is skipped; one a case already holds is marked and skipped.
	 *
	 * @return void
	 */
	public function testADeliveredSourceIsSkipped(): void {
		$report = $this->drainAll(sources: [['id' => 'pv-3', 'kind' => 'bezwaarschrift', 'newCaseId' => 'case-old']]);
		$this->assertSame(['pv-3'], $report['already']);
		$this->assertSame([], $this->marked);

		$report = $this->drainAll(sources: [['id' => 'pv-4', 'kind' => 'bezwaarschrift']], existing: 'case-held');
		$this->assertSame(['pv-4'], $report['already']);
		$this->assertSame(['pv-4' => 'case-held'], $this->marked);
		$this->assertSame([], $this->written);
	}//end testADeliveredSourceIsSkipped()

	/**
	 * A dry run writes and marks nothing.
	 *
	 * @return void
	 */
	public function testADryRunWritesNothing(): void {
		$report = $this->drainAll(sources: [['id' => 'pv-5', 'kind' => 'bezwaarschrift']], apply: false);

		$this->assertSame([['source' => 'pv-5', 'case' => '']], $report['delivered']);
		$this->assertSame([], $this->written);
		$this->assertSame([], $this->marked);
	}//end testADryRunWritesNothing()

	/**
	 * A refused or failing write is reported and the source stays unmarked.
	 *
	 * @return void
	 */
	public function testARefusedWriteIsReported(): void {
		$finding = ['property' => 'communicationChannel', 'code' => 'required-unmapped', 'message' => 'x'];
		$report = $this->drainAll(sources: [['id' => 'pv-6', 'kind' => 'bezwaarschrift']], answer: ['findings' => [$finding]]);
		$this->assertSame([['source' => 'pv-6', 'findings' => [$finding]]], $report['refused']);

		$failing = $this->drain()->drain(
			sources: [['id' => 'pv-7', 'kind' => 'bezwaarschrift']],
			plan: DrainIntakeObjectsCommand::PLAN,
			existing: static fn (array $source): string => '',
			submit: static function (array $case): array {
				throw new RuntimeException('down');
			},
			markSource: function (array $source, string $caseId): void {
				$this->marked[(string)$source['id']] = $caseId;
			},
			apply: true
		);
		$this->assertSame('write-failed', $failing['refused'][0]['findings'][0]['code']);
		$this->assertSame([], $this->marked);
	}//end testARefusedWriteIsReported()
}//end class
