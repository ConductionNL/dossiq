<?php

/**
 * The mail-kind split is configuration (decisions 165, 182).
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\MailTransportPolicy;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Email\MailTransportPolicy
 */
class MailTransportPolicyTest extends TestCase {

	/**
	 * A policy over one configured value.
	 *
	 * @param string $json The `mail_transport_by_kind` value.
	 *
	 * @return MailTransportPolicy The policy.
	 */
	private function policy(string $json): MailTransportPolicy {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === MailTransportPolicy::CONFIG_KEY ? $json : $default)
		);

		return new MailTransportPolicy($appConfig);
	}//end policy()

	/**
	 * With nothing configured, decision 165 applies.
	 *
	 * @return void
	 */
	public function testNothingConfiguredFollowsDecision165(): void {
		$policy = $this->policy('');

		self::assertSame(MailTransportPolicy::TRANSPORT_MAIL_ACCOUNT, $policy->transportFor(MailTransportPolicy::KIND_CASE_MAIL));
		self::assertSame(MailTransportPolicy::TRANSPORT_IMAILER, $policy->transportFor(MailTransportPolicy::KIND_NOTICE));
		self::assertSame(MailTransportPolicy::TRANSPORT_IMAILER, $policy->transportFor(MailTransportPolicy::KIND_SERVICE));
		self::assertSame(MailTransportPolicy::TRANSPORT_IMAILER, $policy->transportFor('a-kind-nobody-named'));
	}//end testNothingConfiguredFollowsDecision165()

	/**
	 * A configured kind moves; a kind it does not name keeps its default.
	 *
	 * @return void
	 */
	public function testAConfiguredKindMoves(): void {
		$policy = $this->policy('{"notice":"mail-account"}');

		self::assertSame(MailTransportPolicy::TRANSPORT_MAIL_ACCOUNT, $policy->transportFor(MailTransportPolicy::KIND_NOTICE));
		self::assertSame(MailTransportPolicy::TRANSPORT_MAIL_ACCOUNT, $policy->transportFor(MailTransportPolicy::KIND_CASE_MAIL));
		self::assertSame(MailTransportPolicy::TRANSPORT_IMAILER, $policy->transportFor(MailTransportPolicy::KIND_SERVICE));
	}//end testAConfiguredKindMoves()

	/**
	 * A typo or broken JSON keeps the default rather than sending nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownValueKeepsTheDefault(): void {
		self::assertSame(
			MailTransportPolicy::TRANSPORT_MAIL_ACCOUNT,
			$this->policy('{"case-mail":"smtp"}')->transportFor(MailTransportPolicy::KIND_CASE_MAIL)
		);
		self::assertSame(
			MailTransportPolicy::TRANSPORT_MAIL_ACCOUNT,
			$this->policy('{not json')->transportFor(MailTransportPolicy::KIND_CASE_MAIL)
		);
	}//end testAnUnknownValueKeepsTheDefault()
}//end class
