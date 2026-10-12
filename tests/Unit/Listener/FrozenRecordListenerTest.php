<?php

/**
 * FrozenRecordListener unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
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
 *
 * @spec openspec/specs/frozen-record/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\AppInfo\Registrar\ImmutabilityListenerRegistrar;
use OCA\Dossiq\Listener\FrozenRecordListener;
use OCA\Dossiq\Service\FrozenRecord\FreezeRegistry;
use OCA\Dossiq\Service\FrozenRecord\FrozenRecordGuard;
use OCA\Dossiq\Service\FrozenRecord\RecordSeal;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Unit\Service\FrozenRecord\FrozenRecordFixture;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The listener on OpenRegister's pre-persist events, on the neutral fixture record kind.
 *
 * @covers \OCA\Dossiq\Listener\FrozenRecordListener
 * @uses   \OCA\Dossiq\Service\FrozenRecord\FreezeRegistry
 * @uses   \OCA\Dossiq\Service\FrozenRecord\FreezeDefinition
 * @uses   \OCA\Dossiq\Service\FrozenRecord\FrozenRecordGuard
 * @uses   \OCA\Dossiq\Service\FrozenRecord\RecordSeal
 * @uses   \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\AppInfo\Registrar\ImmutabilityListenerRegistrar
 */
class FrozenRecordListenerTest extends TestCase {

	/**
	 * The listener with the fixture entry configured.
	 *
	 * @return FrozenRecordListener The listener.
	 */
	private function listener(): FrozenRecordListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => $key === FreezeRegistry::CONFIG_KEY ? (string)json_encode([FrozenRecordFixture::ENTRY]) : ''
		);
		$logger = $this->createMock(LoggerInterface::class);

		return new FrozenRecordListener(
			registry: new FreezeRegistry(settingsService: $settings, logger: $logger),
			guard: new FrozenRecordGuard(),
			seal: new RecordSeal(),
			logger: $logger,
		);
	}//end listener()

	/**
	 * An entity carrying a fixture record.
	 *
	 * @param array<string, mixed> $record The record payload.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $record): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('rec-1');
		$entity->setSchema((string)($record['@self']['schema'] ?? ''));
		if (empty($record['@self']['deleted']) === false) {
			$entity->setDeleted($record['@self']['deleted']);
		}

		unset($record['@self']);
		$entity->setObject($record);

		return $entity;
	}//end entity()

	/**
	 * A frozen field moved through any door is stopped, with the rule.
	 *
	 * @return void
	 */
	public function testRefusesAFrozenEdit(): void {
		$event = new ObjectUpdatingEvent(
			newObject: $this->entity(record: FrozenRecordFixture::record(status: 'issued', extra: ['body' => 'Changed'])),
			oldObject: $this->entity(record: FrozenRecordFixture::record(status: 'issued')),
		);

		$this->listener()->handle(event: $event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('record-frozen', $event->getErrors()['error']);
	}//end testRefusesAFrozenEdit()

	/**
	 * The write that freezes the record carries the seal, beside what other listeners modified.
	 *
	 * @return void
	 */
	public function testSealsOnTheFreezingWrite(): void {
		$event = new ObjectUpdatingEvent(
			newObject: $this->entity(record: FrozenRecordFixture::record(status: 'issued')),
			oldObject: $this->entity(record: FrozenRecordFixture::record(status: 'draft')),
		);
		$event->setModifiedData(['stampedBy' => 'another listener']);

		$this->listener()->handle(event: $event);

		self::assertFalse($event->isPropagationStopped());
		$modified = $event->getModifiedData();
		self::assertSame('another listener', $modified['stampedBy']);
		self::assertTrue((new RecordSeal())->verify(record: FrozenRecordFixture::record(status: 'issued'), seal: $modified['seal']));
	}//end testSealsOnTheFreezingWrite()

	/**
	 * A later write on a frozen record is not sealed again.
	 *
	 * @return void
	 */
	public function testDoesNotResealLaterWrites(): void {
		$event = new ObjectUpdatingEvent(
			newObject: $this->entity(record: FrozenRecordFixture::record(status: 'archived')),
			oldObject: $this->entity(record: FrozenRecordFixture::record(status: 'issued')),
		);

		$this->listener()->handle(event: $event);

		self::assertSame([], $event->getModifiedData());
		self::assertFalse($event->isPropagationStopped());
	}//end testDoesNotResealLaterWrites()

	/**
	 * A permanent delete of a frozen record is stopped.
	 *
	 * @return void
	 */
	public function testRefusesAFrozenDelete(): void {
		$event = new ObjectDeletingEvent(object: $this->entity(record: FrozenRecordFixture::record(status: 'issued')));

		$this->listener()->handle(event: $event);

		self::assertTrue($event->isPropagationStopped());
	}//end testRefusesAFrozenDelete()

	/**
	 * A record no entry covers is left alone.
	 *
	 * @return void
	 */
	public function testIgnoresUnconfiguredRecords(): void {
		$other = FrozenRecordFixture::record(status: 'issued', extra: ['@self' => ['schema' => 'otherRecord']]);
		$event = new ObjectDeletingEvent(object: $this->entity(record: $other));

		$this->listener()->handle(event: $event);

		self::assertFalse($event->isPropagationStopped());
	}//end testIgnoresUnconfiguredRecords()

	/**
	 * The registrar wires the listener to both events: the capability has a call site.
	 *
	 * @return void
	 */
	public function testRegistrarWiresTheListener(): void {
		$wired = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$wired): void {
				$wired[] = $event . ' => ' . $listener;
			}
		);

		(new ImmutabilityListenerRegistrar())->register(context: $context);

		self::assertContains(ObjectUpdatingEvent::class . ' => ' . FrozenRecordListener::class, $wired);
		self::assertContains(ObjectDeletingEvent::class . ' => ' . FrozenRecordListener::class, $wired);
	}//end testRegistrarWiresTheListener()
}//end class
