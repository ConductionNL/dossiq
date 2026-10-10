<?php

/**
 * Tests for what a DSO term rung does.
 *
 * A case decided after its timer was armed must not be marked overdue or
 * chased, so every act is asserted to refuse a case whose DSO status left the
 * open pair. The overdue marker is written once: a second breach (the repair
 * step after the timer already fired) adds no second journal entry.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Dso;

use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\Dso\DsoDeadlineActs;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\SettingsService;
use OCP\IUserSession;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Notify, mark overdue once, refuse a closed case, read fresh.
 */
class DsoDeadlineActsTest extends TestCase {

	/**
	 * Patches written, by case id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $patches = [];

	/**
	 * The notification manager.
	 *
	 * @var IManager&MockObject
	 */
	private IManager&MockObject $manager;

	/**
	 * The notification the manager hands out.
	 *
	 * @var INotification&MockObject
	 */
	private INotification&MockObject $notification;

	/**
	 * The acts under test, over an object service that records patches.
	 *
	 * @param array<string, array<string, mixed>> $stored Stored cases by id.
	 *
	 * @return DsoDeadlineActs
	 */
	private function acts(array $stored = []): DsoDeadlineActs {
		$patches = &$this->patches;
		$objects = new class ($stored, $patches) {
			/**
			 * Build the store.
			 *
			 * @param array<string, array<string, mixed>> $stored  Stored cases.
			 * @param array<int, array<string, mixed>>    $patches Where patches are recorded.
			 */
			public function __construct(private array $stored, private array &$patches) {
			}

			/**
			 * Find one case.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): ?array {
				return $this->stored[$id] ?? null;
			}

			/**
			 * Record one patch.
			 *
			 * @param string               $objectId The id.
			 * @param array<string, mixed> $data     The changes.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(string $objectId, array $data, mixed $register, mixed $schema): array {
				$this->patches[] = ['id' => $objectId] + $data;
				return $data;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				default => '',
			}
		);

		$this->notification = $this->createMock(INotification::class);
		$this->manager      = $this->createMock(IManager::class);
		$this->manager->method('createNotification')->willReturn($this->notification);

		return new DsoDeadlineActs(
			settingsService: $settings,
			notifications: $this->manager,
			journal: new CaseJournal(userSession: $this->createMock(IUserSession::class)),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end acts()

	/**
	 * A band rung tells the assignee, with the band's subject.
	 *
	 * @return void
	 */
	public function testABandNotifiesTheAssignee(): void {
		$acts = $this->acts();
		$this->notification->expects($this->once())->method('setUser')->with('pjansen');
		$this->notification->expects($this->once())->method('setSubject')->with(Notifier::SUBJECT_DSO_DEADLINE_CRITICAL, ['caseId' => 'case-1']);
		$this->manager->expects($this->once())->method('notify');

		$this->assertSame(
			expected: DsoDeadlineActs::NOTIFIED,
			actual: $acts->notify(case: ['id' => 'case-1', 'dsoStatus' => 'in_handling', 'assignee' => 'pjansen'], subject: Notifier::SUBJECT_DSO_DEADLINE_CRITICAL)
		);
	}//end testABandNotifiesTheAssignee()

	/**
	 * Nobody assigned, or a decided case, is not notified.
	 *
	 * @return void
	 */
	public function testNoAssigneeOrAClosedCaseIsNotNotified(): void {
		$acts = $this->acts();
		$this->manager->expects($this->never())->method('notify');

		$this->assertSame(expected: DsoDeadlineActs::SKIPPED, actual: $acts->notify(case: ['id' => 'case-1', 'dsoStatus' => 'in_handling'], subject: 'x'));
		$this->assertSame(expected: DsoDeadlineActs::SKIPPED, actual: $acts->notify(case: ['id' => 'case-1', 'dsoStatus' => 'decided', 'assignee' => 'pjansen'], subject: 'x'));
	}//end testNoAssigneeOrAClosedCaseIsNotNotified()

	/**
	 * Overdue marks the case and journals it, once.
	 *
	 * @return void
	 */
	public function testOverdueMarksTheCaseOnce(): void {
		$acts = $this->acts();

		$this->assertTrue($acts->markOverdue(case: ['id' => 'case-1', 'dsoStatus' => 'in_handling', 'assignee' => 'pjansen']));
		$this->assertFalse($acts->markOverdue(case: ['id' => 'case-1', 'dsoStatus' => 'in_handling', 'deadlineOverdue' => true]));

		$this->assertCount(expectedCount: 1, haystack: $this->patches);
		$this->assertTrue($this->patches[0]['deadlineOverdue']);
		$journal = json_decode($this->patches[0][CaseJournal::FIELD], true);
		$this->assertSame(expected: 'dsoDeadlineOverdue', actual: $journal[count($journal) - 1]['type']);
	}//end testOverdueMarksTheCaseOnce()

	/**
	 * A decided case is never marked overdue.
	 *
	 * @return void
	 */
	public function testADecidedCaseIsNotMarked(): void {
		$acts = $this->acts();

		$this->assertFalse($acts->markOverdue(case: ['id' => 'case-1', 'dsoStatus' => 'decided']));
		$this->assertSame(expected: [], actual: $this->patches);
	}//end testADecidedCaseIsNotMarked()

	/**
	 * The case is read fresh by id.
	 *
	 * @return void
	 */
	public function testTheCaseIsReadFresh(): void {
		$acts = $this->acts(stored: ['case-1' => ['id' => 'case-1', 'dsoStatus' => 'decided']]);

		$this->assertSame(expected: 'decided', actual: $acts->find(caseId: 'case-1')['dsoStatus']);
		$this->assertNull($acts->find(caseId: 'case-none'));
	}//end testTheCaseIsReadFresh()
}//end class
