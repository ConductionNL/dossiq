<?php

/**
 * The scope the consent names travels with the share it allows.
 *
 * Row 13.28's defect, in one sentence: `toestemming` was declared and nothing
 * read it, so `createPartnerShare` wrote a share with no scope and nothing was
 * ever blocked. These tests are the two halves of the fix. A share is not
 * written at all without a covering consent, and the share that IS written
 * carries the consent, its scope and the day it runs out, so the refusal and
 * the share cannot disagree (D-6).
 *
 * MUTATION-CHECKED 2026-09-18: dropping the `consentScope` key from the share
 * payload in CaseSharingService::createPartnerShare() reddens
 * testTheShareCarriesTheScopeTheConsentNames on the scope assertion; removing
 * the `$verdict['allowed'] === false` return reddens
 * testNoShareIsWrittenWithoutAConsent on the store count. Restored after.
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

use OCA\Dossiq\Service\CaseSharingService;
use OCA\Dossiq\Service\Custody\CaseTransferConsentGate;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Sharing\CaseAccessLinkService;
use OCA\Dossiq\Service\Sharing\CaseAccessPolicy;
use OCA\Dossiq\Service\Sharing\CaseLinkShares;
use OCA\Dossiq\Service\Sharing\FederatedCaseShareService;
use OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One saved share, in the shape OpenRegister hands back.
 *
 * A real object rather than an array, because `createPartnerShare` reads the
 * result through `getUuid()` and `jsonSerialize()`. An array double would make
 * the method fatal rather than assert anything.
 */
final class PssSavedShare implements \JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param string               $uuid The share uuid.
	 * @param array<string, mixed> $data The stored payload.
	 */
	public function __construct(private string $uuid, private array $data) {
	}//end __construct()

	/**
	 * The uuid.
	 *
	 * @return string The uuid.
	 */
	public function getUuid(): string {
		return $this->uuid;
	}//end getUuid()

	/**
	 * The payload.
	 *
	 * @return array<string, mixed> The payload.
	 */
	public function jsonSerialize(): array {
		return array_merge($this->data, ['id' => $this->uuid]);
	}//end jsonSerialize()
}//end class

/**
 * The store the share is written into.
 */
final class PssShareStore {

	/**
	 * Every share written.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $shares = [];

	/**
	 * Store one share.
	 *
	 * @param array<string, mixed> $object   The payload.
	 * @param int|string           $register Ignored.
	 * @param int|string           $schema   Ignored.
	 * @param string|null          $uuid     Ignored.
	 *
	 * @return PssSavedShare The saved share.
	 */
	public function saveObject(array $object, int|string $register = '', int|string $schema = '', ?string $uuid = null): PssSavedShare {
		$this->shares[] = $object;

		return new PssSavedShare(uuid: ('share-' . count($this->shares)), data: $object);
	}//end saveObject()
}//end class

