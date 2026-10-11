<?php

/**
 * Unit tests for RemedyLodging.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Remedy;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Beschikking\CaseRemedy;
use OCA\Dossiq\Service\Bezwaar\BezwaarCreationHook;
use OCA\Dossiq\Service\Remedy\RemedyLodging;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A remedy opens its own case, linked, with its objection, or nothing at all.
 */
class RemedyLodgingTest extends TestCase {

	/**
	 * @var RemedyObjectServiceDouble
	 */
	private RemedyObjectServiceDouble $objects;

	/**
	 * @var BezwaarCreationHook&\PHPUnit\Framework\MockObject\MockObject
	 */
	private BezwaarCreationHook $hook;

	/**
	 * Build the service around a decided Woo case whose case type declares bezwaar, 42 days.
	 *
	 * @param string $remedyCaseType The case type the remedy opens.
	 *
	 * @return RemedyLodging
	 */
	private function lodging(string $remedyCaseType = 'bezwaar-type'): RemedyLodging {
		$this->objects = new RemedyObjectServiceDouble();
		$this->objects->decisions = [
			['id' => 'decision-old', 'case' => 'woo-1', 'decisionDate' => '2026-09-01'],
			['id' => 'decision-1', 'case' => 'woo-1', 'decisionDate' => '2026-10-30', 'publicationDate' => '2026-11-02'],
		];

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
			['case_schema', '', 'case'],
			['decision_schema', '', 'decision'],
		]);

		$remedy = $this->createMock(CaseRemedy::class);
		$remedy->method('declarationOf')->willReturn(['kind' => 'bezwaar', 'termDays' => 42, 'body' => 'het college', 'enabled' => true, 'caseType' => $remedyCaseType]);

		$this->hook = $this->createMock(BezwaarCreationHook::class);

		return new RemedyLodging(
			settingsService: $settings,
			caseRemedy: $remedy,
			objections: $this->hook,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end lodging()

	/**
	 * The remedy opens a case of the declared type, linked to the decided case, with its objection.
	 *
	 * @return void
	 */
	public function testAnObjectionOpensItsOwnCase(): void {
		$lodging = $this->lodging();
		$this->hook->expects($this->once())->method('onBezwaarCreated')
			->with('bezwaar-1', 'decision-1', $this->callback(static fn (array $p): bool => $p['grounds'] === 'Onevenredig' && $p['isTimely'] === true))
			->willReturn(['id' => 'objection-1']);

		$answer = $lodging->lodge('woo-1', ['receivedDate' => '2026-12-01', 'receivedChannel' => 'brief', 'grounds' => 'Onevenredig']);

		$this->assertSame(['caseId' => 'bezwaar-1', 'objectionId' => 'objection-1', 'deadline' => '2027-01-26', 'isTimely' => true], $answer);
		$this->assertSame('case', $this->objects->saved[0][0]);
		$this->assertSame('bezwaar-type', $this->objects->saved[0][1]['caseType']);
		// The link to the decided case is the objection path's, through CaseRelationService (typed, symmetric).
		$this->assertArrayNotHasKey('relatedCases', $this->objects->saved[0][1]);
		$this->assertSame('Bezwaar: Verlichting fietspad Lindelaan', $this->objects->saved[0][1]['title']);
	}//end testAnObjectionOpensItsOwnCase()

	/**
	 * No decision answers 409 and creates nothing.
	 *
	 * @return void
	 */
	public function testNoDecisionAnswers409AndCreatesNothing(): void {
		$lodging = $this->lodging();
		$this->objects->decisions = [];
		$this->hook->expects($this->never())->method('onBezwaarCreated');

		try {
			$lodging->lodge('woo-1', ['receivedChannel' => 'brief', 'grounds' => 'x']);
			$this->fail('a case without a decision was lodged against');
		} catch (RefusedException $e) {
			$this->assertSame(409, $e->getStatus());
			$this->assertSame('remedy-no-decision', $e->getRule());
		}

		$this->assertSame([], $this->objects->saved);
	}//end testNoDecisionAnswers409AndCreatesNothing()

	/**
	 * A case type that names no remedy case type answers 409 and creates nothing.
	 *
	 * @return void
	 */
	public function testNoRemedyCaseTypeAnswers409(): void {
		$lodging = $this->lodging(remedyCaseType: '');

		$this->expectException(RefusedException::class);
		try {
			$lodging->lodge('woo-1', ['receivedChannel' => 'brief', 'grounds' => 'x']);
		} finally {
			$this->assertSame([], $this->objects->saved);
		}
	}//end testNoRemedyCaseTypeAnswers409()

	/**
	 * A failed objection record deletes the case it opened.
	 *
	 * @return void
	 */
	public function testAFailedObjectionRecordLeavesNoCase(): void {
		$lodging = $this->lodging();
		$this->hook->method('onBezwaarCreated')->willThrowException(new RuntimeException('objection schema refused the payload'));

		try {
			$lodging->lodge('woo-1', ['receivedDate' => '2026-12-01', 'receivedChannel' => 'email', 'grounds' => 'x']);
			$this->fail('a failed objection was answered as lodged');
		} catch (RuntimeException $e) {
			$this->assertSame('objection schema refused the payload', $e->getMessage());
		}

		$this->assertSame(['bezwaar-1'], $this->objects->deleted);
	}//end testAFailedObjectionRecordLeavesNoCase()

	/**
	 * Received within six weeks of the day after the decision was made known: timely.
	 *
	 * @return void
	 */
	public function testATimelyObjection(): void {
		$lodging = $this->lodging();
		$decision = ['decisionDate' => '2026-10-30', 'publicationDate' => '2026-11-02'];

		$this->assertTrue($lodging->isTimely($decision, 42, new DateTimeImmutable('2026-12-01')));
		$this->assertTrue($lodging->isTimely($decision, 42, new DateTimeImmutable('2026-12-14')));
	}//end testATimelyObjection()

	/**
	 * A late objection is still registered, marked untimely.
	 *
	 * @return void
	 */
	public function testALateObjectionIsRegisteredAndMarked(): void {
		$lodging = $this->lodging();
		$this->hook->expects($this->once())->method('onBezwaarCreated')
			->with('bezwaar-1', 'decision-1', $this->callback(static fn (array $p): bool => $p['isTimely'] === false))
			->willReturn(['id' => 'objection-2']);

		$answer = $lodging->lodge('woo-1', ['receivedDate' => '2026-12-21', 'receivedChannel' => 'balie', 'grounds' => 'x']);

		$this->assertFalse($answer['isTimely']);
		$this->assertSame('bezwaar-1', $answer['caseId']);
		$this->assertFalse($lodging->isTimely(['publicationDate' => '2026-11-02'], 42, new DateTimeImmutable('2026-12-15')));
	}//end testALateObjectionIsRegisteredAndMarked()

	/**
	 * An objection the objection schema would refuse opens no case.
	 *
	 * @return void
	 */
	public function testAnIncompleteObjectionOpensNoCase(): void {
		$lodging = $this->lodging();

		foreach ([['grounds' => 'x', 'receivedChannel' => 'post'], ['receivedChannel' => 'brief']] as $objection) {
			try {
				$lodging->lodge('woo-1', $objection);
				$this->fail('an incomplete objection was lodged');
			} catch (RefusedException $e) {
				$this->assertSame(422, $e->getStatus());
			}
		}

		$this->assertSame([], $this->objects->saved);
	}//end testAnIncompleteObjectionOpensNoCase()
}//end class
