<?php

/**
 * MentionNotificationService Unit Tests
 *
 * Tests for the Dossiq MentionNotificationService that turns a saved
 * note's `@mention` tokens (nc-vue #207) into Nextcloud notifications.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ncvue-w2-leaves-adoption/specs/ncvue-w2-leaves-adoption/spec.md#REQ-W2L-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\MentionNotificationService;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the MentionNotificationService class.
 *
 * @covers \OCA\Dossiq\Service\MentionNotificationService
 */
class MentionNotificationServiceTest extends TestCase {

	/**
	 * The mocked Nextcloud notification manager.
	 *
	 * @var IManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IManager $notificationManager;

	/**
	 * The mocked logger interface.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The service under test.
	 *
	 * @var MentionNotificationService
	 */
	private MentionNotificationService $service;

	/**
	 * The mocked notification instance.
	 *
	 * @var INotification|\PHPUnit\Framework\MockObject\MockObject
	 */
	private INotification $notification;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->notificationManager = $this->createMock(IManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->notification = $this->createMock(INotification::class);

		$this->notification->method('setApp')->willReturn($this->notification);
		$this->notification->method('setUser')->willReturn($this->notification);
		$this->notification->method('setDateTime')->willReturn($this->notification);
		$this->notification->method('setObject')->willReturn($this->notification);
		$this->notification->method('setSubject')->willReturn($this->notification);

		$this->notificationManager
			->method('createNotification')
			->willReturn($this->notification);

		$this->service = new MentionNotificationService(
			$this->notificationManager,
			$this->logger,
		);
	}//end setUp()

	/**
	 * Every mentioned user gets one notification.
	 *
	 * @return void
	 */
	public function testNotifiesEveryMentionedUser(): void {
		$this->notificationManager
			->expects($this->exactly(2))
			->method('notify');

		$notified = $this->service->notifyMention(
			actorUserId: 'alice',
			actorDisplayName: 'Alice',
			objectId: 'case-1',
			register: 'procest',
			schema: 'case',
			noteId: 'note-9',
			mentionedUserIds: ['bob', 'carol'],
		);

		$this->assertSame(2, $notified);
	}//end testNotifiesEveryMentionedUser()

	/**
	 * The note author is never notified about their own mention.
	 *
	 * @return void
	 */
	public function testSkipsSelfMention(): void {
		$this->notificationManager
			->expects($this->once())
			->method('notify');

		$notified = $this->service->notifyMention(
			actorUserId: 'alice',
			actorDisplayName: 'Alice',
			objectId: 'case-1',
			register: 'procest',
			schema: 'case',
			noteId: 'note-9',
			mentionedUserIds: ['alice', 'bob'],
		);

		$this->assertSame(1, $notified);
	}//end testSkipsSelfMention()

	/**
	 * Duplicate mentions of the same user only send one notification.
	 *
	 * @return void
	 */
	public function testDeduplicatesRepeatedMentions(): void {
		$this->notificationManager
			->expects($this->once())
			->method('notify');

		$notified = $this->service->notifyMention(
			actorUserId: 'alice',
			actorDisplayName: 'Alice',
			objectId: 'case-1',
			register: 'procest',
			schema: 'case',
			noteId: 'note-9',
			mentionedUserIds: ['bob', 'bob'],
		);

		$this->assertSame(1, $notified);
	}//end testDeduplicatesRepeatedMentions()

	/**
	 * The notification is addressed to the mentioned user, uses the app id
	 * and carries the note_mention subject with the actor + object context.
	 *
	 * @return void
	 */
	public function testNotificationShapeIsCorrect(): void {
		$this->notification
			->expects($this->once())
			->method('setApp')
			->with(Application::APP_ID)
			->willReturn($this->notification);

		$this->notification
			->expects($this->once())
			->method('setUser')
			->with('bob')
			->willReturn($this->notification);

		$this->notification
			->expects($this->once())
			->method('setObject')
			->with('case', 'case-1')
			->willReturn($this->notification);

		$this->notification
			->expects($this->once())
			->method('setSubject')
			->with(
				'note_mention',
				$this->callback(
					function (array $params): bool {
						return $params['actorUserId'] === 'alice'
							&& $params['actorDisplayName'] === 'Alice'
							&& $params['register'] === 'procest'
							&& $params['schema'] === 'case'
							&& $params['objectId'] === 'case-1'
							&& $params['noteId'] === 'note-9';
					}
				)
			)
			->willReturn($this->notification);

		$this->service->notifyMention(
			actorUserId: 'alice',
			actorDisplayName: 'Alice',
			objectId: 'case-1',
			register: 'procest',
			schema: 'case',
			noteId: 'note-9',
			mentionedUserIds: ['bob'],
		);
	}//end testNotificationShapeIsCorrect()

	/**
	 * A missing schema falls back to 'note' as the notification object type.
	 *
	 * @return void
	 */
	public function testFallsBackToNoteObjectTypeWhenSchemaMissing(): void {
		$this->notification
			->expects($this->once())
			->method('setObject')
			->with('note', 'case-1')
			->willReturn($this->notification);

		$this->service->notifyMention(
			actorUserId: 'alice',
			actorDisplayName: 'Alice',
			objectId: 'case-1',
			register: '',
			schema: '',
			noteId: 'note-9',
			mentionedUserIds: ['bob'],
		);
	}//end testFallsBackToNoteObjectTypeWhenSchemaMissing()

	/**
	 * A per-recipient exception is caught, logged, and does not stop the
	 * remaining recipients from being notified.
	 *
	 * @return void
	 */
	public function testExceptionForOneRecipientIsCaughtAndOthersStillNotified(): void {
		$calls = 0;
		$this->notificationManager
			->method('notify')
			->willReturnCallback(
				function () use (&$calls): void {
					$calls++;
					if ($calls === 1) {
						throw new \RuntimeException('Notification service unavailable');
					}
				}
			);

		$this->logger
			->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('Failed to send note mention notification'),
				$this->anything()
			);

		$notified = $this->service->notifyMention(
			actorUserId: 'alice',
			actorDisplayName: 'Alice',
			objectId: 'case-1',
			register: 'procest',
			schema: 'case',
			noteId: 'note-9',
			mentionedUserIds: ['bob', 'carol'],
		);

		$this->assertSame(1, $notified);
	}//end testExceptionForOneRecipientIsCaughtAndOthersStillNotified()
}//end class
