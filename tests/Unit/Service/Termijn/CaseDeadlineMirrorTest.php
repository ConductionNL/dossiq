<?php

/**
 * CaseDeadlineMirror unit tests.
 *
 * One term engine (REQ-OTE-01): while a case has a statutory term, the case's
 * deadline is that term's current end. The store is the shared fake with the
 * real ObjectService signatures, so the write goes through the same
 * read-merge-save path a live install without `patchObject()` takes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\Dossiq\Service\Termijn\TermInstanceStore;
use OCA\Dossiq\Service\TermKind;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Termijn\CaseDeadlineMirror
 * @uses   \OCA\Dossiq\Service\Termijn\TermInstanceStore
 * @uses   \OCA\Dossiq\Service\TermKind
 */
class CaseDeadlineMirrorTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var FakeTermijnStore
	 */
	private FakeTermijnStore $objects;

	/**
	 * The mirror under test.
	 *
	 * @var CaseDeadlineMirror
	 */
	private CaseDeadlineMirror $mirror;

	/**
	 * Wire the mirror over an empty store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'termijn_instance_schema' => 'deadlineInstance',
				default => '',
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$this->mirror = new CaseDeadlineMirror(
			settingsService: $settings,
			store: new TermInstanceStore(settingsService: $settings, logger: $logger),
			logger: $logger,
		);
	}//end setUp()

	/**
	 * Seed one instance.
	 *
	 * @param string               $id     Its id.
	 * @param array<string, mixed> $fields Its fields.
	 *
	 * @return void
	 */
	private function instance(string $id, array $fields): void {
		$this->objects->seed(
			'deadlineInstance',
			array_merge(
				['id' => $id, 'case' => 'case-1', 'status' => 'lopend', 'startDate' => '2026-09-01T09:00:00+02:00'],
				$fields
			)
		);
	}//end instance()

	/**
	 * A paused term's moved end date is the case's deadline (the pause scenario).
	 *
	 * @return void
	 */
	public function testAPausedTermsEndIsTheCaseDeadline(): void {
		$this->objects->seed('case', ['id' => 'case-1', 'title' => 'Dakkapel', 'deadline' => '2026-11-02']);
		$this->instance('t1', ['status' => 'paused', 'endDateCurrent' => '2026-11-12']);

		self::assertTrue($this->mirror->follow('case-1'), 'A case that disagrees with its term is written.');

		$case = $this->objects->get('case', 'case-1');
		self::assertSame('2026-11-12', $case[CaseDeadlineMirror::FIELD]);
		self::assertSame('Dakkapel', $case['title'], 'The write merges; it does not replace the case.');
	}//end testAPausedTermsEndIsTheCaseDeadline()

	/**
	 * A case that already agrees is not written again.
	 *
	 * @return void
	 */
	public function testACaseThatAgreesIsNotWritten(): void {
		$this->objects->seed(
			'case',
			['id' => 'case-1', 'deadline' => '2026-11-12', CaseDeadlineMirror::FIELD => '2026-11-12']
		);
		$this->instance('t1', ['endDateCurrent' => '2026-11-12']);

		self::assertFalse($this->mirror->follow('case-1'));
	}//end testACaseThatAgreesIsNotWritten()

	/**
	 * Planned, internal and phase clocks never decide the case's deadline.
	 *
	 * @return void
	 */
	public function testOnlyTheStatutoryTermDecides(): void {
		$this->objects->seed('case', ['id' => 'case-1', 'deadline' => '2026-11-02']);
		$this->instance('planned', ['kind' => TermKind::PLANNED, 'endDateCurrent' => '2026-10-01', 'startDate' => '2026-09-03T09:00:00+02:00']);
		$this->instance('internal', ['kind' => TermKind::INTERNAL, 'endDateCurrent' => '2026-09-20', 'startDate' => '2026-09-02T09:00:00+02:00']);

		self::assertNull($this->mirror->deadlineFor('case-1'), 'No statutory term: the fallback stands.');
		self::assertFalse($this->mirror->follow('case-1'));

		$this->instance('statutory', ['endDateCurrent' => '2026-10-27T00:00:00+01:00']);
		self::assertSame('2026-10-27', $this->mirror->deadlineFor('case-1'), 'A term without a kind is statutory, and its date is a day.');
	}//end testOnlyTheStatutoryTermDecides()

	/**
	 * A running statutory term wins over a completed one; when all are
	 * completed, the newest decides.
	 *
	 * @return void
	 */
	public function testTheRunningTermWinsOverACompletedOne(): void {
		$rows = [
			['id' => 'new', 'kind' => 'statutory', 'status' => 'completed', 'endDateCurrent' => '2026-12-01'],
			['id' => 'old', 'kind' => 'statutory', 'status' => 'verlengd', 'endDateCurrent' => '2026-11-16'],
		];
		self::assertSame('old', CaseDeadlineMirror::decidingInstance($rows)['id']);

		$rows[1]['status'] = 'completed';
		self::assertSame('new', CaseDeadlineMirror::decidingInstance($rows)['id']);

		self::assertNull(CaseDeadlineMirror::decidingInstance([]));
	}//end testTheRunningTermWinsOverACompletedOne()

	/**
	 * A case the store does not hold is not invented.
	 *
	 * @return void
	 */
	public function testAMissingCaseIsNotWritten(): void {
		$this->instance('t1', ['endDateCurrent' => '2026-11-12']);

		self::assertFalse($this->mirror->follow('case-1'));
		self::assertNull($this->objects->get('case', 'case-1'));
	}//end testAMissingCaseIsNotWritten()
}//end class
