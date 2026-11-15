<?php

/**
 * The case changer: the caller, the signed-out answer, or the given refusal.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Controller\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\AnswersSignedOutCallers;
use OCA\Dossiq\Controller\Support\FindsCaseChangers;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\FindsCaseChangers
 */
class FindsCaseChangersTest extends TestCase {

	private function host(?IUser $user, bool $mayChange): object {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$guard = $this->createMock(CaseAccessGuardInterface::class);
		$guard->method('hasCaseMutationAccess')->willReturn($mayChange);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new class ($session, $guard, $l10n) {
			use AnswersSignedOutCallers;
			use FindsCaseChangers;

			public function __construct(
				private readonly IUserSession $userSession,
				private readonly CaseAccessGuardInterface $caseAccessGuard,
				private readonly IL10N $l10n,
			) {
			}

			public function changer(string $caseId): mixed {
				return $this->caseChanger(caseId: $caseId, refusal: 'Not yours.');
			}
		};
	}

	public function testTheCallerMayChange(): void {
		$user = $this->createMock(IUser::class);
		$this->assertSame($user, $this->host($user, true)->changer('c-1'));
	}

	public function testNoSessionIsTheSignedOutAnswer(): void {
		$answer = $this->host(null, true)->changer('c-1');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $answer->getStatus());
		$this->assertSame(['error' => 'You are not signed in.'], $answer->getData());
	}

	public function testNoChangeAccessIsTheRefusal(): void {
		$answer = $this->host($this->createMock(IUser::class), false)->changer('c-1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $answer->getStatus());
		$this->assertSame(['error' => 'Not yours.'], $answer->getData());
	}
}
