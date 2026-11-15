<?php

/**
 * QueueViewPreferences tests: the stored grouping, and groups hidden for
 * the day only.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
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

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use InvalidArgumentException;
use OCA\Dossiq\Service\Queue\QueueViewPreferences;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for QueueViewPreferences.
 *
 * @covers \OCA\Dossiq\Service\Queue\QueueViewPreferences
 * @uses   \OCA\Dossiq\Service\Queue\JsonPreference
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
class QueueViewPreferencesTest extends TestCase {

	/**
	 * The user config double.
	 *
	 * @var IUserConfig&MockObject
	 */
	private IUserConfig $config;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = $this->createMock(IUserConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The preferences under test.
	 *
	 * @return QueueViewPreferences
	 */
	private function preferences(): QueueViewPreferences {
		return new QueueViewPreferences(userConfig: $this->config, logger: $this->logger);
	}//end preferences()

	/**
	 * A stored known grouping is answered, read from the app's own key with "source" as default.
	 *
	 * @return void
	 */
	public function testAStoredGroupingIsAnswered(): void {
		$this->config->expects($this->once())->method('getValueString')
			->with('anouk', 'dossiq', 'queue_group_by', 'source')
			->willReturn('priority');

		$this->assertSame(expected: 'priority', actual: $this->preferences()->groupBy(userId: 'anouk'));
	}//end testAStoredGroupingIsAnswered()

	/**
	 * An unknown stored grouping falls back to "source".
	 *
	 * @return void
	 */
	public function testAnUnknownGroupingFallsBackToSource(): void {
		$this->config->method('getValueString')->willReturn('colour');

		$this->assertSame(expected: 'source', actual: $this->preferences()->groupBy(userId: 'anouk'));
	}//end testAnUnknownGroupingFallsBackToSource()

	/**
	 * An unreadable grouping falls back to "source" and is logged.
	 *
	 * @return void
	 */
	public function testAnUnreadableGroupingFallsBackToSourceAndIsLogged(): void {
		$this->config->method('getValueString')->willThrowException(new InvalidArgumentException('lazy mismatch'));
		$this->logger->expects($this->once())->method('warning')
			->with('Dossiq: the queue grouping could not be read: lazy mismatch');

		$this->assertSame(expected: 'source', actual: $this->preferences()->groupBy(userId: 'anouk'));
	}//end testAnUnreadableGroupingFallsBackToSourceAndIsLogged()

	/**
	 * Setting a known grouping stores and answers it; an unknown one stores and answers "source".
	 *
	 * @return void
	 */
	public function testSettingAGroupingStoresOnlyAKnownOne(): void {
		$stored = [];
		$this->config->expects($this->exactly(2))->method('setValueString')->willReturnCallback(
			static function (string $user, string $app, string $key, string $value) use (&$stored): bool {
				$stored[] = [$user, $app, $key, $value];
				return true;
			}
		);

		$this->assertSame(expected: 'due', actual: $this->preferences()->setGroupBy(userId: 'anouk', groupBy: 'due'));
		$this->assertSame(expected: 'source', actual: $this->preferences()->setGroupBy(userId: 'anouk', groupBy: 'colour'));
		$this->assertSame(
			expected: [
				['anouk', 'dossiq', 'queue_group_by', 'due'],
				['anouk', 'dossiq', 'queue_group_by', 'source'],
			],
			actual: $stored
		);
	}//end testSettingAGroupingStoresOnlyAKnownOne()

	/**
	 * Only groups hidden on today's date are hidden; malformed entries are ignored.
	 *
	 * @return void
	 */
	public function testOnlyGroupsHiddenTodayAreHidden(): void {
		$this->config->method('getValueString')
			->with('anouk', 'dossiq', 'queue_hidden_groups', '')
			->willReturn('{"mentions":"2026-11-13","tasks":"2026-11-12","approvals":"2026-11-13","broken":5,"0":"2026-11-13"}');

		$this->assertSame(
			expected: ['mentions', 'approvals'],
			actual: $this->preferences()->hiddenGroups(userId: 'anouk', today: '2026-11-13')
		);
	}//end testOnlyGroupsHiddenTodayAreHidden()

	/**
	 * Nothing stored, unreadable or not JSON hides nothing; the unreadable case is logged.
	 *
	 * @return void
	 */
	public function testNothingStoredOrUnreadableHidesNothing(): void {
		$this->config->method('getValueString')->willReturnOnConsecutiveCalls('', 'not json', '"a string"');
		$preferences = $this->preferences();

		$this->assertSame(expected: [], actual: $preferences->hiddenGroups(userId: 'anouk', today: '2026-11-13'));
		$this->assertSame(expected: [], actual: $preferences->hiddenGroups(userId: 'anouk', today: '2026-11-13'));
		$this->assertSame(expected: [], actual: $preferences->hiddenGroups(userId: 'anouk', today: '2026-11-13'));

		$throwing = $this->createMock(IUserConfig::class);
		$throwing->method('getValueString')->willThrowException(new InvalidArgumentException('bad type'));
		$this->logger->expects($this->once())->method('warning')
			->with('Dossiq: the hidden queue groups could not be read: bad type');

		$this->assertSame(
			expected: [],
			actual: (new QueueViewPreferences(userConfig: $throwing, logger: $this->logger))->hiddenGroups(userId: 'anouk', today: '2026-11-13')
		);
	}//end testNothingStoredOrUnreadableHidesNothing()

	/**
	 * Hiding a group stores it under today and drops entries from earlier days.
	 *
	 * @return void
	 */
	public function testHidingForTodayDropsEarlierDays(): void {
		$this->config->method('getValueString')->willReturn('{"mentions":"2026-11-13","tasks":"2026-11-12"}');
		$this->config->expects($this->once())->method('setValueString')
			->with('anouk', 'dossiq', 'queue_hidden_groups', '{"mentions":"2026-11-13","approvals":"2026-11-13"}');

		$this->assertSame(
			expected: ['mentions', 'approvals'],
			actual: $this->preferences()->hideForToday(userId: 'anouk', group: 'approvals', today: '2026-11-13')
		);
	}//end testHidingForTodayDropsEarlierDays()

	/**
	 * Hiding a group hidden on an earlier day re-dates it to today.
	 *
	 * @return void
	 */
	public function testHidingAgainRedatesTheGroup(): void {
		$this->config->method('getValueString')->willReturn('{"tasks":"2026-11-12"}');
		$this->config->expects($this->once())->method('setValueString')
			->with('anouk', 'dossiq', 'queue_hidden_groups', '{"tasks":"2026-11-13"}');

		$this->assertSame(
			expected: ['tasks'],
			actual: $this->preferences()->hideForToday(userId: 'anouk', group: 'tasks', today: '2026-11-13')
		);
	}//end testHidingAgainRedatesTheGroup()

	/**
	 * Showing a group again removes it and drops earlier days; an empty set is stored as an empty list.
	 *
	 * @return void
	 */
	public function testShowingAgainRemovesTheGroup(): void {
		$this->config->method('getValueString')->willReturnOnConsecutiveCalls(
			'{"mentions":"2026-11-13","tasks":"2026-11-12","approvals":"2026-11-13"}',
			'{"mentions":"2026-11-13"}'
		);
		$written = [];
		$this->config->method('setValueString')->willReturnCallback(
			static function (string $user, string $app, string $key, string $value) use (&$written): bool {
				$written[] = $value;
				return true;
			}
		);
		$preferences = $this->preferences();

		$this->assertSame(expected: ['approvals'], actual: $preferences->showAgain(userId: 'anouk', group: 'mentions', today: '2026-11-13'));
		$this->assertSame(expected: [], actual: $preferences->showAgain(userId: 'anouk', group: 'mentions', today: '2026-11-13'));
		$this->assertSame(expected: ['{"approvals":"2026-11-13"}', '[]'], actual: $written);
	}//end testShowingAgainRemovesTheGroup()
}//end class
