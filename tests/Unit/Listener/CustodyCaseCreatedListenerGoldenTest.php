<?php

/**
 * Golden record of the first custody holding of a new case.
 *
 * Records, per world, the holding the listener begins and every log line, so a
 * refactor cannot change which created objects start a custody chain or what
 * that first holding says.
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

use OCA\Dossiq\Listener\CustodyCaseCreatedListener;
use OCA\Dossiq\Service\Custody\CaseCustodyChain;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for CustodyCaseCreatedListener::handle.
 *
 * @covers \OCA\Dossiq\Listener\CustodyCaseCreatedListener
 * @uses \OCA\Dossiq\Listener\Support\EventObjectPayload
 */
class CustodyCaseCreatedListenerGoldenTest extends TestCase {
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
	public function testFirstHoldingAcrossWorlds(): void {
		$case = ['id' => ' case-1 ', 'assignedGroup' => ' vergunningen ', 'assignee' => ' alice ', 'createdBy' => ' bob ', 'startDate' => '2026-03-02'];
		$worlds = [
			'not a created event' => ['event' => 'other', 'slug' => 'case', 'object' => $case, 'begin' => 'ok'],
			'another schema' => ['event' => 'created', 'slug' => 'note', 'object' => $case, 'begin' => 'ok'],
			'a case without an id' => ['event' => 'created', 'slug' => 'case', 'object' => ['assignee' => 'alice'], 'begin' => 'ok'],
			'a case named by its uuid' => ['event' => 'created', 'slug' => 'case', 'object' => ['uuid' => 'case-2', 'registrationDate' => '2026-03-01'], 'begin' => 'ok'],
			'a new case' => ['event' => 'created', 'slug' => 'case', 'object' => $case, 'begin' => 'ok'],
			'a new case dated by its creation' => ['event' => 'created', 'slug' => 'case', 'object' => ['id' => 'case-3', '@self' => ['created' => '2026-02-01T09:00:00+01:00']], 'begin' => 'ok'],
			'a new case without any date' => ['event' => 'created', 'slug' => 'case', 'object' => ['id' => 'case-4'], 'begin' => 'ok'],
			'the chain cannot be written' => ['event' => 'created', 'slug' => 'case', 'object' => $case, 'begin' => 'throw'],
		];

		$record = [];
		foreach ($worlds as $name => $world) {
			$this->events = [];
			$this->listenerFor(world: $world)->handle($this->eventFor(world: $world));
			$record[$name] = $this->events;
		}

		$this->assertMatchesGolden(name: 'custody-case-created', value: $record);
	}//end testFirstHoldingAcrossWorlds()

	/**
	 * The event of one world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return Event
	 */
	private function eventFor(array $world): Event {
		if ($world['event'] !== 'created') {
			return new Event();
		}

		$entity = new ObjectEntity();
		$entity->setObject($world['object']);
		return new ObjectCreatedEvent($entity);
	}//end eventFor()

	/**
	 * The listener of one world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return CustodyCaseCreatedListener
	 */
	private function listenerFor(array $world): CustodyCaseCreatedListener {
		$custody = $this->createMock(CaseCustodyChain::class);
		$custody->method('begin')->willReturnCallback(
			function (string $caseId, string $organisationUnit, string $handler, string $from, string $reason, string $movedBy) use ($world): ?array {
				$this->events[] = 'begin ' . json_encode([$caseId, $organisationUnit, $handler, $from, $reason, $movedBy]);
				if ($world['begin'] === 'throw') {
					throw new RuntimeException('register offline');
				}

				return ['id' => 'holding-1'];
			}
		);

		$slugs = $this->createMock(ObjectSchemaSlugResolver::class);
		$slugs->method('resolveFromPayload')->willReturn($world['slug']);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'notice', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->events[] = $level . ': ' . $message . ' ' . json_encode($context);
				}
			);
		}

		return new CustodyCaseCreatedListener($custody, $slugs, $logger);
	}//end listenerFor()
}//end class
