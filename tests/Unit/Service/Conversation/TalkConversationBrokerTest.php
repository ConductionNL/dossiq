<?php

/**
 * Tests for the Talk conversation broker.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Conversation
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Conversation;


use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Conversation\TalkConversationBroker;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Talk\IBroker;
use OCP\Talk\IConversation;
use OCP\Talk\IConversationOptions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The broker opens and closes Talk rooms, and tells absent Talk from failing Talk.
 *
 * @covers \OCA\Dossiq\Service\Conversation\TalkConversationBroker
 * @uses   \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/specs/case-management/spec.md
 */
class TalkConversationBrokerTest extends TestCase {

	/**
	 * The server container.
	 *
	 * @var IServerContainer|MockObject
	 */
	private IServerContainer $container;

	/**
	 * The user manager.
	 *
	 * @var IUserManager|MockObject
	 */
	private IUserManager $userManager;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The Talk broker.
	 *
	 * @var IBroker|MockObject
	 */
	private IBroker $talk;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(IServerContainer::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->talk = $this->createMock(IBroker::class);
	}//end setUp()

	/**
	 * The broker under test.
	 *
	 * @return TalkConversationBroker
	 */
	private function broker(): TalkConversationBroker {
		return new TalkConversationBroker(
			container: $this->container,
			userManager: $this->userManager,
			logger: $this->logger,
		);
	}//end broker()

	/**
	 * Make the container hold the Talk broker, with or without a backend.
	 *
	 * @param bool $backend Whether Talk answers hasBackend() true.
	 *
	 * @return void
	 */
	private function withTalk(bool $backend = true): void {
		$this->container->method('has')->with(IBroker::class)->willReturn(true);
		$this->container->method('get')->with(IBroker::class)->willReturn($this->talk);
		$this->talk->method('hasBackend')->willReturn($backend);
	}//end withTalk()

	/**
	 * Without the broker in the container, there is no Talk and nothing is asked of it.
	 *
	 * @return void
	 */
	public function testAContainerWithoutTalkIsUnavailable(): void {
		$this->container->expects($this->once())->method('has')->with(IBroker::class)->willReturn(false);
		$this->container->expects($this->never())->method('get');

		$broker = $this->broker();

		$this->assertFalse($broker->isAvailable());
	}//end testAContainerWithoutTalkIsUnavailable()

	/**
	 * A container entry that is not a Talk broker counts as no Talk.
	 *
	 * @return void
	 */
	public function testAContainerEntryThatIsNotABrokerIsUnavailable(): void {
		$this->container->method('has')->willReturn(true);
		$this->container->method('get')->willReturn(new \stdClass());

		$this->assertFalse($this->broker()->isAvailable());
		$this->assertNull($this->broker()->createRoom(name: 'Zaak'));
		$this->assertFalse($this->broker()->closeRoom(roomId: 'room-1'));
	}//end testAContainerEntryThatIsNotABrokerIsUnavailable()

	/**
	 * Talk installed without a backend is unavailable.
	 *
	 * @return void
	 */
	public function testTalkWithoutABackendIsUnavailable(): void {
		$this->withTalk(backend: false);

		$this->assertFalse($this->broker()->isAvailable());
	}//end testTalkWithoutABackendIsUnavailable()

	/**
	 * Talk with a backend is available.
	 *
	 * @return void
	 */
	public function testTalkWithABackendIsAvailable(): void {
		$this->withTalk();

		$this->assertTrue($this->broker()->isAvailable());
	}//end testTalkWithABackendIsAvailable()

	/**
	 * Without Talk no room is opened, and the answer is null rather than a refusal.
	 *
	 * @return void
	 */
	public function testCreateRoomWithoutTalkAnswersNull(): void {
		$this->container->method('has')->willReturn(false);
		$this->talk->expects($this->never())->method('createConversation');

		$this->assertNull($this->broker()->createRoom(name: 'Zaak ZAAK-1', moderators: ['anna']));
	}//end testCreateRoomWithoutTalkAnswersNull()