/**
 * No share without a scope, and no scope without a consent.
 *
 * @covers \OCA\Dossiq\Service\CaseSharingService::createPartnerShare
 * @uses \OCA\Dossiq\Service\Custody\CaseTransferConsentGate
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Sharing\CaseAccessLinkService
 * @uses \OCA\Dossiq\Service\Sharing\CaseAccessPolicy
 * @uses \OCA\Dossiq\Service\Sharing\CaseLinkShares
 * @uses \OCA\Dossiq\Service\Sharing\FederatedCaseShareService
 * @uses \OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway
 * @uses \OCA\Dossiq\Service\CaseSharingService
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
class PartnerShareScopeTest extends TestCase {

	/**
	 * The store the consent gate reads.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The store the share is written into.
	 *
	 * @var PssShareStore
	 */
	private PssShareStore $shares;

	/**
	 * A Wmo case that may not leave without consent.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->shares = new PssShareStore();

		$this->store->seed(
			schema: 'caseType',
			uuid: 'ct-wmo',
			row: ['title' => 'Wmo-melding', 'consentRequiredInsideOrganisation' => false],
		);
		$this->store->seed(
			schema: 'case',
			uuid: 'case-1',
			row: ['title' => 'Wmo-melding', 'caseType' => 'ct-wmo', 'assignedGroup' => 'wijkteam'],
		);
	}//end setUp()

	/**
	 * Without a consent, no share is written at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-the-recorded-scope-travels-with-the-share-req-cst-02
	 */
	public function testNoShareIsWrittenWithoutAConsent(): void {
		$answer = $this->sharing()->createPartnerShare(
			caseId: 'case-1',
			partnerId: 'zorgpartner-bv',
			permissionLevel: 'read',
			createdBy: 'jan',
		);

		self::assertArrayHasKey('error', $answer, 'A share with no scope is the defect, so it is not written.');
		self::assertSame(CaseTransferConsentGate::NO_CONSENT, $answer['rule']);
		self::assertSame([], $this->shares->shares, 'Nothing reached the register.');
	}//end testNoShareIsWrittenWithoutAConsent()

	/**
	 * The share carries the consent, its scope and the day it runs out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-the-recorded-scope-travels-with-the-share-req-cst-02
	 */
	public function testTheShareCarriesTheScopeTheConsentNames(): void {
		$this->store->seed(
			schema: 'toestemming',
			uuid: 'consent-1',
			row: [
				'caseId' => 'case-1',
				'grantedByBsn' => '999990627',
				'grantedDate' => '2026-01-10',
				'validTo' => '2099-12-31',
				'withdrawn' => false,
				'recipientParties' => ['zorgpartner-bv'],
				'tegegevens' => ['ondersteuningsplan'],
			],
		);

		$share = $this->sharing()->createPartnerShare(
			caseId: 'case-1',
			partnerId: 'zorgpartner-bv',
			permissionLevel: 'read',
			createdBy: 'jan',
		);

		self::assertArrayNotHasKey('error', $share);
		self::assertCount(1, $this->shares->shares);

		$written = $this->shares->shares[0];
		self::assertSame(
			['ondersteuningsplan'],
			$written['consentScope'],
			'The partner sees only what the consent names, so the share has to carry it.',
		);
		self::assertSame('consent-1', $written['consentId'], 'And it names the consent it was allowed by.');
		self::assertSame('2099-12-31', $written['consentUntil'], 'And the day that permission ends.');
		self::assertSame(['ondersteuningsplan'], $share['consentScope'], 'The caller is told the same thing that was stored.');
	}//end testTheShareCarriesTheScopeTheConsentNames()

	/**
	 * A consent for a different partner writes no share for this one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-the-recorded-scope-travels-with-the-share-req-cst-02
	 */
	public function testAConsentForAnotherPartnerWritesNoShare(): void {
		$this->store->seed(
			schema: 'toestemming',
			uuid: 'consent-1',
			row: [
				'caseId' => 'case-1',
				'grantedByBsn' => '999990627',
				'grantedDate' => '2026-01-10',
				'validTo' => '2099-12-31',
				'recipientParties' => ['andere-zorgpartner'],
				'tegegevens' => ['ondersteuningsplan'],
			],
		);

		$answer = $this->sharing()->createPartnerShare(
			caseId: 'case-1',
			partnerId: 'zorgpartner-bv',
			permissionLevel: 'read',
			createdBy: 'jan',
		);

		self::assertArrayHasKey('error', $answer);
		self::assertSame([], $this->shares->shares);
	}//end testAConsentForAnotherPartnerWritesNoShare()

	/**
	 * The sharing service under test, with a real consent gate.
	 *
	 * @return CaseSharingService The service.
	 */
	private function sharing(): CaseSharingService {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$settings = $this->settings();

		$gateway = $this->createMock(originalClassName: OpenRegisterSharingGateway::class);
		$gateway->method('objectService')->willReturn($this->shares);

		return new CaseSharingService(
			settingsService: $settings,
			gateway: $gateway,
			accessPolicy: $this->createStub(CaseAccessPolicy::class),
			accessLinks: $this->createStub(CaseAccessLinkService::class),
			linkShares: $this->createStub(CaseLinkShares::class),
			federatedShares: $this->createStub(FederatedCaseShareService::class),
			consent: new CaseTransferConsentGate(settingsService: $settings, logger: $logger),
			logger: $logger,
		);
	}//end sharing()

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
					'case_share_schema' => 'caseShare',
				];

				return ($map[$key] ?? $default);
			}
		);

		return $settings;
	}//end settings()
}//end class
