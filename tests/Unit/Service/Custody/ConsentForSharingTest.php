<?php

/**
 * CaseTransferConsentGate::assessSharing(): a share with an outside party needs
 * a valid consent naming it when the case type requires consent for sharing.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Custody
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-consent-is-recorded-and-revoked-on-the-case-req-sdc-consent-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Custody;

use OCA\Dossiq\Service\Custody\CaseTransferConsentGate;
use OCA\Dossiq\Service\Custody\ConsentRefusal;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Unit\Service\Transitions\SourceL10n;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Sharing is read through the same consents as a hand-off, but only for a
 * case type that asks for it, and it fails closed when the case type is unreadable.
 *
 * @covers \OCA\Dossiq\Service\Custody\CaseTransferConsentGate
 * @covers \OCA\Dossiq\Service\Custody\ConsentRefusal
 * @uses   \OCA\Dossiq\Service\Custody\ConsentStanding
 * @uses   \OCA\Dossiq\Service\Custody\DossierRegister
 */
class ConsentForSharingTest extends TestCase {

	/**
	 * The gate over an in-memory register holding one case of a case type and its consents.
	 *
	 * @param array<string, mixed>|string     $caseType The case type, or 'missing' / 'throw'.
	 * @param list<array<string, mixed>>      $consents The consents recorded on the case.
	 *
	 * @return CaseTransferConsentGate
	 */
	private function gate(array|string $caseType, array $consents=[]): CaseTransferConsentGate {
		$store = new class($caseType, $consents) {
			/**
			 * @param array<string, mixed>|string $caseType The case type.
			 * @param list<array<string, mixed>>  $consents The consents.
			 */
			public function __construct(private array|string $caseType, private array $consents) {
			}

			/**
			 * @param int|string $id       The id.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function find(int|string $id, int|string $register='', int|string $schema=''): array {
				if ($schema === 'case') {
					return ['id' => $id, 'caseType' => 'ct-1'];
				}

				if ($this->caseType === 'throw') {
					throw new RuntimeException('store down');
				}

				if ($this->caseType === 'missing') {
					throw new DoesNotExistException('not found');
				}

				return $this->caseType;
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters=[]): array {
				return array_values(array_filter($this->consents, static fn (array $row): bool => $row['caseId'] === $filters['caseId']));
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default=''): string => ([
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				'consent_schema' => 'toestemming',
			][$key] ?? $default)
		);

		return new CaseTransferConsentGate(settingsService: $settings, logger: new NullLogger(), refusals: new ConsentRefusal(l10n: new SourceL10n()));
	}//end gate()

	/**
	 * A consent on case-1 naming the party, valid from last year until far ahead.
	 *
	 * @param string               $party  Who it names.
	 * @param array<string, mixed> $fields Fields that differ.
	 *
	 * @return array<string, mixed>
	 */
	private function consent(string $party, array $fields=[]): array {
		return $fields + [
			'id' => 'consent-' . $party,
			'caseId' => 'case-1',
			'recipientParties' => [$party],
			'grantedDate' => date('Y-m-d', strtotime('-1 year')),
			'validTo' => '2099-12-31',
			'withdrawn' => false,
			'tegegevens' => ['naam', 'hulpvraag'],
		];
	}//end consent()

	/**
	 * A case type without the flag shares without asking for consent.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutTheFlagSharesFreely(): void {
		$verdict = $this->gate(caseType: ['id' => 'ct-1', 'requiresConsentForSharing' => false])->assessSharing(caseId: 'case-1', party: 'Veilig Thuis');

		$this->assertTrue($verdict['allowed']);
		$this->assertNull($verdict['consent']);
		$this->assertSame([], $verdict['scope']);
	}//end testACaseTypeWithoutTheFlagSharesFreely()

	/**
	 * With the flag and no consent naming the party, the share is refused with the handler's sentence.
	 *
	 * @return void
	 */
	public function testSharingWithoutConsentIsRefused(): void {
		$verdict = $this->gate(
			caseType: ['id' => 'ct-1', 'requiresConsentForSharing' => true],
			consents: [$this->consent('Wijkteam Noord')]
		)->assessSharing(caseId: 'case-1', party: 'Veilig Thuis');

		$this->assertFalse($verdict['allowed']);
		$this->assertSame(CaseTransferConsentGate::NO_CONSENT, $verdict['rule']);
		$this->assertSame('There is no valid consent for this party.', $verdict['sentence']);
	}//end testSharingWithoutConsentIsRefused()

	/**
	 * A valid consent naming the party lets the share go, carrying its scope and end.
	 *
	 * @return void
	 */
	public function testAValidConsentNamingThePartyAllowsIt(): void {
		$verdict = $this->gate(
			caseType: ['id' => 'ct-1', 'requiresConsentForSharing' => true],
			consents: [$this->consent('Veilig Thuis')]
		)->assessSharing(caseId: 'case-1', party: ' veilig thuis ');

		$this->assertTrue($verdict['allowed']);
		$this->assertSame('consent-Veilig Thuis', $verdict['consent']['id']);
		$this->assertSame(['naam', 'hulpvraag'], $verdict['scope']);
		$this->assertSame('2099-12-31', $verdict['until']);
	}//end testAValidConsentNamingThePartyAllowsIt()

	/**
	 * A revoked consent stops the next share, keeping the withdrawn rule.
	 *
	 * @return void
	 */
	public function testARevokedConsentStopsTheShare(): void {
		$verdict = $this->gate(
			caseType: ['id' => 'ct-1', 'requiresConsentForSharing' => true],
			consents: [$this->consent('Wijkteam Noord', ['withdrawn' => true])]
		)->assessSharing(caseId: 'case-1', party: 'Wijkteam Noord');

		$this->assertFalse($verdict['allowed']);
		$this->assertSame(CaseTransferConsentGate::CONSENT_WITHDRAWN, $verdict['rule']);
		$this->assertSame('There is no valid consent for this party.', $verdict['sentence']);
	}//end testARevokedConsentStopsTheShare()

	/**
	 * A lapsed consent is no consent for sharing either.
	 *
	 * @return void
	 */
	public function testALapsedConsentStopsTheShare(): void {
		$verdict = $this->gate(
			caseType: ['id' => 'ct-1', 'requiresConsentForSharing' => true],
			consents: [$this->consent('Wijkteam Noord', ['grantedDate' => '2020-01-01', 'validTo' => '2021-01-01'])]
		)->assessSharing(caseId: 'case-1', party: 'Wijkteam Noord');

		$this->assertFalse($verdict['allowed']);
		$this->assertSame(CaseTransferConsentGate::CONSENT_LAPSED, $verdict['rule']);
	}//end testALapsedConsentStopsTheShare()

	/**
	 * A case type that cannot be read refuses the share rather than letting it through.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseTypeFailsClosed(): void {
		foreach (['throw', 'missing'] as $caseType) {
			$verdict = $this->gate(caseType: $caseType, consents: [$this->consent('Veilig Thuis')])->assessSharing(caseId: 'case-1', party: 'Veilig Thuis');

			$this->assertFalse($verdict['allowed']);
			$this->assertSame(CaseTransferConsentGate::CONSENT_UNCHECKED, $verdict['rule']);
			$this->assertSame('Consent could not be checked, so the case was not shared.', $verdict['sentence']);
		}

		$this->assertFalse($this->gate(caseType: ['id' => 'ct-1'])->assessSharing(caseId: ' ', party: 'Veilig Thuis')['allowed']);
	}//end testAnUnreadableCaseTypeFailsClosed()
}//end class