	/**
	 * A room is opened under the given name, with only the moderators that exist.
	 *
	 * @return void
	 */
	public function testCreateRoomPassesTheNameAndOnlyExistingModerators(): void {
		$this->withTalk();

		$anna = $this->createMock(IUser::class);
		$bob = $this->createMock(IUser::class);
		$this->userManager->expects($this->exactly(3))
			->method('get')
			->willReturnCallback(
				static fn (string $uid): ?IUser => match ($uid) {
					'anna' => $anna,
					'bob' => $bob,
					default => null,
				}
			);

		$options = $this->createMock(IConversationOptions::class);
		$this->talk->method('newConversationOptions')->willReturn($options);

		$room = $this->createMock(IConversation::class);
		$room->method('getId')->willReturn('room-9');
		$room->method('getAbsoluteUrl')->willReturn('https://nc.test/call/room-9');

		$this->talk->expects($this->once())
			->method('createConversation')
			->with('Zaak ZAAK-1', [$anna, $bob], $options)
			->willReturn($room);

		$result = $this->broker()->createRoom(name: 'Zaak ZAAK-1', moderators: ['anna', 'ghost', 'bob']);

		$this->assertSame(['id' => 'room-9', 'url' => 'https://nc.test/call/room-9'], $result);
	}//end testCreateRoomPassesTheNameAndOnlyExistingModerators()

	/**
	 * Talk failing to open a room is a refusal, logged, with the failure kept as its cause.
	 *
	 * @return void
	 */
	public function testCreateRoomThatTalkRefusesThrowsARefusal(): void {
		$this->withTalk();
		$this->talk->method('newConversationOptions')->willReturn($this->createMock(IConversationOptions::class));
		$cause = new RuntimeException('quota reached');
		$this->talk->method('createConversation')->willThrowException($cause);

		$this->logger->expects($this->once())
			->method('warning')
			->with('Talk refused a room named "Zaak": quota reached', ['app' => 'dossiq']);

		try {
			$this->broker()->createRoom(name: 'Zaak');
			$this->fail('A room Talk refused was reported as opened.');
		} catch (RefusedException $e) {
			$this->assertSame('talk-room-refused', $e->getRule());
			$this->assertSame('Nextcloud Talk could not open a room for this case.', $e->getSentence());
			$this->assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
			$this->assertSame($cause, $e->getPrevious());
		}
	}//end testCreateRoomThatTalkRefusesThrowsARefusal()

	/**
	 * Without Talk a close answers false and asks nothing.
	 *
	 * @return void
	 */
	public function testCloseRoomWithoutTalkAnswersFalse(): void {
		$this->container->method('has')->willReturn(false);
		$this->talk->expects($this->never())->method('deleteConversation');

		$this->assertFalse($this->broker()->closeRoom(roomId: 'room-1'));
	}//end testCloseRoomWithoutTalkAnswersFalse()

	/**
	 * A close deletes exactly that room and answers true.
	 *
	 * @return void
	 */
	public function testCloseRoomDeletesTheRoom(): void {
		$this->withTalk();
		$this->talk->expects($this->once())->method('deleteConversation')->with('room-1');
		$this->logger->expects($this->never())->method('warning');

		$this->assertTrue($this->broker()->closeRoom(roomId: 'room-1'));
	}//end testCloseRoomDeletesTheRoom()

	/**
	 * A room Talk will not delete answers false and is logged, not thrown.
	 *
	 * @return void
	 */
	public function testCloseRoomThatTalkRefusesAnswersFalse(): void {
		$this->withTalk();
		$this->talk->method('deleteConversation')->willThrowException(new RuntimeException('gone'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('Talk refused to close room "room-1": gone', ['app' => 'dossiq']);

		$this->assertFalse($this->broker()->closeRoom(roomId: 'room-1'));
	}//end testCloseRoomThatTalkRefusesAnswersFalse()

}//end class
