<?php

/**
 * What a failing verdict means, decided per case type.
 *
 * The two cases that matter are the two the design names: a melding openbare
 * ruimte from an unauthenticated sender is a normal Tuesday, and a bezwaar from
 * one is a forgery risk with a statutory consequence. One instance-wide answer
 * is wrong for somebody whichever way it is set.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\AuthenticationResult;
use OCA\Dossiq\Service\Email\IntakePolicy;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the per-case-type intake policy.
 *
 * @covers \OCA\Dossiq\Service\Email\IntakePolicy
 * @uses \OCA\Dossiq\Service\Email\AuthenticationResult
 */
class IntakePolicyTest extends TestCase {

	/**
	 * Build a policy reader over a stub settings service.
	 *
	 * @param string $intakeRole The group named as the intake role.
	 * @param array<string, bool> $memberships Group memberships, by "user|group".
	 *
	 * @return IntakePolicy The policy reader.
	 */
	private function policy(string $intakeRole = '', array $memberships = []): IntakePolicy {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('getConfigValue')->willReturn('');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($intakeRole): string {
				if ($key === IntakePolicy::INTAKE_ROLE_KEY) {
					return $intakeRole;
				}

				return $default;
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			static function (string $userId) use ($memberships): bool {
				return ($memberships[$userId . '|admin'] ?? false);
			}
		);
		$groups->method('isInGroup')->willReturnCallback(
			static function (string $userId, string $group) use ($memberships): bool {
				return ($memberships[$userId . '|' . $group] ?? false);
			}
		);

		return new IntakePolicy(
			settingsService: $settings,
			appConfig: $appConfig,
			groupManager: $groups,
			logger: new NullLogger()
		);
	}//end policy()

	/**
	 * A case type that declares nothing quarantines.
	 *
	 * Quarantine is the only answer that neither drops a bezwaar nor files a
	 * forged one, so it is what silence means.
	 *
	 * @return void
	 */
	public function testACaseTypeThatDeclaresNothingQuarantines(): void {
		self::assertSame(IntakePolicy::QUARANTINE, IntakePolicy::DEFAULT_POLICY);
		self::assertSame(IntakePolicy::QUARANTINE, $this->policy()->forCaseType(caseTypeId: ''));
		self::assertSame(
			IntakePolicy::QUARANTINE,
			$this->policy()->forCaseType(caseTypeId: 'een-zaaktype')
		);
	}//end testACaseTypeThatDeclaresNothingQuarantines()

	/**
	 * A bezwaar with a failing DMARC waits for a human.
	 *
	 * @return void
	 */
	public function testABezwaarFromAnUnauthenticatedSenderWaitsForAHuman(): void {
		$outcome = $this->policy()->outcomeFor(
			policy: IntakePolicy::QUARANTINE,
			results: [
				'spf' => AuthenticationResult::UNAVAILABLE,
				'dkim' => AuthenticationResult::NONE,
				'dmarc' => AuthenticationResult::FAIL,
				'threading' => AuthenticationResult::NONE,
			]
		);

		self::assertSame(IntakePolicy::QUARANTINE, $outcome);
	}//end testABezwaarFromAnUnauthenticatedSenderWaitsForAHuman()

	/**
	 * A melding that declares `accept` becomes a case anyway.
	 *
	 * @return void
	 */
	public function testAMeldingFromAnUnauthenticatedSenderIsNormal(): void {
		$outcome = $this->policy()->outcomeFor(
			policy: IntakePolicy::ACCEPT,
			results: ['dmarc' => AuthenticationResult::FAIL]
		);

		self::assertSame(IntakePolicy::ACCEPT, $outcome);
	}//end testAMeldingFromAnUnauthenticatedSenderIsNormal()

	/**
	 * A verdict with no failure is accepted, whatever the policy says.
	 *
	 * 🔴 The policy is about a FAILING verdict and nothing else. A case type
	 * that declares `refuse` must not refuse every message, only the ones that
	 * failed a check. `unavailable` and `none` are absences of evidence.
	 *
	 * @return void
	 */
	public function testAPolicyOnlyBitesOnAnActualFailure(): void {
		$outcome = $this->policy()->outcomeFor(
			policy: IntakePolicy::REFUSE,
			results: [
				'spf' => AuthenticationResult::UNAVAILABLE,
				'dkim' => AuthenticationResult::NONE,
				'dmarc' => AuthenticationResult::UNAVAILABLE,
				'threading' => AuthenticationResult::NONE,
			]
		);

		self::assertSame(IntakePolicy::ACCEPT, $outcome);
	}//end testAPolicyOnlyBitesOnAnActualFailure()

	/**
	 * A case type declaring something this app does not know quarantines.
	 *
	 * @return void
	 */
	public function testAnUnknownPolicyFallsBackToQuarantine(): void {
		self::assertSame(
			IntakePolicy::QUARANTINE,
			$this->policy()->outcomeFor(
				policy: 'delete-it',
				results: ['dmarc' => AuthenticationResult::FAIL]
			)
		);
	}//end testAnUnknownPolicyFallsBackToQuarantine()

	/**
	 * An unnamed intake role means administrators only, never everybody.
	 *
	 * 🔴 The log holds the original of every message the mailbox received. An
	 * instance that configured no group has not said "anyone may read it".
	 *
	 * @return void
	 */
	public function testAnUnnamedRoleMeansAdministratorsOnly(): void {
		$policy = $this->policy(memberships: ['beheerder|admin' => true]);

		self::assertTrue($policy->mayRunIntake(userId: 'beheerder'));
		self::assertFalse($policy->mayRunIntake(userId: 'willekeurige-gebruiker'));
		self::assertFalse($policy->mayRunIntake(userId: ''));
	}//end testAnUnnamedRoleMeansAdministratorsOnly()

	/**
	 * A member of the named group may run intake.
	 *
	 * @return void
	 */
	public function testTheNamedGroupMayRunIntake(): void {
		$policy = $this->policy(
			intakeRole: 'zaakbehandelaars',
			memberships: ['jan|zaakbehandelaars' => true]
		);

		self::assertTrue($policy->mayRunIntake(userId: 'jan'));
		self::assertFalse($policy->mayRunIntake(userId: 'piet'));
	}//end testTheNamedGroupMayRunIntake()
}//end class
