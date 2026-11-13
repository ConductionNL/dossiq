<?php

/**
 * One reassignment write: the assignee, the audit entry and the failures
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ReassignmentBatch;
use OCA\Dossiq\Service\Support\WritesReassignments;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What reassignItem writes, and what it answers when it cannot.
 *
 * @covers \OCA\Dossiq\Service\Support\WritesReassignments
 * @uses   \OCA\Dossiq\Service\Support\ReassignmentBatch
 */
final class WritesReassignmentsTest extends TestCase {
	/**
	 * Every update the object service was asked for, as [objectId, data].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $updates = [];

	/**
	 * Every warning logged, as [message, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $warnings = [];

	/**
	 * Reassign one item through a host of the trait.
	 *
	 * @param array<string, mixed> $item   The stored object.
	 * @param string               $schema The schema.
	 * @param string               $id     The object id.
	 * @param bool                 $fails  Whether the update throws.
	 *
	 * @return bool What reassignItem answered.
	 */
	private function reassign(array $item, string $schema, string $id = 'o-1', bool $fails = false): bool {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnMap([['case_schema', '', 'case']]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context): void {
				$this->warnings[] = [$message, $context];
			}
		);
		$objectService = new class($this, $fails) {
			/**
			 * @param WritesReassignmentsTest $test  Receives the updates.
			 * @param bool                    $fails Whether the update throws.
			 */
			public function __construct(private WritesReassignmentsTest $test, private bool $fails) {
			}

			/**
			 * Record or refuse one update.
			 *
			 * @param string               $objectId The object id.
			 * @param array<string, mixed> $data     The data.
			 *
			 * @return void
			 */
			public function updateObject(string $objectId, array $data): void {
				if ($this->fails === true) {
					throw new RuntimeException('store down');
				}

				$this->test->record([$objectId, $data]);
			}
		};
		$host = new class($settings, $logger) {
			use WritesReassignments;

			/**
			 * @param SettingsService $settingsService Configuration.
			 * @param LoggerInterface $logger          The logger.
			 */
			public function __construct(private SettingsService $settingsService, private LoggerInterface $logger) {
			}

			/**
			 * Call the trait's private write.
			 *
			 * @param object               $objectService The object service.
			 * @param string               $schema        The schema.
			 * @param string               $id            The object id.
			 * @param array<string, mixed> $item          The stored object.
			 *
			 * @return bool
			 */
			public function write(object $objectService, string $schema, string $id, array $item): bool {
				return $this->reassignItem($objectService, $schema, $id, $item, new ReassignmentBatch('alice', 'bob', 'carol', 'b-1', '2026-01-02T03:04:05+00:00'));
			}
		};

		return $host->write($objectService, $schema, $id, $item);
	}//end reassign()

	/**
	 * Receive one update from the fake object service.
	 *
	 * @param array<int, mixed> $update [objectId, data].
	 *
	 * @return void
	 */
	public function record(array $update): void {
		$this->updates[] = $update;
	}//end record()

	/**
	 * The audit entry every write appends.
	 *
	 * @return array<string, string>
	 */
	private static function entry(): array {
		return ['type' => 'reassignment', 'reassignedFrom' => 'alice', 'reassignedTo' => 'bob', 'reassignedBy' => 'carol', 'batchId' => 'b-1', 'timestamp' => '2026-01-02T03:04:05+00:00'];
	}//end entry()

	/**
	 * A case without an activity log gets one; the assignee moves.
	 *
	 * @return void
	 */
	public function testACaseGetsTheEntryInANewLog(): void {
		$this->assertTrue($this->reassign(['assignee' => 'alice'], 'case'));
		$this->assertSame([['o-1', ['assignee' => 'bob', 'activity' => json_encode([self::entry()])]]], $this->updates);
	}//end testACaseGetsTheEntryInANewLog()

	/**
	 * A log stored as a JSON string or as an array is extended; another schema keeps its shape.
	 *
	 * @return void
	 */
	public function testAnExistingLogIsExtendedAndATaskWithoutOneKeepsNone(): void {
		$this->assertTrue($this->reassign(['activity' => json_encode([['type' => 'x']])], 'task'));
		$this->assertTrue($this->reassign(['activity' => [['type' => 'y']]], 'task'));
		$this->assertTrue($this->reassign(['activity' => 'not json'], 'task'));
		$this->assertTrue($this->reassign(['assignee' => 'alice'], 'task'));
		$this->assertSame(
			[
				['o-1', ['activity' => json_encode([['type' => 'x'], self::entry()]), 'assignee' => 'bob']],
				['o-1', ['activity' => json_encode([['type' => 'y'], self::entry()]), 'assignee' => 'bob']],
				['o-1', ['activity' => json_encode([self::entry()]), 'assignee' => 'bob']],
				['o-1', ['assignee' => 'bob']],
			],
			$this->updates
		);
	}//end testAnExistingLogIsExtendedAndATaskWithoutOneKeepsNone()

	/**
	 * No id or no schema writes nothing; a failing update is logged and answers false.
	 *
	 * @return void
	 */
	public function testMissingIdentityAndFailuresAnswerFalse(): void {
		$this->assertFalse($this->reassign([], 'case', ''));
		$this->assertFalse($this->reassign([], ''));
		$this->assertFalse($this->reassign([], 'case', 'o-2', true));
		$this->assertSame([], $this->updates);
		$this->assertSame([['Reassignment item failed', ['id' => 'o-2', 'batchId' => 'b-1', 'error' => 'store down']]], $this->warnings);
	}//end testMissingIdentityAndFailuresAnswerFalse()
}//end class
