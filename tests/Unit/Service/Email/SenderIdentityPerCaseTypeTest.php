<?php

/**
 * A team's mail carries that team's sender identity (REQ-IMF-12).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-a-teams-mail-carries-that-teams-sender-identity-req-imf-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Email\IntakeAccount;
use OCA\Dossiq\Service\Email\SenderIdentity;
use OCA\Dossiq\Tests\Support\FakeMailGateway;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Email\SenderIdentity
 *
 * @uses \OCA\Dossiq\Service\Email\IntakeAccount
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class SenderIdentityPerCaseTypeTest extends TestCase {

	/**
	 * The fake Mail app, with the default mailbox and the Juridische Zaken account.
	 *
	 * @var FakeMailGateway
	 */
	private FakeMailGateway $gateway;

	/**
	 * Set up the two accounts.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->gateway = new FakeMailGateway();
		$this->gateway->accounts = [
			['id' => 7, 'name' => 'Zaken', 'email' => 'zaken@gemeente.nl'],
			['id' => 8, 'name' => 'Juridische Zaken', 'email' => 'juridischezaken@gemeente.nl'],
		];
	}//end setUp()

	/**
	 * The identity under test.
	 *
	 * @param array<string, array<string, mixed>> $caseTypes The effective case types by id.
	 * @param string                              $default   The picked default account id.
	 *
	 * @return SenderIdentity The identity.
	 */
	private function identity(array $caseTypes = [], string $default = '7'): SenderIdentity {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $fallback = ''): string => ($key === IntakeAccount::ACCOUNT_KEY ? $default : $fallback)
		);

		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(
			static fn (string $caseTypeId): array => ($caseTypes[$caseTypeId] ?? [])
		);

		$store = $this->createMock(CaseTypeStore::class);
		$store->method('referenceId')->willReturnCallback(
			static fn ($value): string => (is_array($value) === true ? (string)($value['id'] ?? '') : trim((string)$value))
		);

		return new SenderIdentity($this->gateway, new IntakeAccount($appConfig), $resolver, $store);
	}//end identity()

	/**
	 * A bezwaar goes out from Juridische Zaken.
	 *
	 * @return void
	 */
	public function testABezwaarGoesOutFromJuridischeZaken(): void {
		$identity = $this->identity(['ct-bezwaar' => ['title' => 'Bezwaar', 'mailAccount' => 'JuridischeZaken@gemeente.nl']]);

		$account = $identity->accountForCase(['caseType' => ['id' => 'ct-bezwaar']]);

		self::assertSame(8, $account['id']);
		self::assertSame('juridischezaken@gemeente.nl', $account['email']);
	}//end testABezwaarGoesOutFromJuridischeZaken()

	/**
	 * A case type with no declaration uses the default account.
	 *
	 * @return void
	 */
	public function testACaseTypeWithNoDeclarationUsesTheDefaultAccount(): void {
		$identity = $this->identity(['ct-melding' => ['title' => 'Melding']]);

		self::assertSame(7, $identity->accountForCase(['caseType' => 'ct-melding'])['id']);
		self::assertSame(7, $identity->accountForCase([])['id']);
	}//end testACaseTypeWithNoDeclarationUsesTheDefaultAccount()

	/**
	 * An unresolvable account refuses publication, naming the account.
	 *
	 * @return void
	 */
	public function testAnUnresolvableAccountRefusesPublication(): void {
		$identity = $this->identity();

		$findings = $identity->publicationFindings(['mailAccount' => 'vergunningen@gemeente.nl']);

		self::assertCount(1, $findings);
		self::assertStringContainsString('vergunningen@gemeente.nl', $findings[0]);
		self::assertSame([], $identity->publicationFindings(['mailAccount' => 'juridischezaken@gemeente.nl']));
		self::assertSame([], $identity->publicationFindings(['title' => 'No declaration']));
	}//end testAnUnresolvableAccountRefusesPublication()

	/**
	 * dossiq does not forge a sender: an address no account holds is refused.
	 *
	 * @return void
	 */
	public function testDossiqDoesNotForgeASender(): void {
		try {
			$this->identity()->accountFor('burgemeester@gemeente.nl');
			self::fail('An address no account holds must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('sender-not-held', $e->getRule());
			self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
			self::assertStringContainsString('burgemeester@gemeente.nl', $e->getSentence());
		}
	}//end testDossiqDoesNotForgeASender()

	/**
	 * A case whose type names an account that is gone is refused rather than sent from the default.
	 *
	 * @return void
	 */
	public function testACaseWhoseDeclaredAccountIsGoneIsRefusedNotRerouted(): void {
		$identity = $this->identity(['ct-x' => ['mailAccount' => 'weg@gemeente.nl']]);

		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('sender_not_held');

		$identity->accountForCase(['caseType' => 'ct-x']);
	}//end testACaseWhoseDeclaredAccountIsGoneIsRefusedNotRerouted()

	/**
	 * No default account picked, or one Mail no longer has: unavailable, not a guess.
	 *
	 * @return void
	 */
	public function testNoUsableDefaultAccountIsUnavailable(): void {
		foreach (['0', '99'] as $default) {
			try {
				$this->identity([], $default)->accountFor('');
				self::fail('No usable default account must be refused.');
			} catch (RefusedException $e) {
				self::assertSame('mail-account-unavailable', $e->getRule());
				self::assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
			}
		}
	}//end testNoUsableDefaultAccountIsUnavailable()
}//end class
