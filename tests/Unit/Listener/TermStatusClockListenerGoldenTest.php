<?php

/**
 * Golden record of reconciling term clocks with a saved case's status.
 *
 * Records, per world, what the listener asks the clock to reconcile and every
 * log line, so a refactor cannot change which saved objects move a term clock.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\TermStatusClockListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Term\TermStatusClock;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An event carrying a new and an old object, as OpenRegister's saved events do.
 */
class TermClockSavedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param mixed $newObject What getNewObject() answers.
	 * @param mixed $object    What getObject() answers.
	 */
	public function __construct(private mixed $newObject, private mixed $object) {
		parent::__construct();
	}//end __construct()

	/**
	 * The new object.
	 *
	 * @return mixed
	 */
	public function getNewObject(): mixed {
		return $this->newObject;
	}//end getNewObject()

	/**
	 * The object.
	 *
	 * @return mixed
	 */
	public function getObject(): mixed {
		return $this->object;
	}//end getObject()
}//end class

/**
 * An event carrying only an object, as OpenRegister's created event does.
 */
class TermClockCreatedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param mixed $object What getObject() answers.
	 */
	public function __construct(private mixed $object) {
		parent::__construct();
	}//end __construct()

	/**
	 * The object.
	 *
	 * @return mixed
	 */
	public function getObject(): mixed {
		return $this->object;
	}//end getObject()
}//end class

/**
 * Golden test for TermStatusClockListener::handle.
 *
 * @covers \OCA\Dossiq\Listener\TermStatusClockListener
 * @uses \OCA\Dossiq\Listener\Support\EventObjectPayload
 * @uses \OCA\Dossiq\Listener\Support\SchemaMembership
 */
class TermStatusClockListenerGoldenTest extends TestCase {
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
	public function testReconcileAcrossWorlds(): void {
		$case = ['id' => 'case-1', 'schema' => 'case', 'status' => ['id' => 'st-2'], 'caseType' => 'type-1'];
		$serialisable = new class(['@self' => ['id' => 'case-3', 'schema' => 'registers/7/case'], 'status' => 'st-3', 'caseType' => ['uuid' => 'type-3']]) implements \JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $data The data.
			 */
			public function __construct(private array $data) {
			}

			/**
			 * The data.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};
		$worlds = [
			'an event without an object' => [new Event(), 'case', 'ok'],
			'a created case' => [new TermClockCreatedEvent($case), 'case', 'ok'],
			'an updated case, read from the new object' => [new TermClockSavedEvent($case, ['id' => 'old', 'schema' => 'case']), 'case', 'ok'],
			'the new object unreadable, the old one read' => [new TermClockSavedEvent('not an object', $case), 'case', 'ok'],
			'a serialisable entity under a schema path' => [new TermClockCreatedEvent($serialisable), 'case', 'ok'],
			'another schema' => [new TermClockCreatedEvent(['id' => 'n-1', 'schema' => 'note']), 'case', 'ok'],
			'no case schema configured' => [new TermClockCreatedEvent($case), '', 'ok'],
			'a case without an id' => [new TermClockCreatedEvent(['schema' => 'case', 'status' => 'st-1']), 'case', 'ok'],
			'the clock fails' => [new TermClockCreatedEvent($case), 'case', 'throw'],
		];

		$record = [];
		foreach ($worlds as $name => [$event, $schema, $clock]) {
			$this->events = [];
			$this->listenerFor(schema: $schema, clock: $clock)->handle($event);
			$record[$name] = $this->events;
		}

		$this->assertMatchesGolden(name: 'term-status-clock', value: $record);
	}//end testReconcileAcrossWorlds()

	/**
	 * The listener of one world.
	 *
	 * @param string $schema The configured case schema.
	 * @param string $clock  'ok' or 'throw'.
	 *
	 * @return TermStatusClockListener
	 */
	private function listenerFor(string $schema, string $clock): TermStatusClockListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'case_schema') ? $schema : $default
		);

		$termClock = $this->createMock(TermStatusClock::class);
		$termClock->method('reconcile')->willReturnCallback(
			function (string $caseId, string $statusId, string $caseType) use ($clock): string {
				$this->events[] = 'reconcile ' . json_encode([$caseId, $statusId, $caseType]);
				if ($clock === 'throw') {
					throw new RuntimeException('register offline');
				}

				return 'moved';
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'notice', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->events[] = $level . ': ' . $message;
				}
			);
		}

		return new TermStatusClockListener($settings, $termClock, $logger);
	}//end listenerFor()
}//end class
