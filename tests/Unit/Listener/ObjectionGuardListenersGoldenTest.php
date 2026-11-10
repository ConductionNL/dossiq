<?php

/**
 * Golden test for the decision guard and the appeal escalation listener.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
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

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\BeroepEscalationListener;
use OCA\Dossiq\Listener\BezwaarDecisionListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every config read, store call and log line of the two listeners, per world.
 *
 * @covers \OCA\Dossiq\Listener\BezwaarDecisionListener
 * @covers \OCA\Dossiq\Listener\BeroepEscalationListener
 * @covers \OCA\Dossiq\Listener\Support\ObjectUpdatePayload
 * @covers \OCA\Dossiq\Listener\Support\ReadsEventObjects
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 *
 * @spec openspec/specs/bezwaar-decision/spec.md
 */
class ObjectionGuardListenersGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The calls, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $log = [];

	/**
	 * The worlds: listener, event kind, new payload, old payload, whether a store is there.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, mixed>|null, 4: bool}>
	 */
	public static function worlds(): array {
		$decided = ['id' => 'b-1', 'status' => 'Decision on objection', '@self' => ['id' => 'b-1', 'schema' => 'objection']];
		$appeal = [
			'id' => 'a-1',
			'sourceObjection' => 'b-1',
			'contestedDecision' => 'd-1',
			'appellantFilingDate' => '2026-01-10',
			'@self' => ['schema' => 'appeal'],
		];

		return [
			'decision guard reverts' => ['decision', 'updated', $decided, ['status' => 'Hearing held'], true],
			'decision guard without an old status' => ['decision', 'updated', $decided, null, true],
			'decision guard without a store' => ['decision', 'updated', $decided, ['status' => 'Hearing held'], false],
			'decision guard on another status' => ['decision', 'updated', array_replace($decided, ['status' => 'Received']), null, true],
			'decision guard on another schema' => ['decision', 'updated', array_replace($decided, ['@self' => ['schema' => 'case']]), null, true],
			'decision guard on a created object' => ['decision', 'created', $decided, null, true],
			'decision guard on a plain event' => ['decision', 'plain', $decided, null, true],
			'appeal on update' => ['appeal', 'updated', $appeal, null, true],
			'appeal on create' => ['appeal', 'created', $appeal, null, true],
			'appeal without a source objection' => ['appeal', 'created', array_diff_key($appeal, ['sourceObjection' => 1]), null, true],
			'appeal on another schema' => ['appeal', 'created', array_replace($appeal, ['@self' => ['schema' => 'case']]), null, true],
			'appeal without a store' => ['appeal', 'created', $appeal, null, false],
			'appeal on a plain event' => ['appeal', 'plain', $appeal, null, true],
			'decision guard, schema slug under self' => [
				'decision',
				'updated',
				array_replace($decided, ['@self' => ['schemaSlug' => 'objection']]),
				['status' => 'Hearing held'],
				true,
			],
			'decision guard, schema slug at the top' => [
				'decision',
				'updated',
				array_replace($decided, ['@self' => [], '_schemaSlug' => 'objection']),
				['status' => 'Hearing held'],
				true,
			],
			'appeal, schema by url' => ['appeal', 'created', array_replace($appeal, ['@self' => ['schema' => 'https://x/schemas/appeal']]), null, true],
			'appeal, schema slug at the top' => ['appeal', 'created', array_replace($appeal, ['@self' => [], 'schema' => 'appeal']), null, true],
			'appeal on a terminal judgment' => ['appeal', 'updated', array_replace($appeal, ['judgmentOutcome' => 'upheld']), null, true],
			'appeal with an open judgment' => ['appeal', 'updated', array_replace($appeal, ['judgmentOutcome' => 'pending']), null, true],
			'appeal without a contested decision' => ['appeal', 'updated', array_replace($appeal, ['contestedDecision' => '']), null, true],
			'appeal without a filing date' => ['appeal', 'updated', array_diff_key($appeal, ['appellantFilingDate' => 1]), null, true],
			'appeal filed after its deadline' => ['appeal', 'updated', array_replace($appeal, ['filingDeadline' => '2026-01-01']), null, true],
			'appeal filed before its deadline' => ['appeal', 'updated', array_replace($appeal, ['filingDeadline' => '2026-02-01']), null, true],
			'appeal with an unreadable deadline' => ['appeal', 'updated', array_replace($appeal, ['filingDeadline' => 'not a date']), null, true],
		];
	}//end worlds()

	/**
	 * The calls match the recording.
	 *
	 * @param string                    $listener `decision` or `appeal`.
	 * @param string                    $kind     `updated`, `created` or `plain`.
	 * @param array<string, mixed>      $new      The new payload.
	 * @param array<string, mixed>|null $old      The old payload.
	 * @param bool                      $store    Whether a store is there.
	 *
	 * @dataProvider worlds
	 *
	 * @return void
	 */
	public function testTheListenerMatchesTheRecording(string $listener, string $kind, array $new, ?array $old, bool $store): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key): string {
				$this->log[] = ['getConfigValue' => $key];
				return ['register' => 'reg', 'bezwaar_schema' => 'objection', 'beroep_schema' => 'appeal', 'bezwaar_decision_schema' => 'decision'][$key] ?? $key;
			}
		);
		$objects = null;
		if ($store === true) {
			$objects = $this->store();
		}

		$settings->method('getObjectService')->willReturn($objects);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		foreach (['debug', 'warning', 'error', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		$instance = new BezwaarDecisionListener(settingsService: $settings, logger: $logger);
		if ($listener === 'appeal') {
			$instance = new BeroepEscalationListener(settingsService: $settings, logger: $logger);
		}

		$instance->handle($this->event(kind: $kind, new: $new, old: $old));

		$name = trim(strtolower((string)preg_replace('/[^a-z]+/i', '-', $this->dataName())), '-');
		$this->assertMatchesGolden(name: 'objection-guard-listeners/' . $name, value: $this->log);
	}//end testTheListenerMatchesTheRecording()

	/**
	 * The event.
	 *
	 * @param string                    $kind `updated`, `created` or `plain`.
	 * @param array<string, mixed>      $new  The new payload.
	 * @param array<string, mixed>|null $old  The old payload.
	 *
	 * @return Event
	 */
	private function event(string $kind, array $new, ?array $old): Event {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($new);
		if ($kind === 'plain') {
			return new Event();
		}

		if ($kind === 'created') {
			return new ObjectCreatedEvent($entity);
		}

		$oldEntity = null;
		if ($old !== null) {
			$oldEntity = $this->createMock(originalClassName: ObjectEntity::class);
			$oldEntity->method('jsonSerialize')->willReturn($old);
		}

		return new ObjectUpdatedEvent($entity, $oldEntity);
	}//end event()

	/**
	 * A store that records every call: no decision is decided, the source objection is open.
	 *
	 * @return object
	 */
	private function store(): object {
		return new class ($this) {
			/**
			 * Constructor.
			 *
			 * @param ObjectionGuardListenersGoldenTest $test The test keeping the log.
			 */
			public function __construct(private readonly ObjectionGuardListenersGoldenTest $test) {
			}

			/**
			 * List objects.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = []): array {
				$this->test->log[] = ['findAll' => $config];
				return [['id' => 'd-1', 'status' => 'draft']];
			}

			/**
			 * Find one object.
			 *
			 * @param mixed ...$args The arguments.
			 *
			 * @return array<string, mixed>
			 */
			public function find(mixed ...$args): array {
				$this->test->log[] = ['find' => $args];
				return ['id' => 'b-1', 'status' => 'Handled', 'dwingendStatus' => false];
			}

			/**
			 * Patch one object.
			 *
			 * @param mixed ...$args The arguments.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(mixed ...$args): array {
				$this->test->log[] = ['patchObject' => $args];
				return ['id' => 'b-1'];
			}
		};
	}//end store()
}//end class
