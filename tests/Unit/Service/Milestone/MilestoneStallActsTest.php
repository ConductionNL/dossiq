<?php

/**
 * Tests for telling the assignee of a case stalled on a milestone.
 *
 * The fire is armed for one milestone; when it lands the case may have moved
 * on. So the notification is asserted to go out only when the case still
 * waits late on THAT milestone, with the subject the Notifier renders.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Milestone
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

namespace OCA\Dossiq\Tests\Unit\Service\Milestone;

use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\Milestone\MilestoneStallActs;
use OCA\Dossiq\Service\Milestone\StalledCaseDetector;
use OCA\Dossiq\Service\SettingsService;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Still late on that milestone: told. Moved on, on time, nobody assigned: not.
 */
class MilestoneStallActsTest extends TestCase {

	/**
	 * The acts over a detector answering the row, recording notifications.
	 *
	 * @param array<string, mixed>|null $row      What waitingOn() answers.
	 * @param array<int, array>         $subjects Where sent subjects are recorded.
	 *
	 * @return MilestoneStallActs
	 */
	private function acts(?array $row, array &$subjects): MilestoneStallActs {
		$detector = $this->createMock(StalledCaseDetector::class);
		$detector->method('waitingOn')->willReturn($row);

		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setMessage'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$notification->method('setSubject')->willReturnCallback(
			static function (string $subject, array $params) use (&$subjects, $notification): INotification {
				$subjects[] = [$subject, $params];
				return $notification;
			}
		);
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($notification);

		return new MilestoneStallActs(
			settingsService: $this->createMock(SettingsService::class),
			detector: $detector,
			notifications: $manager,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end acts()

	/**
	 * A case still late on the armed milestone tells its assignee.
	 *
	 * @return void
	 */
	public function testStillLateOnThatMilestoneIsTold(): void {
		$subjects = [];
		$row      = ['caseId' => 'case-1', 'milestoneIdentifier' => 'besluit', 'milestoneLabel' => 'Besluit', 'daysOverdue' => 2, 'assignee' => 'pjansen'];

		$this->assertTrue($this->acts(row: $row, subjects: $subjects)->notifyIfStalled(case: ['id' => 'case-1'], identifier: 'besluit'));
		$this->assertSame(expected: [[Notifier::SUBJECT_MILESTONE_BOTTLENECK, ['milestone' => 'Besluit', 'daysOverdue' => 2]]], actual: $subjects);
	}//end testStillLateOnThatMilestoneIsTold()

	/**
	 * Moved on to another milestone, on time, nobody assigned, or waiting on nothing: silent.
	 *
	 * @return void
	 */
	public function testOtherwiseNothingIsSent(): void {
		$subjects = [];
		$late     = ['caseId' => 'case-1', 'milestoneIdentifier' => 'besluit', 'daysOverdue' => 2, 'assignee' => 'pjansen'];

		$this->assertFalse($this->acts(row: $late, subjects: $subjects)->notifyIfStalled(case: [], identifier: 'intake'));
		$this->assertFalse($this->acts(row: ['daysOverdue' => 0] + $late, subjects: $subjects)->notifyIfStalled(case: []));
		$this->assertFalse($this->acts(row: ['assignee' => ''] + $late, subjects: $subjects)->notifyIfStalled(case: []));
		$this->assertFalse($this->acts(row: null, subjects: $subjects)->notifyIfStalled(case: []));
		$this->assertSame(expected: [], actual: $subjects);
	}//end testOtherwiseNothingIsSent()
}//end class
