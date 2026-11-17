<?php

/**
 * Unit tests for BackgroundServiceAccountCheck: the setup check passes naming
 * the account background jobs save as, and otherwise warns why the chosen
 * account cannot be used and what stops until one is chosen.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\SetupCheck;

use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\SetupCheck\BackgroundServiceAccountCheck;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\SetupCheck\BackgroundServiceAccountCheck
 */
class BackgroundServiceAccountCheckTest extends TestCase {

	private const UNTIL = 'Until you choose one in the Dossiq settings, reminders on suspended terms are not sent and nothing is saved.';

	private BackgroundServiceAccount&MockObject $account;

	protected function setUp(): void {
		parent::setUp();
		$this->account = $this->createMock(BackgroundServiceAccount::class);
	}//end setUp()

	private function check(): BackgroundServiceAccountCheck {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return new BackgroundServiceAccountCheck($this->account, $l10n);
	}//end check()

	public function testAUsableAccountPassesNamingTheAccount(): void {
		$this->account->method('status')->willReturn(['usable' => true, 'userId' => 'dossiq-bot', 'reason' => null]);

		$result = $this->check()->run();

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
		$this->assertSame('The Dossiq background jobs save as dossiq-bot.', $result->getDescription());
	}//end testAUsableAccountPassesNamingTheAccount()

	/**
	 * @return array<string, array{string|null, string}>
	 */
	public static function refusals(): array {
		return [
			'unknown' => ['unknown', 'The chosen account does not exist.'],
			'disabled' => ['disabled', 'The chosen account is disabled.'],
			'not a member' => ['not-in-group', 'The chosen account is not in the group dossiq-service.'],
			'unset' => ['unset', 'No account is chosen.'],
			'anything else' => [null, 'No account is chosen.'],
		];
	}//end refusals()

	#[DataProvider('refusals')]
	public function testAnUnusableAccountWarnsWhyAndWhatStops(?string $reason, string $why): void {
		$this->account->method('status')->willReturn(['usable' => false, 'userId' => 'x', 'reason' => $reason]);
		$this->account->method('group')->willReturn('dossiq-service');

		$result = $this->check()->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertSame($why . ' ' . self::UNTIL, $result->getDescription());
	}//end testAnUnusableAccountWarnsWhyAndWhatStops()

	public function testAUsableAccountNeverAsksForTheGroup(): void {
		$this->account->expects($this->once())->method('status')->willReturn(['usable' => true, 'userId' => 'svc', 'reason' => 'disabled']);
		$this->account->expects($this->never())->method('group');

		$result = $this->check()->run();

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
		$this->assertSame('The Dossiq background jobs save as svc.', $result->getDescription());
	}//end testAUsableAccountNeverAsksForTheGroup()

	public function testOnlyTheMembershipRefusalAsksForTheGroup(): void {
		$this->account->method('status')->willReturn(['usable' => false, 'userId' => 'svc', 'reason' => 'disabled']);
		$this->account->expects($this->never())->method('group');

		$this->assertSame(SetupResult::WARNING, $this->check()->run()->getSeverity());
	}//end testOnlyTheMembershipRefusalAsksForTheGroup()

	public function testTheCheckIsASystemCheckWithATranslatedName(): void {
		$check = $this->check();

		$this->assertSame('system', $check->getCategory());
		$this->assertSame('Dossiq background service account', $check->getName());
	}//end testTheCheckIsASystemCheckWithATranslatedName()
}//end class
