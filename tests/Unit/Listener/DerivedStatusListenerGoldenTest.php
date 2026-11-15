<?php

/**
 * Golden record of the derived status move.
 *
 * Records, per world, the data the listener writes into the save, what it
 * stages in the journal and every log line, so a refactor cannot change when
 * a declared status moves a case or what that move writes.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\DerivedStatusListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Status\DerivedStatusJournal;
use OCA\Dossiq\Service\Status\DerivedStatusService;
use OCA\Dossiq\Service\Status\StatusDeclarations;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An update event that carries the modified data, as OpenRegister's does.
 */
class DerivedStatusUpdatingEvent extends ObjectUpdatingEvent {

	/**
	 * The data the save will write on top of the object.
	 *
	 * @var array<string, mixed>
	 */
	public array $modified = [];

	/**
	 * The data the save will write on top of the object.
	 *
	 * @return array<string, mixed>
	 */
	public function getModifiedData(): array {
		return $this->modified;
	}//end getModifiedData()

	/**
	 * Replace the data the save will write.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return void
	 */
	public function setModifiedData(array $data): void {
		$this->modified = $data;
	}//end setModifiedData()
}//end class

/**
 * Golden test for DerivedStatusListener::handle.
 *
 * @covers \OCA\Dossiq\Listener\DerivedStatusListener
 * @uses \OCA\Dossiq\Listener\Support\SchemaMembership
 */
class DerivedStatusListenerGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * What happened in the current world.
	 *
	 * @var list<string>
	 */
	private array $events = [];

	/**
	 * Every world the listener distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testMoveAcrossWorlds(): void {
		$case = ['status' => 'intake', 'title' => 'A case'];
		$worlds = [
			'not an update' => ['event' => 'create', 'schema' => 'case', 'object' => $case, 'modified' => [], 'target' => 'review', 'journal' => true],
			'another schema' => ['event' => 'update', 'schema' => 'note', 'object' => $case, 'modified' => [], 'target' => 'review', 'journal' => true],
			'no declared status became true' => ['event' => 'update', 'schema' => 'case', 'object' => $case, 'modified' => ['title' => 'B'], 'target' => null, 'journal' => true],
			'moves' => ['event' => 'update', 'schema' => 'case', 'object' => $case, 'modified' => ['title' => 'B'], 'target' => 'review', 'journal' => true],
			'moves on a status the save itself sets' => ['event' => 'update', 'schema' => 'case', 'object' => $case, 'modified' => ['status' => 'waiting'], 'target' => 'review', 'journal' => true],
			'moves without a journal' => ['event' => 'update', 'schema' => 'case', 'object' => $case, 'modified' => [], 'target' => 'review', 'journal' => false],
			'moves a case whose payload names its id' => ['event' => 'update', 'schema' => 'case', 'object' => $case + ['id' => 'case-from-payload'], 'modified' => [], 'target' => 'review', 'journal' => true],
		];

		$record = [];
		foreach ($worlds as $name => $world) {
			$this->events = [];
			$event = $this->eventFor(world: $world);
			$this->listenerFor(world: $world)->handle($event);
			$record[$name] = [
				'modified' => ($event instanceof DerivedStatusUpdatingEvent) ? $event->modified : null,
				'events' => $this->events,
			];
		}

		$this->assertMatchesGolden(name: 'derived-status-move', value: $record);
	}//end testMoveAcrossWorlds()

	/**
	 * The save event of one world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return Event
	 */
	private function eventFor(array $world): Event {
		$entity = new ObjectEntity();
		$entity->setObject($world['object']);
		$entity->setSchema($world['schema']);
		$entity->setUuid('case-1');
		if ($world['event'] === 'create') {
			return new ObjectCreatingEvent($entity);
		}

		$event = new DerivedStatusUpdatingEvent($entity);
		$event->modified = $world['modified'];
		return $event;
	}//end eventFor()

	/**
	 * The listener of one world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return DerivedStatusListener
	 */
	private function listenerFor(array $world): DerivedStatusListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'case_schema') ? 'case' : $default
		);

		$derived = $this->createMock(DerivedStatusService::class);
		$derived->method('statusFor')->willReturnCallback(
			function (array $case) use ($world): ?string {
				$this->events[] = 'statusFor status=' . ($case['status'] ?? '') . ' title=' . ($case['title'] ?? '');
				return $world['target'];
			}
		);

		$declarations = $this->createMock(StatusDeclarations::class);
		$declarations->method('applyStatusChange')->willReturnCallback(
			function (array $case, string $toStatus): array {
				$this->events[] = 'applyStatusChange to ' . $toStatus;
				return $case + ['statusDwellTotals' => ['intake' => 3], 'currentStatusEnteredAt' => '2026-03-02', 'currentStatusDwellDays' => 0, 'statusDwellBreached' => false, 'unrelated' => 'x'];
			}
		);

		$journal = null;
		if ($world['journal'] === true) {
			$journal = $this->createMock(DerivedStatusJournal::class);
			$journal->method('stage')->willReturnCallback(
				function (string $caseId, string $fromStatus, string $toStatus): void {
					$this->events[] = 'stage ' . $caseId . ' ' . $fromStatus . ' -> ' . $toStatus;
				}
			);
		}

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'notice', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->events[] = $level . ': ' . $message . ' ' . json_encode($context);
				}
			);
		}

		return new DerivedStatusListener($settings, $derived, $declarations, $logger, $journal);
	}//end listenerFor()
}//end class
