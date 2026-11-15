<?php

/**
 * The notifier's name, and the wording of what an applicant did in the portal.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Notification
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

namespace OCA\Dossiq\Tests\Unit\Notification;

use OCA\Dossiq\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * A withdrawal with and without a reason, and each portal act, exactly as the handler reads them.
 *
 * @covers \OCA\Dossiq\Notification\Notifier
 * @uses \OCA\Dossiq\Notification\NotificationText
 */
class NotifierApplicantActsTest extends TestCase {

	private function notifier(): Notifier {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->with('dossiq', 'nl')->willReturn($l10n);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/img/app-dark.svg');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example.nl'.$path);

		return new Notifier($factory, $urls);
	}

	/**
	 * @return array{0: string, 1: string, 2: string} Subject, message and icon as set on the notification.
	 */
	private function prepared(array $parameters, string $subject = Notifier::SUBJECT_APPLICANT_WITHDREW): array {
		$set          = ['', '', ''];
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('dossiq');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		$notification->method('setParsedSubject')->willReturnCallback(function (string $text) use (&$set, $notification): INotification {
			$set[0] = $text;
			return $notification;
		});
		$notification->method('setParsedMessage')->willReturnCallback(function (string $text) use (&$set, $notification): INotification {
			$set[1] = $text;
			return $notification;
		});
		$notification->method('setIcon')->willReturnCallback(function (string $icon) use (&$set, $notification): INotification {
			$set[2] = $icon;
			return $notification;
		});

		$this->assertSame($notification, $this->notifier()->prepare($notification, 'nl'));

		return $set;
	}

	public function testTheNotifierIsNamedDossiq(): void {
		$this->assertSame('Dossiq', $this->notifier()->getName());
	}

	public function testAWithdrawalWithAReasonQuotesIt(): void {
		$this->assertSame(
			['The applicant withdrew a case you handle', 'Reason given: verhuisd', 'https://cloud.example.nl/img/app-dark.svg'],
			$this->prepared(['reason' => ' verhuisd '])
		);
	}

	public function testAWithdrawalWithoutAReasonSaysWhatToDo(): void {
		foreach ([[], ['reason' => '   ']] as $parameters) {
			$this->assertSame(
				['The applicant withdrew a case you handle', 'Open the case to close it the way its case type prescribes.', 'https://cloud.example.nl/img/app-dark.svg'],
				$this->prepared($parameters)
			);
		}
	}

	public function testEachPortalActHasItsOwnMessage(): void {
		$messages = [
			'amendment' => 'The applicant changed an answer in the portal. Open the case to see what changed.',
			'document' => 'The applicant added a document in the portal. Open the case to read it.',
			'task-answer' => 'The applicant answered a task in the portal. Open the case to see the answer.',
			'answer' => 'The applicant answered your question in the portal. Open the case to read the answer and say whether the request is complete.',
			'something-else' => 'Open the case to see what the applicant did.',
		];
		foreach ($messages as $act => $message) {
			$this->assertSame(
				['The applicant responded on a case you handle', $message],
				array_slice($this->prepared(['act' => $act], Notifier::SUBJECT_APPLICANT_RESPONDED), 0, 2),
				$act
			);
		}
	}
}
