<?php

/**
 * A planned item is a calendar event, and it is not a case.
 *
 * The first test is the load-bearing one and it is a structural assertion, not
 * a behavioural one: the service has no register dependency, so a planned item
 * cannot reach the case table, the open-case count, a case list or a case
 * report. The tempting build was a case with no case type, and no filter added
 * afterwards takes one of those back out of a report.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Queue\PersonalAgendaItemService;
use OCA\Dossiq\Service\Queue\Source\PlannedItemSource;
use OCA\Dossiq\Service\SettingsService;
use OCP\Calendar\ICalendarEventBuilder;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Queue\PersonalAgendaItemService
 * @covers \OCA\Dossiq\Service\Queue\Source\PlannedItemSource
 */
class PersonalAgendaItemTest extends TestCase {
	/**
	 * A translator that answers the source string.
	 *
	 * @return IL10N The translator.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return $l10n;
	}

	/**
	 * The service, over a calendar manager that writes nowhere.
	 *
	 * @param ICalendarManager|null $calendars The calendar manager to use.
	 *
	 * @return PersonalAgendaItemService The service.
	 */
	private function service(?ICalendarManager $calendars = null): PersonalAgendaItemService {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));

		return new PersonalAgendaItemService(
			calendars: ($calendars ?? $this->createMock(ICalendarManager::class)),
			users: $users,
			l10n: $this->l10n(),
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * The service cannot reach the register, so it cannot make a case.
	 *
	 * @return void
	 */
	public function testAPlannedItemCannotReachTheCaseStore(): void {
		$types = array_map(
			static fn (\ReflectionParameter $parameter): string => (string)$parameter->getType(),
			(new ReflectionClass(PersonalAgendaItemService::class))->getConstructor()->getParameters()
		);

		self::assertNotContains(
			SettingsService::class,
			$types,
			'A planned item that can reach the register can become a case, and then a case count.'
		);
		self::assertSame(
			[ICalendarManager::class, IUserManager::class, IL10N::class, LoggerInterface::class],
			$types
		);
	}

	/**
	 * The source that puts planned items on the queue reaches no register either.
	 *
	 * @return void
	 */
	public function testThePlannedItemSourceReachesNoRegister(): void {
		$types = array_map(
			static fn (\ReflectionParameter $parameter): string => (string)$parameter->getType(),
			(new ReflectionClass(PlannedItemSource::class))->getConstructor()->getParameters()
		);

		self::assertSame([PersonalAgendaItemService::class, IL10N::class], $types);
	}

	/**
	 * Every declared template has a length, so no template plans a zero-minute item.
	 *
	 * @return void
	 */
	public function testEveryTemplateHasALength(): void {
		self::assertNotSame([], PersonalAgendaItemService::TEMPLATES);

		foreach (PersonalAgendaItemService::TEMPLATES as $template => $minutes) {
			self::assertIsString($template);
			self::assertGreaterThan(0, $minutes, $template . ' plans an item of no length.');
		}
	}

	/**
	 * A template nobody declared still gets a workable length.
	 *
	 * @return void
	 */
	public function testAnUnknownTemplateStillHasALength(): void {
		self::assertSame(30, $this->service()->minutesFor(template: 'nothing-like-this'));
		self::assertSame(15, $this->service()->minutesFor(template: 'call-back'));
	}

	/**
	 * An item with no title is refused rather than planned as "".
	 *
	 * @return void
	 */
	public function testAnItemWithNoTitleIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('A planned item needs a title.');

		$this->service()->plan(userId: 'alice', title: '  ', startsAt: '2026-09-16 09:00');
	}

	/**
	 * An unreadable moment is refused rather than planned at "now".
	 *
	 * An item silently planned for the moment the button was pressed is worse
	 * than one that was not planned, because the reader believes it is booked.
	 *
	 * @return void
	 */
	public function testAnUnreadableMomentIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('That is not a moment this calendar can read.');

		$this->service()->plan(userId: 'alice', title: 'Call back', startsAt: 'whenever');
	}

	/**
	 * With no writable calendar the reader is told, not quietly ignored.
	 *
	 * @return void
	 */
	public function testNoWritableCalendarIsRefused(): void {
		$builder = $this->createMock(ICalendarEventBuilder::class);
		$builder->method('toIcs')->willReturn("BEGIN:VEVENT\r\nUID:abc-123\r\nEND:VEVENT\r\n");

		$calendars = $this->createMock(ICalendarManager::class);
		$calendars->method('createEventBuilder')->willReturn($builder);
		$calendars->method('getCalendarsForPrincipal')->willReturn([]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('You have no calendar this item can be written to.');

		$this->service(calendars: $calendars)->plan(
			userId: 'alice',
			title: 'Call back',
			startsAt: '2026-09-16 09:00'
		);
	}

	/**
	 * A planned item's group is its own, so it never reads as a case.
	 *
	 * @return void
	 */
	public function testThePlannedItemSourceIsItsOwnGroup(): void {
		$source = new PlannedItemSource(agenda: $this->service(), l10n: $this->l10n());

		self::assertSame('planned-items', $source->name());
		self::assertNotSame('', $source->closesWhen());
		self::assertSame([], $source->mechanisms(), 'Nothing asks a person to plan their own day.');
	}
}//end class
