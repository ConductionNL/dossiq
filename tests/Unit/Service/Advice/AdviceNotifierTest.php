<?php

/**
 * Who hears about an advice request, and with which subject.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Advice
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

namespace OCA\Dossiq\Tests\Unit\Service\Advice;

use OCA\Dossiq\Service\Advice\AdviceNotifier;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Advice\AdviceNotifier
 */
class AdviceNotifierTest extends TestCase {
	/**
	 * The notifications sent, each as the setter calls made on it.
	 *
	 * @var list<array<string, array<int, mixed>>>
	 */
	private array $sent = [];

	/**
	 * The setter calls per notification, keyed by object id.
	 *
	 * @var array<int, array<string, array<int, mixed>>>
	 */
	private array $calls = [];

	/**
	 * A notifier whose manager records every notification it is handed.
	 *
	 * @param LoggerInterface|null $logger The logger, or a silent one.
	 * @param bool                 $fail   Whether notify() throws.
	 *
	 * @return AdviceNotifier
	 */
	private function notifier(?LoggerInterface $logger = null, bool $fail = false): AdviceNotifier {
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(
			function (): INotification {
				$notification = $this->createMock(INotification::class);
				foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setMessage'] as $setter) {
					$notification->method($setter)->willReturnCallback(
						function (...$arguments) use ($setter, $notification): INotification {
							$this->calls[spl_object_id($notification)][$setter] = $arguments;
							return $notification;
						}
					);
				}

				return $notification;
			}
		);
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification) use ($fail): void {
				if ($fail === true) {
					throw new RuntimeException('push gateway down');
				}

				$calls = ($this->calls[spl_object_id($notification)] ?? []);
				unset($calls['setDateTime']);
				$this->sent[] = $calls;
			}
		);

		return new AdviceNotifier(notificationManager: $manager, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end notifier()

	/**
	 * A notification carries app, user, object and subject, and a message only when given.
	 *
	 * @return void
	 */
	public function testANotificationCarriesItsFieldsAndAnOptionalMessage(): void {
		$notifier = $this->notifier();

		$notifier->sendUserNotification(userId: 'adv', subject: 'advies_aangevraagd', objectId: 'a-1', message: 'Please advise');
		$notifier->sendUserNotification(userId: 'adv', subject: 'advies_aangevraagd', objectId: 'a-2');

		$this->assertSame(
			[
				'setApp' => ['dossiq'],
				'setUser' => ['adv'],
				'setObject' => ['advice', 'a-1'],
				'setSubject' => ['advies_aangevraagd', ['object' => 'a-1']],
				'setMessage' => ['plain', ['message' => 'Please advise']],
			],
			$this->sent[0]
		);
		$this->assertArrayNotHasKey('setMessage', $this->sent[1]);
	}//end testANotificationCarriesItsFieldsAndAnOptionalMessage()

	/**
	 * A failing push is logged, never thrown.
	 *
	 * @return void
	 */
	public function testAFailingPushIsLoggedNotThrown(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Dossiq: failed to send advice notification: push gateway down',
			['app' => 'dossiq']
		);

		$this->notifier(logger: $logger, fail: true)->sendUserNotification(userId: 'adv', subject: 's', objectId: 'a-1');
	}//end testAFailingPushIsLoggedNotThrown()

	/**
	 * Requesting tells the advisor with the advice subject; receiving tells the caller with the summary.
	 *
	 * @return void
	 */
	public function testTransitionsNotifyTheRightPerson(): void {
		$notifier = $this->notifier();

		$notifier->fireTransitionNotification(to: 'requested', current: ['advisor' => 'adv', 'subject' => 'Noise permit'], adviceId: 'a-1', callerId: 'handler');
		$notifier->fireTransitionNotification(to: 'received', current: ['advisor' => 'adv'], adviceId: 'a-1', callerId: 'handler', summary: 'Positive');

		$this->assertSame(['adv'], $this->sent[0]['setUser']);
		$this->assertSame(['advies_aangevraagd', ['object' => 'a-1']], $this->sent[0]['setSubject']);
		$this->assertSame(['plain', ['message' => 'Noise permit']], $this->sent[0]['setMessage']);
		$this->assertSame(['handler'], $this->sent[1]['setUser']);
		$this->assertSame(['advies_ontvangen', ['object' => 'a-1']], $this->sent[1]['setSubject']);
		$this->assertSame(['plain', ['message' => 'Positive']], $this->sent[1]['setMessage']);
	}//end testTransitionsNotifyTheRightPerson()

	/**
	 * Nobody is notified without an advisor, without a caller, or for any other target.
	 *
	 * @return void
	 */
	public function testNobodyIsNotifiedWithoutARecipient(): void {
		$notifier = $this->notifier();

		$notifier->fireTransitionNotification(to: 'requested', current: [], adviceId: 'a-1', callerId: 'handler');
		$notifier->fireTransitionNotification(to: 'received', current: ['advisor' => 'adv'], adviceId: 'a-1', callerId: '');
		$notifier->fireTransitionNotification(to: 'withdrawn', current: ['advisor' => 'adv'], adviceId: 'a-1', callerId: 'handler');
		$notifier->notifyAdviseur(caseId: 'c-1', payload: ['advisor' => ''], saved: ['id' => 'a-1']);

		$this->assertSame([], $this->sent);
	}//end testNobodyIsNotifiedWithoutARecipient()

	/**
	 * A new request tells the advisor about the saved advice, or the case when it has no id.
	 *
	 * @return void
	 */
	public function testANewRequestNotifiesTheAdvisor(): void {
		$notifier = $this->notifier();

		$notifier->notifyAdviseur(caseId: 'c-1', payload: ['advisor' => 'adv'], saved: ['id' => 'a-9']);
		$notifier->notifyAdviseur(caseId: 'c-2', payload: ['advisor' => 'adv'], saved: []);

		$this->assertSame(
			['setApp' => ['dossiq'], 'setUser' => ['adv'], 'setObject' => ['advice', 'a-9'], 'setSubject' => ['advice_requested', ['object' => 'a-9']], 'setMessage' => ['plain', ['message' => 'Adviesaanvraag voor zaak c-1']]],
			$this->sent[0]
		);
		$this->assertSame(['advice', 'c-2'], $this->sent[1]['setObject']);
	}//end testANewRequestNotifiesTheAdvisor()
}//end class
