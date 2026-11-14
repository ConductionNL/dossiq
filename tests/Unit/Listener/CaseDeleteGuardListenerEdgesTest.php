<?php

/**
 * CaseDeleteGuardListener edge tests.
 *
 * REQ-CM-35: the guard only judges case deletes, reads the disposal date from
 * OpenRegister's retention decision before the case field, and never turns a
 * delete into an error when it cannot read its own stores: an unreadable
 * payload, an unconfigured store or a refused read all leave the delete to
 * proceed, as it did before the guard existed.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/case-delete-guard/specs/case-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Exception\CaseHeldException;
use OCA\Dossiq\Listener\CaseDeleteGuardListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Listener\CaseDeleteGuardListener
 *
 * @uses \OCA\Dossiq\Exception\CaseHeldException
 */
class CaseDeleteGuardListenerEdgesTest extends TestCase {

	/**
	 * The case under test.
	 */
	private const CASE_UUID = '22222222-2222-2222-2222-222222222222';

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Fresh logger per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * An event that is not a pre-delete event is not read at all.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->expects($this->never())->method('getConfigValue');

		$this->listener(settings: $settings)->handle(new Event());
	}//end testAnotherEventIsIgnored()

	/**
	 * Without a configured case schema nothing is guarded, even a closed case in retention.
	 *
	 * @return void
	 */
	public function testWithoutACaseSchemaNothingIsGuarded(): void {
		$event = $this->deleteEventFor(entity: $this->entity(payload: $this->inRetention()));
		$this->listener(settings: $this->settings(config: ['case_schema' => ''], objectService: null))->handle($event);

		$this->assertFalse(condition: $event->isPropagationStopped());
	}//end testWithoutACaseSchemaNothingIsGuarded()

	/**
	 * A case whose schema is only in its payload is still recognised and refused.
	 *
	 * @return void
	 */
	public function testTheSchemaIsReadFromThePayloadWhenTheMetadataHasNone(): void {
		$entity = $this->entity(payload: $this->inRetention() + ['schema' => 'registers/dossiq/case'], schema: '');
		$event = $this->deleteEventFor(entity: $entity);
		$this->listener(settings: $this->settings(objectService: null))->handle($event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: [CaseHeldException::RULE_IN_RETENTION], actual: $event->getErrors()['blockedBy']);
	}//end testTheSchemaIsReadFromThePayloadWhenTheMetadataHasNone()

	/**
	 * OpenRegister's retention decision wins over the case's own archive date, both ways.
	 *
	 * @return void
	 */
	public function testTheRetentionDecisionDateIsReadFirst(): void {
		$held = $this->deleteEventFor(
			entity: $this->entity(
				payload: ['endDate' => '2006-01-31', 'archiveActionDate' => '2016-01-31'],
				selfRetention: ['disposalDate' => '2046-01-31T00:00:00+00:00']
			)
		);
		$this->listener(settings: $this->settings(objectService: null))->handle($held);
		$this->assertTrue(condition: $held->isPropagationStopped());

		$free = $this->deleteEventFor(
			entity: $this->entity(
				payload: ['endDate' => '2006-01-31', 'archiveActionDate' => '2046-01-31'],
				selfRetention: ['disposalDate' => '2016-01-31']
			)
		);
		$this->listener(settings: $this->settings(objectService: null))->handle($free);
		$this->assertFalse(condition: $free->isPropagationStopped());
	}//end testTheRetentionDecisionDateIsReadFirst()

	/**
	 * An unreadable disposal date holds nothing, and is logged at debug level.
	 *
	 * @return void
	 */
	public function testAnUnreadableDisposalDateHoldsNothing(): void {
		$this->logger->expects($this->once())->method('debug')
			->with($this->stringStartsWith('Dossiq: case delete guard could not read a disposal date: '));
		$event = $this->deleteEventFor(entity: $this->entity(payload: ['endDate' => '2006-01-31', 'archiveActionDate' => 'not a date']));
		$this->listener(settings: $this->settings(objectService: null))->handle($event);

		$this->assertFalse(condition: $event->isPropagationStopped());
	}//end testAnUnreadableDisposalDateHoldsNothing()

	/**
	 * An entity whose payload cannot be read is let through, and the failure logged.
	 *
	 * @return void
	 */
	public function testAnUnreadablePayloadIsLetThrough(): void {
		$entity = new class extends ObjectEntity {
			/**
			 * Fail to serialise.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				throw new RuntimeException('broken row');
			}
		};
		$this->logger->expects($this->once())->method('debug')
			->with('Dossiq: case delete guard could not read the payload: broken row');
		$event = $this->deleteEventFor(entity: $entity);
		$this->listener(settings: $this->settings(objectService: null))->handle($event);

		$this->assertFalse(condition: $event->isPropagationStopped());
	}//end testAnUnreadablePayloadIsLetThrough()

	/**
	 * Without an object service, or without a register, no term or sub-case is looked up.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredStoreHoldsNothing(): void {
		$closedAndFree = ['endDate' => '2006-01-31', 'archiveActionDate' => '2016-01-31'];

		$noService = $this->deleteEventFor(entity: $this->entity(payload: $closedAndFree));
		$this->listener(settings: $this->settings(objectService: null))->handle($noService);
		$this->assertFalse(condition: $noService->isPropagationStopped());

		$service = $this->refusingService();
		$noRegister = $this->deleteEventFor(entity: $this->entity(payload: $closedAndFree));
		$this->listener(settings: $this->settings(config: ['register' => ''], objectService: $service))->handle($noRegister);
		$this->assertFalse(condition: $noRegister->isPropagationStopped());
		$this->assertSame(expected: 0, actual: $service->calls);
	}//end testAnUnconfiguredStoreHoldsNothing()

	/**
	 * A refused read is logged per store and holds nothing.
	 *
	 * @return void
	 */
	public function testARefusedReadIsLoggedAndHoldsNothing(): void {
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);
		$event = $this->deleteEventFor(entity: $this->entity(payload: ['endDate' => '2006-01-31', 'archiveActionDate' => '2016-01-31']));
		$this->listener(settings: $this->settings(objectService: $this->refusingService()))->handle($event);

		$this->assertFalse(condition: $event->isPropagationStopped());
		$this->assertSame(
			expected: [
				['Dossiq: case delete guard could not read termijn_instance_schema', ['error' => 'read refused']],
				['Dossiq: case delete guard could not read case_schema', ['error' => 'read refused']],
			],
			actual: $warnings
		);
	}//end testARefusedReadIsLoggedAndHoldsNothing()

	/**
	 * A closed case whose disposal date is years in the future.
	 *
	 * @return array<string, mixed> The case payload.
	 */
	private function inRetention(): array {
		return ['endDate' => '2026-01-31', 'archiveActionDate' => '2046-01-31'];
	}//end inRetention()

	/**
	 * A case entity, optionally with OpenRegister's retention decision in its metadata.
	 *
	 * @param array<string, mixed>      $payload       The case fields.
	 * @param string                    $schema        The schema the entity carries.
	 * @param array<string, mixed>|null $selfRetention The `@self._retention` decision.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload, string $schema = 'case', ?array $selfRetention = null): ObjectEntity {
		$entity = new class extends ObjectEntity {
			/**
			 * The retention decision to add to the metadata.
			 *
			 * @var array<string, mixed>|null
			 */
			public ?array $decision = null;

			/**
			 * The stub's payload plus the retention decision.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				$data = parent::jsonSerialize();
				if ($this->decision !== null) {
					$data['@self']['_retention'] = $this->decision;
				}

				return $data;
			}
		};
		$entity->setObject($payload);
		$entity->setSchema($schema);
		$entity->setUuid(self::CASE_UUID);
		$entity->decision = $selfRetention;

		return $entity;
	}//end entity()

	/**
	 * The delete event for an entity.
	 *
	 * @param ObjectEntity $entity The entity.
	 *
	 * @return ObjectDeletingEvent
	 */
	private function deleteEventFor(ObjectEntity $entity): ObjectDeletingEvent {
		return new ObjectDeletingEvent($entity);
	}//end deleteEventFor()

	/**
	 * A settings double over config overrides and an object service.
	 *
	 * @param array<string, string> $config        Config overrides.
	 * @param object|null           $objectService The object service, or none.
	 *
	 * @return SettingsService
	 */
	private function settings(array $config = [], ?object $objectService = null): SettingsService {
		$values = $config + ['register' => 'dossiq', 'case_schema' => 'case', 'termijn_instance_schema' => 'deadlineInstance'];
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($values[$key] ?? $default)
		);
		$settings->method('getObjectService')->willReturn($objectService);

		return $settings;
	}//end settings()

	/**
	 * An object service that refuses every read and counts them.
	 *
	 * @return object
	 */
	private function refusingService(): object {
		return new class {
			/**
			 * The reads attempted.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Refuse a slug search.
			 *
			 * @param string               $register Register slug.
			 * @param string               $schema   Schema slug.
			 * @param array<string, mixed> $filters  Filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->calls++;
				throw new RuntimeException('read refused');
			}
		};
	}//end refusingService()

	/**
	 * The listener over a settings double.
	 *
	 * @param SettingsService $settings The settings.
	 *
	 * @return CaseDeleteGuardListener
	 */
	private function listener(SettingsService $settings): CaseDeleteGuardListener {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new CaseDeleteGuardListener(settingsService: $settings, l10n: $l10n, logger: $this->logger);
	}//end listener()
}//end class
