<?php

/**
 * Golden test for the note mention route, end to end over the real service.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\NotesController;
use OCA\Dossiq\Service\MentionNotificationService;
use OCA\Dossiq\Service\Notes\NotePush;
use OCA\Dossiq\Service\Queue\MentionQueueRecords;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins who is notified of a mention, as whom, about what, and what is queued.
 *
 * The acting user is the session's. A request that names another actor, in
 * any field, changes nothing about who the notification says wrote the note.
 *
 * @covers \OCA\Dossiq\Controller\NotesController
 * @covers \OCA\Dossiq\Service\MentionNotificationService
 * @covers \OCA\Dossiq\Service\Notes\MentionedNote
 * @covers \OCA\Dossiq\Service\Notes\NoteMention
 */
class NoteMentionGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Every notification sent, as the setters built it.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * Every queue record written.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $queued = [];

	/**
	 * What was set on each notification, by object id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $builds = [];

	/**
	 * Every warning logged.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $warnings = [];

	/**
	 * One mention request.
	 *
	 * @param array<string, mixed> $body     The request body.
	 * @param string|null          $uid      The session user, or null when nobody is signed in.
	 * @param array<int, string>   $failFor  Recipients whose notification throws.
	 *
	 * @return array<string, mixed> The response, the notifications, the queue records and the warnings.
	 */
	private function mention(array $body, ?string $uid, array $failFor = []): array {
		$this->sent = [];
		$this->queued = [];
		$this->warnings = [];

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(fn (): INotification => $this->recordingNotification());
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification) use ($failFor): void {
				$built = $this->builds[spl_object_id($notification)];
				if (in_array($built['user'], $failFor, true) === true) {
					throw new \RuntimeException('bell broke for ' . $built['user']);
				}

				$this->sent[] = $built;
			}
		);

		$queue = $this->getMockBuilder(MentionQueueRecords::class)->disableOriginalConstructor()->onlyMethods(['record'])->getMock();
		$queue->method('record')->willReturnCallback(
			function (string $person, string $actor, string $subjectType, string $subjectId, string $noteId): bool {
				$this->queued[] = ['person' => $person, 'actor' => $actor, 'subjectType' => $subjectType, 'subjectId' => $subjectId, 'noteId' => $noteId];
				return true;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn(($uid === 'alice' ? 'Alice Jansen' : ''));
		}

		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($body);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($body[$key] ?? $default));

		$caseAccessGuard = $this->getMockBuilder(className: CaseAccessGuard::class)
			->disableOriginalConstructor()
			->onlyMethods(methods: ['hasCaseMutationAccess', 'hasCaseReadAccess'])
			->getMock();
		$caseAccessGuard->method('hasCaseReadAccess')->willReturn(value: true);
		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('get')->willReturnCallback(fn (string $uid): IUser => $this->createMock(originalClassName: IUser::class));

		$controller = new NotesController(
			request: $request,
			mentionSvc: new MentionNotificationService($manager, $queue, $logger),
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
			notePush: $this->getMockBuilder(NotePush::class)->disableOriginalConstructor()->onlyMethods(['push'])->getMock(),
			caseAccessGuard: $caseAccessGuard,
			userManager: $userManager,
		);

		$response = $controller->mention();

		return ['status' => $response->getStatus(), 'data' => $response->getData(), 'sent' => $this->sent, 'queued' => $this->queued, 'warnings' => $this->warnings];
	}//end mention()

	/**
	 * A notification double that remembers what was set on it.
	 *
	 * @return INotification The double.
	 */
	private function recordingNotification(): INotification {
		$notification = $this->createMock(INotification::class);
		$key = spl_object_id($notification);
		$this->builds[$key] = ['app' => null, 'user' => null, 'object' => null, 'subject' => null, 'dated' => false];
		$notification->method('setApp')->willReturnCallback(function (string $app) use ($notification, $key): INotification {
			$this->builds[$key]['app'] = $app;
			return $notification;
		});
		$notification->method('setUser')->willReturnCallback(function (string $user) use ($notification, $key): INotification {
			$this->builds[$key]['user'] = $user;
			return $notification;
		});
		$notification->method('setDateTime')->willReturnCallback(function (\DateTime $date) use ($notification, $key): INotification {
			$this->builds[$key]['dated'] = true;
			return $notification;
		});
		$notification->method('setObject')->willReturnCallback(function (string $type, string $id) use ($notification, $key): INotification {
			$this->builds[$key]['object'] = [$type, $id];
			return $notification;
		});
		$notification->method('setSubject')->willReturnCallback(function (string $subject, array $parameters = []) use ($notification, $key): INotification {
			$this->builds[$key]['subject'] = [$subject, $parameters];
			return $notification;
		});

		return $notification;
	}//end recordingNotification()

	/**
	 * Every way a mention request can go, pinned whole.
	 *
	 * @return void
	 */
	public function testGoldenMentions(): void {
		$full = ['objectId' => 'case-1', 'register' => 'dossiq', 'schema' => 'case', 'noteId' => 'n-9', 'mentionedUserIds' => ['bob', 'carol', 'bob', 'alice', '', 7]];
		$forged = $full + ['actorUserId' => 'mallory', 'actorDisplayName' => 'Mallory', 'userId' => 'mallory', 'requestedBy' => 'mallory', 'owner' => 'mallory'];

		$world = [
			'full' => $this->mention(body: $full, uid: 'alice'),
			'forged actor in the request' => $this->mention(body: $forged, uid: 'alice'),
			'no display name' => $this->mention(body: $full, uid: 'dave'),
			'no schema' => $this->mention(body: (['schema' => ''] + $full), uid: 'alice'),
			'no register or note id' => $this->mention(body: ['objectId' => 'case-1', 'mentionedUserIds' => ['bob']], uid: 'alice'),
			'one bell breaks' => $this->mention(body: $full, uid: 'alice', failFor: ['bob']),
			'only self' => $this->mention(body: (['mentionedUserIds' => ['alice', 'alice']] + $full), uid: 'alice'),
			'no recipients' => $this->mention(body: (['mentionedUserIds' => []] + $full), uid: 'alice'),
			'recipients not a list' => $this->mention(body: (['mentionedUserIds' => 'bob'] + $full), uid: 'alice'),
			'no object' => $this->mention(body: (['objectId' => ''] + $full), uid: 'alice'),
			'signed out' => $this->mention(body: $full, uid: null),
		];

		$this->assertMatchesGolden('note-mentions', $world);
	}//end testGoldenMentions()
}//end class
