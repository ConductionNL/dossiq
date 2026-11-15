<?php

/**
 * The paths of the intake term-start listener that write nothing, or fail softly.
 *
 * The behaviour tests drive the listener with the real intake classes; these
 * drive it with the contracts mocked, so each early return and each caught
 * failure is reached on its own and its outcome asserted: no write, the write
 * with only the added fields, or the log line that says why nothing landed.
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
 * @spec openspec/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Kernel\Contracts\CaseReceiptDatesInterface;
use OCA\Dossiq\Kernel\Contracts\IntakeTermStartInterface;
use OCA\Dossiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\IntakeTermStartListener;
use OCA\Dossiq\Listener\ReceiptClock;
use OCA\Dossiq\Service\Intake\ReceiptMoment;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The quiet paths of IntakeTermStartListener.
 *
 * @covers \OCA\Dossiq\Listener\IntakeTermStartListener
 * @uses   \OCA\Dossiq\Listener\ReceiptClock
 * @uses   \OCA\Dossiq\Service\Intake\ReceiptMoment
 * @uses   \OCA\Dossiq\Service\Support\SerialisedResult
 *
 * @spec openspec/specs/burger-notifications/spec.md
 */
class IntakeTermStartListenerEdgesTest extends TestCase {

	/**
	 * The settings bridge.
	 *
	 * @var SettingsServiceInterface&MockObject
	 */
	private SettingsServiceInterface&MockObject $settings;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the mocks every test shares.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A created case without any id is not written: there is nothing to address.
	 *
	 * @return void
	 */
	public function testACreatedCaseWithoutAnIdIsNotWritten(): void {
		$this->settings->expects(self::never())->method('getObjectService');

		$this->listener()->handle(new ObjectCreatedEvent(self::entity(uuid: null)));
	}//end testACreatedCaseWithoutAnIdIsNotWritten()

	/**
	 * A case being created that cannot be serialised is logged and left as it is.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseBeingCreatedIsLoggedAndLeftAlone(): void {
		$entity = new class extends ObjectEntity {
			/**
			 * Fail like a broken entity does.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				throw new RuntimeException('broken entity');
			}//end jsonSerialize()
		};
		$entity->setObject(['title' => 'Kosten zwembad']);

		$this->logger->expects(self::once())->method('debug')->with(
			'Dossiq intake: a case being created could not be read',
			['exception' => 'broken entity'],
		);

		$this->listener()->handle(new ObjectCreatingEvent($entity));

		self::assertSame(['id' => null, 'title' => 'Kosten zwembad'], $entity->getObject());
	}//end testAnUnreadableCaseBeingCreatedIsLoggedAndLeftAlone()

	/**
	 * A case being created that already carries everything is stored unchanged.
	 *
	 * @return void
	 */
	public function testACaseBeingCreatedWithNothingMissingIsUnchanged(): void {
		$entity = self::entity(uuid: 'case-1');

		$this->listener(stamped: true)->handle(new ObjectCreatingEvent($entity));

		self::assertSame(['id' => 'case-1', 'title' => 'Kosten zwembad'], $entity->getObject());
	}//end testACaseBeingCreatedWithNothingMissingIsUnchanged()

	/**
	 * A case being created gets the stamp merged over its own fields.
	 *
	 * @return void
	 */
	public function testACaseBeingCreatedGetsTheStampMergedIn(): void {
		$entity = self::entity(uuid: 'case-1');

		$this->listener()->handle(new ObjectCreatingEvent($entity));

		self::assertSame(
			['id' => 'case-1', 'title' => 'Kosten zwembad', 'termStartsAt' => '2026-10-12T08:30:00+02:00', 'startDate' => '2026-10-12'],
			$entity->getObject()
		);
	}//end testACaseBeingCreatedGetsTheStampMergedIn()

	/**
	 * Without an object service the created fallback writes nothing and logs nothing.
	 *
	 * @return void
	 */
	public function testWithoutAnObjectServiceTheFallbackWritesNothing(): void {
		$this->settings->method('getObjectService')->willReturn(null);
		$this->settings->method('getConfigValue')->willReturnMap([['register', '', 'dossiq'], ['case_schema', '', 'case']]);
		$this->logger->expects(self::never())->method('warning');

		$this->listener()->handle(new ObjectCreatedEvent(self::entity(uuid: 'case-1')));
	}//end testWithoutAnObjectServiceTheFallbackWritesNothing()

