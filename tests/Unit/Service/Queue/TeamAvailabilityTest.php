<?php

/**
 * Tests for TeamAvailability: each handler's line and presence from the user status.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
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
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use DateTimeImmutable;
use OCA\Dossiq\Service\Queue\TeamAvailability;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\UserStatus\IManager as IUserStatusManager;
use OCP\UserStatus\IUserStatus;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Queue\TeamAvailability
 */
class TeamAvailabilityTest extends TestCase {
	/**
	 * A user status double.
	 *
	 * @param string                 $status  The status.
	 * @param string|null            $message The custom message.
	 * @param DateTimeImmutable|null $clearAt When the message ends.
	 *
	 * @return IUserStatus
	 */
	private function status(string $status, ?string $message=null, ?DateTimeImmutable $clearAt=null): IUserStatus {
		$double = $this->createMock(IUserStatus::class);
		$double->method('getStatus')->willReturn($status);
		$double->method('getMessage')->willReturn($message);
		$double->method('getClearAt')->willReturn($clearAt);
		return $double;
	}//end status()

	/**
	 * @return void
	 */
	public function testEachHandlerReadsTheirStatusAndPresence(): void {
		$manager = $this->createMock(IUserStatusManager::class);
		$manager->expects($this->once())->method('getUserStatuses')->with(['jan', 'fatma', 'tim', 'eva'])->willReturn(
			[
				'jan'   => $this->status(status: IUserStatus::ONLINE),
				'fatma' => $this->status(status: IUserStatus::DND, message: 'In a meeting', clearAt: new DateTimeImmutable('2026-10-19 14:00')),
				'tim'   => $this->status(status: IUserStatus::OFFLINE),
			]
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params=[]): string => vsprintf($text, $params));
		$formatter = $this->createMock(IDateTimeFormatter::class);
		$formatter->method('formatTime')->willReturn('14:00');

		$rows = (new TeamAvailability(userStatusManager: $manager, l10n: $l10n, dateTimeFormatter: $formatter))->annotate(
			handlers: [['handler' => 'jan'], ['handler' => 'fatma'], ['handler' => 'tim'], ['handler' => 'eva']]
		);

		self::assertSame(
			[
				['Available', true],
				['In a meeting until 14:00', true],
				['Absent today', false],
				['Absent today', false],
			],
			array_map(static fn (array $row): array => [$row['availability'], $row['present']], $rows)
		);
	}//end testEachHandlerReadsTheirStatusAndPresence()

	/**
	 * @return void
	 */
	public function testNoHandlersAsksNoStatus(): void {
		$manager = $this->createMock(IUserStatusManager::class);
		$manager->expects($this->never())->method('getUserStatuses');

		$rows = (new TeamAvailability(
			userStatusManager: $manager,
			l10n: $this->createMock(IL10N::class),
			dateTimeFormatter: $this->createMock(IDateTimeFormatter::class)
		))->annotate(handlers: []);

		self::assertSame([], $rows);
	}//end testNoHandlersAsksNoStatus()
}//end class
