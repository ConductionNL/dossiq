<?php

/**
 * The consent a case needs before it leaves the organisation.
 *
 * `toestemming` was declared in the sociaal-domein register and read by nothing:
 * the slug had no config-key mapping, so no service could resolve the schema.
 * A Wmo file could be handed to a partner with no recorded consent and nothing
 * anywhere reported it. These tests are the refusal that ends that, and the two
 * ways it must NOT fire: a move inside one organisation, and a case type that
 * says an internal move needs consent too.
 *
 * MUTATION-CHECKED 2026-09-18: making `crossesOrganisation()` return false for
 * an empty receiver reddens testAHandOffWithNoNamedReceiverIsRefused on the
 * `allowed` assertion; dropping the `$expiresOn < $moment` branch reddens
 * testConsentThatHasLapsedDoesNotCover on the rule. Restored after.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Custody\CaseTransferConsentGate;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Whether the case may be handed on, and what the consent lets the receiver see.
 *
 * @covers \OCA\Dossiq\Service\Custody\CaseTransferConsentGate
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
class CaseTransferConsentGateTest extends TestCase {

	/**
	 * The store the gate reads.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * A Wmo case on a case type that does not demand consent inside the organisation.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(
			schema: 'caseType',
			uuid: 'ct-wmo',
			row: ['title' => 'Wmo-melding', 'consentRequiredInsideOrganisation' => false],
		);
		$this->store->seed(
			schema: 'case',
			uuid: 'case-1',
			row: ['title' => 'Wmo-melding Prinsengracht 12', 'caseType' => 'ct-wmo', 'assignedGroup' => 'wijkteam'],
		);
	}//end setUp()

	/**
	 * A Wmo case with no recorded consent does not leave the organisation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	public function testAWmoCaseCannotLeaveWithoutConsent(): void {
		$verdict = $this->gate()->assess(
			caseId: 'case-1',
			sourceOrganisation: 'gemeente-delft',
			receivingOrganisation: 'zorgpartner-bv',
			at: '2026-05-01',
		);

		self::assertFalse($verdict['allowed'], 'Without a consent, the hand-off does not happen.');
		self::assertSame(CaseTransferConsentGate::NO_CONSENT, $verdict['rule']);
		self::assertStringContainsString(
			'zorgpartner-bv',
			$verdict['sentence'],
			'The refusal names the receiver it found no consent for.',
		);
	}//end testAWmoCaseCannotLeaveWithoutConsent()

	/**
	 * A consent naming this receiver and covering today lets the case go.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	public function testARecordedConsentLetsTheCaseGo(): void {
		$this->recordConsent(validTo: '2026-12-31');

		$verdict = $this->gate()->assess(
			caseId: 'case-1',
			sourceOrganisation: 'gemeente-delft',
			receivingOrganisation: 'zorgpartner-bv',
			at: '2026-05-01',
		);

		self::assertTrue($verdict['allowed']);
		self::assertSame(['ondersteuningsplan'], $verdict['scope'], 'The scope the consent names travels with the verdict.');
		self::assertSame('2026-12-31', $verdict['until']);
	}//end testARecordedConsentLetsTheCaseGo()

	/**
	 * A consent whose period ended last month covers nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	public function testConsentThatHasLapsedDoesNotCover(): void {
		$this->recordConsent(validTo: '2026-04-01');

		$verdict = $this->gate()->assess(
			caseId: 'case-1',
			sourceOrganisation: 'gemeente-delft',
			receivingOrganisation: 'zorgpartner-bv',
			at: '2026-05-01',
		);

		self::assertFalse($verdict['allowed']);
		self::assertSame(CaseTransferConsentGate::CONSENT_LAPSED, $verdict['rule']);
		self::assertStringContainsString('2026-04-01', $verdict['sentence'], 'The refusal names the period that ended.');
	}//end testConsentThatHasLapsedDoesNotCover()

	/**
	 * A withdrawn consent is refused, and says so rather than reading as absent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	public function testAWithdrawnConsentIsNamedAsWithdrawn(): void {
		$this->recordConsent(validTo: '2026-12-31', withdrawn: true);

		$verdict = $this->gate()->assess(
			caseId: 'case-1',
			sourceOrganisation: 'gemeente-delft',
			receivingOrganisation: 'zorgpartner-bv',
			at: '2026-05-01',
		);

		self::assertFalse($verdict['allowed']);
		self::assertSame(CaseTransferConsentGate::CONSENT_WITHDRAWN, $verdict['rule']);
	}//end testAWithdrawnConsentIsNamedAsWithdrawn()

	/**
	 * A move between two teams of one organisation needs no consent at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	public function testAMoveInsideOneOrganisationIsNotBlocked(): void {
		$verdict = $this->gate()->assess(
			caseId: 'case-1',
			sourceOrganisation: 'gemeente-delft',
			receivingOrganisation: 'gemeente-delft',
			at: '2026-05-01',
		);

		self::assertTrue($verdict['allowed'], 'A move inside one organisation is not a disclosure.');
		self::assertFalse($verdict['crossesOrganisation']);
		self::assertSame([], $verdict['scope'], 'There is nothing to scope when nothing is disclosed.');
	}//end testAMoveInsideOneOrganisationIsNotBlocked()

	/**
	 * A case type may say that an internal move needs consent too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	public function testACaseTypeCanDemandConsentInsideTheOrganisation(): void {
		$this->store->seed(
			schema: 'caseType',
			uuid: 'ct-jeugd',
			row: ['title' => 'Jeugdwet-melding', 'consentRequiredInsideOrganisation' => true],
		);
		$this->store->seed(
			schema: 'case',
			uuid: 'case-2',
			row: ['title' => 'Jeugdwet-melding', 'caseType' => 'ct-jeugd', 'assignedGroup' => 'jeugdteam'],
		);

		$verdict = $this->gate()->assess(
			caseId: 'case-2',
			sourceOrganisation: 'gemeente-delft',
			receivingOrganisation: 'gemeente-delft',
			at: '2026-05-01',
		);

		self::assertFalse($verdict['allowed'], 'A Jeugdwet file and a parking permit are not the same question.');
		self::assertSame(CaseTransferConsentGate::NO_CONSENT, $verdict['rule']);
	}//end testACaseTypeCanDemandConsentInsideTheOrganisation()

	/**
	 * A hand-off that cannot name where the case is going is refused.
	 *
	 * The one case where guessing "internal" would skip the gate entirely.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	public function testAHandOffWithNoNamedReceiverIsRefused(): void {
		$verdict = $this->gate()->assess(
			caseId: 'case-1',
			sourceOrganisation: 'gemeente-delft',
			receivingOrganisation: '',
			at: '2026-05-01',
		);

		self::assertFalse($verdict['allowed']);
		self::assertTrue($verdict['crossesOrganisation']);
	}//end testAHandOffWithNoNamedReceiverIsRefused()

	/**
	 * A consent naming a different partner does not cover this receiver.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	public function testAConsentForSomebodyElseDoesNotCoverThisReceiver(): void {
		$this->recordConsent(validTo: '2026-12-31', recipient: 'andere-zorgpartner');

		$verdict = $this->gate()->assess(
			caseId: 'case-1',
			sourceOrganisation: 'gemeente-delft',
			receivingOrganisation: 'zorgpartner-bv',
			at: '2026-05-01',
		);

		self::assertFalse($verdict['allowed']);
		self::assertSame(CaseTransferConsentGate::NO_CONSENT, $verdict['rule']);
	}//end testAConsentForSomebodyElseDoesNotCoverThisReceiver()

	/**
	 * Record one consent on the case.
	 *
	 * @param string $validTo   The day it stops covering.
	 * @param bool   $withdrawn Whether it was revoked.
	 * @param string $recipient The organisation it names.
	 *
	 * @return void
	 */
	private function recordConsent(string $validTo, bool $withdrawn = false, string $recipient = 'zorgpartner-bv'): void {
		$this->store->seed(
			schema: 'toestemming',
			uuid: 'consent-1',
			row: [
				'caseId' => 'case-1',
				'grantedByBsn' => '999990627',
				'grantedDate' => '2026-01-10',
				'validTo' => $validTo,
				'withdrawn' => $withdrawn,
				'recipientParties' => [$recipient],
				'tegegevens' => ['ondersteuningsplan'],
				'scope' => 'Alleen het ondersteuningsplan',
			],
		);
	}//end recordConsent()

	/**
	 * The gate under test.
	 *
	 * @return CaseTransferConsentGate The gate.
	 */
	private function gate(): CaseTransferConsentGate {
		return new CaseTransferConsentGate(
			settingsService: $this->settings(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end gate()

	/**
	 * A settings service that answers the in-memory store and the slugs it holds.
	 *
	 * @return SettingsService The settings service.
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_type_schema' => 'caseType',
					'consent_schema' => 'toestemming',
				];

				return ($map[$key] ?? $default);
			}
		);

		return $settings;
	}//end settings()
}//end class