	/**
	 * Without a configured case schema the created fallback writes nothing.
	 *
	 * @return void
	 */
	public function testWithoutACaseSchemaTheFallbackWritesNothing(): void {
		$service = new class {
			/**
			 * Every write it was asked for.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $writes = [];

			/**
			 * Record a partial write.
			 *
			 * @param string               $objectId The uuid.
			 * @param array<string, mixed> $data     The fields.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> The fields.
			 */
			public function patchObject(string $objectId, array $data, string $register, string $schema): array {
				$this->writes[] = $data;
				return $data;
			}//end patchObject()
		};
		$this->settings->method('getObjectService')->willReturn($service);
		$this->settings->method('getConfigValue')->willReturnMap([['register', '', 'dossiq'], ['case_schema', '', '']]);

		$this->listener()->handle(new ObjectCreatedEvent(self::entity(uuid: 'case-1')));

		self::assertSame([], $service->writes);
	}//end testWithoutACaseSchemaTheFallbackWritesNothing()

	/**
	 * A store that refuses the write is logged with the case and the reason.
	 *
	 * @return void
	 */
	public function testARefusedWriteIsLoggedWithTheCase(): void {
		$service = new class {
			/**
			 * Refuse every partial write.
			 *
			 * @param string               $objectId The uuid.
			 * @param array<string, mixed> $data     The fields.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> Never.
			 */
			public function patchObject(string $objectId, array $data, string $register, string $schema): array {
				throw new RuntimeException('store refused');
			}//end patchObject()
		};
		$this->settings->method('getObjectService')->willReturn($service);
		$this->settings->method('getConfigValue')->willReturnMap([['register', '', 'dossiq'], ['case_schema', '', 'case']]);

		$this->logger->expects(self::once())->method('warning')->with(
			'Dossiq intake: the term start could not be written onto the case',
			['case' => 'case-1', 'exception' => 'store refused'],
		);

		$this->listener()->handle(new ObjectCreatedEvent(self::entity(uuid: 'case-1')));
	}//end testARefusedWriteIsLoggedWithTheCase()

	/**
	 * The listener, with the intake contracts answering fixed values.
	 *
	 * @param bool $stamped Whether the case already carries its stamp and dates.
	 *
	 * @return IntakeTermStartListener The listener.
	 */
	private function listener(bool $stamped = false): IntakeTermStartListener {
		$intake = $this->createMock(IntakeTermStartInterface::class);
		$intake->method('isStamped')->willReturn($stamped);
		$intake->method('stampForCase')->willReturn([IntakeTermStartInterface::TERM_STARTS_AT => '2026-10-12T08:30:00+02:00']);

		$dates = $this->createMock(CaseReceiptDatesInterface::class);
		$dates->method('changesFor')->willReturnCallback(
			static fn (array $case, string $termStartsAt = ''): array => ($stamped === true) ? [] : [CaseReceiptDatesInterface::START_DATE => substr($termStartsAt, 0, 10)]
		);

		$slugs = $this->createMock(ObjectSchemaSlugResolverInterface::class);
		$slugs->method('resolveFromPayload')->willReturn('case');

		return new IntakeTermStartListener(
			intake: $intake,
			slugResolver: $slugs,
			settingsService: $this->settings,
			logger: $this->logger,
			clock: new ReceiptClock(
				receiptDates: $dates,
				receipt: new ReceiptMoment(),
				time: $this->createMock(ITimeFactory::class),
			),
		);
	}//end listener()

	/**
	 * A non-draft case entity.
	 *
	 * @param string|null $uuid The uuid, or null for none.
	 *
	 * @return ObjectEntity The entity.
	 */
	private static function entity(?string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		if ($uuid !== null) {
			$entity->setUuid($uuid);
		}

		$entity->setSchema('case');
		$entity->setObject(['title' => 'Kosten zwembad']);

		return $entity;
	}//end entity()
}//end class
