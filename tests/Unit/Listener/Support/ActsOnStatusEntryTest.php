<?php

/**
 * Tests for acting when an update moves an object into a status.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener\Support
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Listener\Support\ActsOnStatusEntry;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The act runs once with the id of the object entering the ENTRY status, not
 * otherwise, and a failure is logged at debug level under FAILURE_LOG instead
 * of escaping.
 *
 * @covers \OCA\Dossiq\Listener\Support\ActsOnStatusEntry
 * @uses \OCA\Dossiq\Listener\Support\ObjectUpdatePayload
 * @uses \OCA\Dossiq\Listener\Support\ReadsEventObjects
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class ActsOnStatusEntryTest extends TestCase {

	/**
	 * The settings the schema is read from.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The logger failures go to.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Configure the schema key and the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(originalClassName: SettingsService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * An update into the status runs the act once with the object id.
	 *
	 * @return void
	 */
	public function testAnEntryRunsTheActWithTheId(): void {
		$this->settings->method('getConfigValue')->with('advice_schema')->willReturn('advice');
		$this->logger->expects($this->never())->method('debug');
		$seen = [];

		$this->host()->run(
			event: $this->update(newStatus: 'requested', oldStatus: 'draft'),
			act: static function (string $id) use (&$seen): void {
				$seen[] = $id;
			}
		);

		$this->assertSame(expected: ['a-1'], actual: $seen);
	}//end testAnEntryRunsTheActWithTheId()

	/**
	 * An update that stays in the status, or another event, does not run the act.
	 *
	 * @return void
	 */
	public function testNoEntryRunsNothing(): void {
		$this->settings->method('getConfigValue')->willReturn('advice');
		$this->logger->expects($this->never())->method('debug');
		$calls = 0;
		$act = static function () use (&$calls): void {
			$calls++;
		};

		$this->host()->run(event: $this->update(newStatus: 'requested', oldStatus: 'requested'), act: $act);
		$this->host()->run(event: new Event(), act: $act);

		$this->assertSame(expected: 0, actual: $calls);
	}//end testNoEntryRunsNothing()

	/**
	 * A failing act is logged under the class's prefix and swallowed.
	 *
	 * @return void
	 */
	public function testAFailingActIsLoggedAndSwallowed(): void {
		$this->settings->method('getConfigValue')->willReturn('advice');
		$this->logger->expects($this->once())->method('debug')->with('Advice entry failed: mail down');

		$this->host()->run(
			event: $this->update(newStatus: 'requested', oldStatus: 'draft'),
			act: static function (): void {
				throw new RuntimeException('mail down');
			}
		);
	}//end testAFailingActIsLoggedAndSwallowed()

	/**
	 * A failure reading the configuration is logged too, and the act does not run.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLoggedAndTheActSkipped(): void {
		$this->settings->method('getConfigValue')->willThrowException(new RuntimeException('config gone'));
		$this->logger->expects($this->once())->method('debug')->with('Advice entry failed: config gone');
		$calls = 0;

		$this->host()->run(
			event: $this->update(newStatus: 'requested', oldStatus: 'draft'),
			act: static function () use (&$calls): void {
				$calls++;
			}
		);

		$this->assertSame(expected: 0, actual: $calls);
	}//end testAFailingReadIsLoggedAndTheActSkipped()

	/**
	 * An update of advice a-1 from one status to another.
	 *
	 * @param string $newStatus The status after.
	 * @param string $oldStatus The status before.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private function update(string $newStatus, string $oldStatus): ObjectUpdatedEvent {
		return new ObjectUpdatedEvent(
			$this->entity(status: $newStatus),
			$this->entity(status: $oldStatus)
		);
	}//end update()

	/**
	 * Advice a-1 in a status.
	 *
	 * @param string $status The status.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $status): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('a-1');
		$entity->setSchema('advice');
		$entity->setObject(['status' => $status]);
		return $entity;
	}//end entity()

	/**
	 * A listener-shaped host with an ENTRY rule and a FAILURE_LOG prefix.
	 *
	 * @return object
	 */
	private function host(): object {
		return new class ($this->settings, $this->logger) {
			use ActsOnStatusEntry;

			/**
			 * The rule.
			 */
			private const ENTRY = ['schemaKey' => 'advice_schema', 'status' => 'requested'];

			/**
			 * The log prefix.
			 */
			private const FAILURE_LOG = 'Advice entry failed: ';

			/**
			 * Constructor.
			 *
			 * @param SettingsService $settingsService The settings.
			 * @param LoggerInterface $logger          The logger.
			 */
			public function __construct(
				private readonly SettingsService $settingsService,
				private readonly LoggerInterface $logger,
			) {
			}

			/**
			 * Act on the entry.
			 *
			 * @param Event    $event The event.
			 * @param callable $act   The act.
			 *
			 * @return void
			 */
			public function run(Event $event, callable $act): void {
				$this->actOnStatusEntry(event: $event, act: $act);
			}
		};
	}//end host()
}//end class
