<?php

/**
 * Golden test for DoorzendingNotifier::announce: the exact notice asked for,
 * every answer and every log line.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transfer;

use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\Transfer\DoorzendingNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

class DoorzendingNotifierGoldenTest extends TestCase {

	private array $logged = [];

	private array $sent = [];

	private int $lookups = 0;

	private function notifier(array $addresses, bool $fail = false): DoorzendingNotifier {
		$notifications = $this->createMock(TermijnNotificationService::class);
		$sent          = &$this->sent;
		$notifications->method('sendTermijnNotification')->willReturnCallback(
			static function (...$args) use (&$sent, $fail): array {
				$sent[] = $args;
				if ($fail === true) {
					throw new RuntimeException('mail down');
				}

				return ['subject' => 'ok'];
			}
		);
		$contacts = $this->createMock(CaseContactDirectory::class);
		$lookups  = &$this->lookups;
		$contacts->method('collectAddresses')->willReturnCallback(
			static function (array $caseData) use (&$lookups, $addresses): array {
				$lookups++;
				return $addresses;
			}
		);
		$logged = &$this->logged;
		$logger = new class ($logged) extends AbstractLogger {
			private array $lines;

			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [(string) $level, (string) $message, $context];
			}
		};

		return new DoorzendingNotifier(notifications: $notifications, contacts: $contacts, logger: $logger);
	}//end notifier()

	public function testTheNoticeAskedFor(): void {
		$case = ['id' => ' case-1 ', 'language' => ' en '];

		$this->assertSame(
			['announced' => true, 'payload' => ['subject' => 'ok']],
			$this->notifier(['first@example.org', 'second@example.org'])->announce(case: $case, transfer: ['doorzending' => '1', 'targetTeam' => ' ', 'targetOrganization' => ' Toezicht '])
		);
		$this->assertSame(
			[[DoorzendingNotifier::TEMPLATE, '', 'first@example.org', ['case' => 'case-1', 'destination' => 'Toezicht', 'locale' => 'en', 'addressee' => ['address' => 'first@example.org']], $case]],
			$this->sent
		);
		$this->assertSame(1, $this->lookups);
		$this->assertSame([], $this->logged);

		$this->sent = [];
		$this->notifier([])->announce(case: ['identifier' => 'Z-1', 'portalSubject' => ' portal-user '], transfer: ['doorzending' => true, 'targetTeam' => 'team-b']);
		$this->assertSame(['case' => 'Z-1', 'destination' => 'team-b', 'locale' => 'nl', 'addressee' => ['address' => 'portal-user']], $this->sent[0][3]);
	}//end testTheNoticeAskedFor()

	public function testEveryOtherAnswer(): void {
		foreach ([false, 'false', 0, 'yes', null] as $flag) {
			$this->assertSame(['announced' => false, 'reason' => 'internal-move'], $this->notifier(['a@b.c'])->announce(case: [], transfer: ['doorzending' => $flag]));
		}

		$this->assertSame(0, $this->lookups);
		$this->assertSame(['announced' => false, 'reason' => 'no-address'], $this->notifier([])->announce(case: ['id' => ' c-2 '], transfer: ['doorzending' => 'true']));
		$this->assertSame(['announced' => false, 'reason' => 'dispatch-failed'], $this->notifier(['a@b.c'], fail: true)->announce(case: ['id' => 'c-3'], transfer: ['doorzending' => 1]));
		$this->assertSame(
			[
				['warning', 'Dossiq doorzending: the case carries no address, so the applicant was not told', ['caseId' => 'c-2']],
				['error', 'Dossiq doorzending: the applicant could not be told the case moved', ['exception' => 'mail down', 'caseId' => 'c-3']],
			],
			$this->logged
		);
		$this->assertCount(1, $this->sent);
	}//end testEveryOtherAnswer()
}//end class
