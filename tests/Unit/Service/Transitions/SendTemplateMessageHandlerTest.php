<?php

/**
 * A rule's message goes to a role on the case, the applicant by default.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\CaseEmailService;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\RoleResolverService;
use OCA\Dossiq\Service\Termijn\TermNoticeLedger;
use OCA\Dossiq\Service\Transitions\SendTemplateMessageHandler;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */
class SendTemplateMessageHandlerTest extends TestCase {

	/**
	 * The case: an applicant address and a contact holding the role "architect".
	 *
	 * @var array<string, mixed>
	 */
	private const CASE = [
		'id' => 'case-1',
		'email' => 'aanvrager@example.nl',
	];

	/**
	 * The handler over a mail double recording what was sent.
	 *
	 * @param array<int, array<int, string>> $sent Receives [case, template, to] per send.
	 *
	 * @return SendTemplateMessageHandler The handler.
	 */
	private function handler(array &$sent): SendTemplateMessageHandler {
		$mail = $this->createMock(CaseEmailService::class);
		$mail->method('sendFromTemplate')->willReturnCallback(
			static function (string $caseId, string $templateId, string $to) use (&$sent): array {
				$sent[] = [$caseId, $templateId, $to];
				return [];
			}
		);
		$contacts = (new \ReflectionClass(CaseContactDirectory::class))->newInstanceWithoutConstructor();
		$claimed = [];
		$ledger = $this->createMock(TermNoticeLedger::class);
		$ledger->method('claim')->willReturnCallback(
			static function (string $key) use (&$claimed): bool {
				if (isset($claimed[$key]) === true) {
					return false;
				}

				$claimed[$key] = true;
				return true;
			}
		);

		// The case's role objects: an architect held by a Nextcloud user and by a contact address.
		$roles = $this->createMock(RoleResolverService::class);
		$roles->method('resolve')->willReturnCallback(
			static fn (array $rule): array => ($rule['roleType'] === 'role-architect' ? ['esra', 'Bureau@Example.nl'] : [])
		);
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('Esra@zuiddrecht.nl');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($uid === 'esra' ? $user : null));

		return new SendTemplateMessageHandler(
			assignees: new AssigneeResolver(new NullLogger()),
			contacts: $contacts,
			mail: $mail,
			logger: new NullLogger(),
			ledger: $ledger,
			roles: $roles,
			users: $users
		);
	}//end handler()

	/**
	 * Without a role the applicant gets it; with one, the participants of the case's role objects.
	 *
	 * @return void
	 */
	public function testTheMessageGoesToTheRole(): void {
		$sent = [];
		$handler = $this->handler($sent);

		$this->assertTrue($handler->handle(['emailTemplate' => 'tpl-weigering'], self::CASE, [])->succeeded);
		$this->assertTrue($handler->handle(['emailTemplate' => 'tpl-weigering', 'recipientRole' => 'role-architect'], self::CASE, [])->succeeded);

		$this->assertSame(
			[
				['case-1', 'tpl-weigering', 'aanvrager@example.nl'],
				['case-1', 'tpl-weigering', 'esra@zuiddrecht.nl'],
				['case-1', 'tpl-weigering', 'bureau@example.nl'],
			],
			$sent
		);
	}//end testTheMessageGoesToTheRole()

	/**
	 * A role nobody holds sends nothing and fails the step.
	 *
	 * @return void
	 */
	public function testNoRecipientSendsNothing(): void {
		$sent = [];
		$result = $this->handler($sent)->handle(['emailTemplate' => 'tpl', 'recipientRole' => 'role-notaris'], self::CASE, []);

		$this->assertFalse($result->succeeded);
		$this->assertSame('no_recipient', $result->error);
		$this->assertSame([], $sent);
	}//end testNoRecipientSendsNothing()
	/**
	 * A once-keyed message goes out once per case, and once per status entry with oncePer.
	 *
	 * @return void
	 */
	public function testAOnceKeyedMessageIsSentOncePerVersion(): void {
		$sent = [];
		$handler = $this->handler($sent);
		$config = ['emailTemplate' => 'tpl', 'onceKey' => 'rule:t:r', 'oncePer' => 'currentStatusEnteredAt'];
		$case = self::CASE + ['currentStatusEnteredAt' => '2026-10-24T10:00:00Z'];

		$handler->handle($config, $case, []);
		$handler->handle($config, $case, []);
		$handler->handle($config, ['currentStatusEnteredAt' => '2026-10-25T09:00:00Z'] + $case, []);

		$this->assertCount(2, $sent);
	}//end testAOnceKeyedMessageIsSentOncePerVersion()
}//end class
