<?php

/**
 * CasePriorityDerivationListener Unit Tests
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

use OCA\Dossiq\Kernel\Contracts\CasePriorityServiceInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\CasePriorityDerivationListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A case being saved gets its derived priority written into the save, an
 * override that changes is stamped with who and when, a cleared override
 * clears its stamp, and anything that is not a readable case is left alone.
 *
 * @covers \OCA\Dossiq\Listener\CasePriorityDerivationListener
 * @uses   \OCA\Dossiq\Listener\Support\SchemaMembership
 */
class CasePriorityDerivationListenerTest extends TestCase {

	/**
	 * The case schema id.
	 *
	 * @var string
	 */
	private const SCHEMA = '41';

	/**
	 * The mocked settings.
	 *
	 * @var SettingsServiceInterface&MockObject
	 */
	private SettingsServiceInterface $settings;

	/**
	 * The mocked priority service.
	 *
	 * @var CasePriorityServiceInterface&MockObject
	 */
	private CasePriorityServiceInterface $priority;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsServiceInterface::class);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'case_schema' ? self::SCHEMA : $default)
		);
		$this->priority = $this->createMock(CasePriorityServiceInterface::class);
		$this->session  = $this->createMock(IUserSession::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The listener under test.
	 *
	 * @return CasePriorityDerivationListener The listener.
	 */
	private function listener(): CasePriorityDerivationListener {
		return new CasePriorityDerivationListener(
			settingsService: $this->settings,
			priorityService: $this->priority,
			userSession: $this->session,
			logger: $this->logger
		);
	}//end listener()

	/**
	 * A case entity.
	 *
	 * @param array<string, mixed> $payload The case fields.
	 * @param string               $schema  The schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $payload, string $schema = self::SCHEMA): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema($schema);
		$entity->setUuid('case-1');

		return $entity;
	}//end entity()

	/**
	 * An entity whose payload cannot be read.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function unreadable(): ObjectEntity {
		return new class extends ObjectEntity {
			/**
			 * Fail to serialise.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				throw new RuntimeException('corrupt row');
			}
		};
	}//end unreadable()

	/**
	 * Sign a user in.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * A new case's derived priority is added to the data the hook already modified,
	 * and the derivation sees that modified data.
	 *
	 * @return void
	 */
	public function testANewCaseGetsItsDerivedPriority(): void {
		$event = new ObjectCreatingEvent($this->entity(['impact' => 'low', 'urgency' => 'low']));
		$event->setModifiedData(['urgency' => 'high']);
		$this->priority->expects($this->once())->method('resolve')
			->with($this->callback(static fn (array $case): bool => $case['impact'] === 'low' && $case['urgency'] === 'high' && $case['@self']['schema'] === self::SCHEMA))
			->willReturn(['priority' => 'normal']);

		$this->listener()->handle($event);

		$this->assertSame(['urgency' => 'high', 'priority' => 'normal'], $event->getModifiedData());
	}//end testANewCaseGetsItsDerivedPriority()

	/**
	 * An object of another schema is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->entity(['impact' => 'low'], schema: '99'));
		$this->priority->expects($this->never())->method('resolve');

		$this->listener()->handle($event);

		$this->assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * A payload that cannot be read is logged at debug level and left alone.
	 *
	 * @return void
	 */
	public function testAnUnreadablePayloadIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->unreadable());
		$this->priority->expects($this->never())->method('resolve');
		$this->logger->expects($this->once())->method('debug')
			->with('Dossiq: priority derivation could not read the payload: corrupt row');

		$this->listener()->handle($event);

		$this->assertSame([], $event->getModifiedData());
	}//end testAnUnreadablePayloadIsLeftAlone()

	/**
	 * A newly set override is stamped with the signed-in user and the moment.
	 *
	 * @return void
	 */
	public function testANewOverrideIsStampedWithWhoAndWhen(): void {
		$this->signIn(uid: 'alice');
		$event = new ObjectUpdatingEvent(
			$this->entity(['priorityOverride' => 'urgent']),
			$this->entity(['priorityOverride' => ''])
		);
		$seen = null;
		$this->priority->method('resolve')->willReturnCallback(
			static function (array $case) use (&$seen): array {
				$seen = $case;
				return ['priority' => 'urgent'];
			}
		);

		$this->listener()->handle($event);

		$modified = $event->getModifiedData();
		$this->assertSame(['priorityOverrideBy', 'priorityOverrideAt', 'priority'], array_keys($modified));
		$this->assertSame('alice', $modified['priorityOverrideBy']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $modified['priorityOverrideAt']);
		$this->assertSame('alice', $seen['priorityOverrideBy']);
	}//end testANewOverrideIsStampedWithWhoAndWhen()

	/**
	 * Without a signed-in user the override is stamped as the system's.
	 *
	 * @return void
	 */
	public function testAnOverrideWithoutAUserIsStampedAsTheSystem(): void {
		$this->session->method('getUser')->willReturn(null);
		$event = new ObjectCreatingEvent($this->entity(['priorityOverride' => 'high']));
		$this->priority->method('resolve')->willReturn([]);

		$this->listener()->handle($event);

		$this->assertSame('system', $event->getModifiedData()['priorityOverrideBy']);
	}//end testAnOverrideWithoutAUserIsStampedAsTheSystem()

	/**
	 * Clearing the override clears who set it, when and why.
	 *
	 * @return void
	 */
	public function testAClearedOverrideClearsItsStamp(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['priorityOverride' => '  ']),
			$this->entity(['priorityOverride' => 'urgent'])
		);
		$this->priority->method('resolve')->willReturn(['priority' => 'high']);

		$this->listener()->handle($event);

		$this->assertSame(
			['priorityOverrideBy' => null, 'priorityOverrideAt' => null, 'priorityOverrideReason' => null, 'priority' => 'high'],
			$event->getModifiedData()
		);
	}//end testAClearedOverrideClearsItsStamp()

	/**
	 * An override that did not change is not stamped again.
	 *
	 * @return void
	 */
	public function testAnUnchangedOverrideIsNotStampedAgain(): void {
		$this->session->expects($this->never())->method('getUser');
		$event = new ObjectUpdatingEvent(
			$this->entity(['priorityOverride' => 'urgent']),
			$this->entity(['priorityOverride' => ' urgent '])
		);
		$this->priority->method('resolve')->willReturn(['priority' => 'urgent']);

		$this->listener()->handle($event);

		$this->assertSame(['priority' => 'urgent'], $event->getModifiedData());
	}//end testAnUnchangedOverrideIsNotStampedAgain()

	/**
	 * A stored version that cannot be read counts as having no override.
	 *
	 * @return void
	 */
	public function testAnUnreadablePreviousVersionCountsAsNoOverride(): void {
		$this->signIn(uid: 'bob');
		$event = new ObjectUpdatingEvent($this->entity(['priorityOverride' => 'low']), $this->unreadable());
		$this->priority->method('resolve')->willReturn([]);

		$this->listener()->handle($event);

		$this->assertSame('bob', $event->getModifiedData()['priorityOverrideBy']);
	}//end testAnUnreadablePreviousVersionCountsAsNoOverride()

	/**
	 * Without a configured case schema nothing is derived.
	 *
	 * @return void
	 */
	public function testWithoutACaseSchemaNothingIsDerived(): void {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getConfigValue')->willReturn('');
		$this->priority->expects($this->never())->method('resolve');
		$event = new ObjectCreatingEvent($this->entity(['impact' => 'high']));

		(new CasePriorityDerivationListener(
			settingsService: $settings,
			priorityService: $this->priority,
			userSession: $this->session,
			logger: $this->logger
		))->handle($event);

		$this->assertSame([], $event->getModifiedData());
	}//end testWithoutACaseSchemaNothingIsDerived()
}//end class
