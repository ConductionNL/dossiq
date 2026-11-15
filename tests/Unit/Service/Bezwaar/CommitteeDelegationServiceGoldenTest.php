<?php

/**
 * CommitteeDelegationService golden test
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Decidiq\Event\GovernanceBodyRequestedEvent;
use OCA\Dossiq\Service\Bezwaar\CommitteeDelegationService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Throwable;

/**
 * Golden test for CommitteeDelegationService::ensureGovernanceBody(): the answer or refusal, the command and the logs.
 *
 * @covers \OCA\Dossiq\Service\Bezwaar\CommitteeDelegationService
 */
class CommitteeDelegationServiceGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Run one call in a world and observe it.
	 *
	 * @param array<string, mixed> $world The committee, the actor and how the decision app answers.
	 *
	 * @return array<string, mixed> The answer or refusal, the command and the log lines.
	 */
	private function observe(array $world): array {
		$logger = new class extends AbstractLogger {
			/**
			 * The log lines.
			 *
			 * @var array<int, mixed>
			 */
			public array $lines = [];

			/**
			 * Keep one log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed>       $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [$level, (string)$message, $context];
			}
		};

		$commands = [];
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use (&$commands, $world): void {
				$commands[] = $event;
				$answer = ($world['answer'] ?? ['id' => 'gb-1', 'created' => true, 'handled' => true]);
				if ($answer === 'throw') {
					throw new RuntimeException('bus down');
				}

				$event->setGovernanceBodyId($answer['id']);
				$event->setCreated($answer['created']);
				$event->setHandled($answer['handled']);
			}
		);

		$service = new CommitteeDelegationService(eventDispatcher: $dispatcher, logger: $logger);
		$args = ['committee' => $world['committee']];
		if (array_key_exists('actorId', $world) === true) {
			$args['actorId'] = $world['actorId'];
		}

		try {
			$answer = ['id' => $service->ensureGovernanceBody(...$args)];
		} catch (Throwable $e) {
			$answer = ['throws' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode(), 'previous' => $e->getPrevious()?->getMessage()];
		}

		return ['answer' => $answer, 'commands' => array_map(fn (Event $e): array => $this->commandOf(event: $e), $commands), 'logs' => $logger->lines];
	}//end observe()

	/**
	 * What one dispatched command carries.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array<string, mixed> Its fields.
	 */
	private function commandOf(Event $event): array {
		if ($event instanceof GovernanceBodyRequestedEvent === false) {
			return ['class' => get_class($event)];
		}

		return [
			'class' => get_class($event),
			'sourceApp' => $event->getSourceApp(),
			'externalReference' => $event->getExternalReference(),
			'name' => $event->getName(),
			'bodyType' => $event->getBodyType(),
			'domain' => $event->getDomain(),
			'active' => $event->isActive(),
			'attributes' => $event->getAttributes(),
			'members' => $event->getMembers(),
			'actorId' => $event->getActorId(),
			'correlationId' => $event->getCorrelationId(),
		];
	}//end commandOf()

	/**
	 * Every path through ensureGovernanceBody().
	 *
	 * @return void
	 */
	public function testGoldenEnsureGovernanceBody(): void {
		$full = [
			'id' => 'cmte-1',
			'name' => '  Commissie sociaal domein ',
			'domain' => ' social_domain ',
			'active' => true,
			'quorum' => 3,
			'jurisdiction' => '',
			'termStartsOn' => '2026-01-01',
			'termEndsOn' => null,
			'chair' => 'alice',
			'secretary' => 'carol',
			'members' => ['alice', ['uid' => 'dave', 'external' => 0], ['uid' => ' '], 'bob', ''],
		];
		$minimal = ['id' => 'cmte-2', 'name' => 'Kamer 2', 'active' => false];
		$worlds = [
			'full committee, created' => ['committee' => $full, 'actorId' => 'eve'],
			'minimal committee, updated' => ['committee' => $minimal, 'answer' => ['id' => 'gb-2', 'created' => false, 'handled' => true]],
			'id under @self' => ['committee' => ['@self' => ['id' => 'self-1']] + array_diff_key($minimal, ['id' => 1])],
			'top-level id wins over @self' => ['committee' => ['@self' => ['id' => 'self-1']] + $minimal],
			'no id' => ['committee' => ['name' => 'Kamer', 'active' => true]],
			'empty id' => ['committee' => ['id' => '', 'name' => 'Kamer', 'active' => true]],
			'no name' => ['committee' => ['id' => 'c', 'active' => true]],
			'blank name' => ['committee' => ['id' => 'c', 'name' => '   ', 'active' => true]],
			'id checked before name' => ['committee' => ['active' => true]],
			'name checked before active' => ['committee' => ['id' => 'c']],
			'no active' => ['committee' => ['id' => 'c', 'name' => 'Kamer']],
			'active as a string' => ['committee' => ['id' => 'c', 'name' => 'Kamer', 'active' => 'true']],
			'not handled' => ['committee' => $minimal, 'answer' => ['id' => 'gb-3', 'created' => true, 'handled' => false]],
			'handled without an id' => ['committee' => $minimal, 'answer' => ['id' => '', 'created' => true, 'handled' => true]],
			'dispatch throws' => ['committee' => $minimal, 'actorId' => 'eve', 'answer' => 'throw'],
			'domain unreadable' => ['committee' => ['domain' => new \stdClass()] + $minimal],
			'members not a list' => ['committee' => ['members' => 'alice', 'chair' => ' ', 'secretary' => 'alice'] + $minimal],
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'committee-delegation', value: $observed);
	}//end testGoldenEnsureGovernanceBody()
}//end class
