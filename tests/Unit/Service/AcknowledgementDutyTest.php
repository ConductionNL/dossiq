<?php

/**
 * AcknowledgementService unit tests: the duty, the record and the refusal.
 *
 * The three intake paths that owe an Awb 4:3a confirmation all end in a `case`
 * object, so what tells them apart is `intakeChannel`, and each is driven here
 * as its own case: the portal writes `website`, the mail intake writes `email`,
 * the ZGW API intake writes `zgw-api`. A case typed at the balie is driven too,
 * because sending one anyway is its own defect.
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
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Service\AcknowledgementService;
use OCA\Dossiq\Service\BerichtenboxRoutingService;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\CaseTypeAcknowledgement;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An in-memory case store with the PATCH seam the writer prefers.
 *
 * `patchObject` is implemented on purpose: the fallback path in
 * {@see \OCA\Dossiq\Service\Support\SearchesObjects} re-reads and full-saves,
 * and testing against the fallback would hide a clobber the real seam prevents.
 */
class AcknowledgementCaseStore {

	/**
	 * Stored cases, keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $cases = [];

	/**
	 * Find one case.
	 *
	 * @param string $id       The case id.
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 *
	 * @return array<string, mixed>|null The case, or null.
	 */
	public function find(string $id, string $register = '', string $schema = ''): ?array {
		return ($this->cases[$id] ?? null);
	}//end find()

	/**
	 * Apply a partial change to one case.
	 *
	 * @param string               $objectId The case id.
	 * @param array<string, mixed> $data     The fields to apply.
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 *
	 * @return array<string, mixed> The case as it now stands.
	 */
	public function patchObject(
		string $objectId,
		array $data,
		string $register = '',
		string $schema = '',
	): array {
		$this->cases[$objectId] = array_merge(($this->cases[$objectId] ?? []), $data);

		return $this->cases[$objectId];
	}//end patchObject()
}//end class

/**
 * Confirming receipt, recording it, and refusing when there is nowhere to send.
 *
 * @covers \OCA\Dossiq\Service\AcknowledgementService
 *
 * @uses \OCA\Dossiq\Portal\PortalContributionProvider
 * @uses \OCA\Dossiq\Service\BerichtenboxRoutingService
 * @uses \OCA\Dossiq\Service\CaseFieldWriter
 * @uses \OCA\Dossiq\Service\CaseTypeAcknowledgement
 * @uses \OCA\Dossiq\Service\Email\CaseContactDirectory
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\TermijnNotificationService
 */
class AcknowledgementDutyTest extends TestCase {

	/**
	 * The in-memory case store.
	 *
	 * @var AcknowledgementCaseStore
	 */
	private AcknowledgementCaseStore $store;

	/**
	 * The bound statutory term, when the test wants one.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $term = ['id' => 'ti-1', 'case' => '2026-0042', 'endDateCurrent' => '2026-11-01'];

	/**
	 * What the case type declares.
	 *
	 * @var array<string, mixed>
	 */
	private array $caseType = [];

	/**
	 * Set up the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new AcknowledgementCaseStore();
	}//end setUp()

	/**
	 * The service, wired against the in-memory store.
	 *
	 * @return AcknowledgementService The service under test.
	 */
	private function service(): AcknowledgementService {
		$logger = $this->createMock(LoggerInterface::class);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => 'case',
					default => '',
				};
			}
		);

		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(fn (): array => $this->caseType);

		$store = $this->createMock(CaseTypeStore::class);
		$store->method('referenceId')->willReturnCallback(
			static fn (mixed $value): string => (string)$value
		);

		$terms = $this->createMock(TermijnService::class);
		$terms->method('getTermijnInstanceForZaak')->willReturnCallback(fn (): ?array => $this->term);

		return new AcknowledgementService(
			settingsService: $settings,
			caseTypeResolver: $resolver,
			store: $store,
			declaration: new CaseTypeAcknowledgement(),
			termService: $terms,
			notifications: new TermijnNotificationService(
				$this->createMock(TermijnService::class),
				new BerichtenboxRoutingService($logger),
				$logger
			),
			contacts: new CaseContactDirectory(),
			portal: new PortalContributionProvider(),
			writer: new CaseFieldWriter(),
			logger: $logger,
		);
	}//end service()

	/**
	 * Seed one case in the store.
	 *
	 * @param array<string, mixed> $overrides Fields to set on the case.
	 *
	 * @return string The case id.
	 */
	private function seedCase(array $overrides = []): string {
		$case = array_merge(
			[
				'id' => 'case-1',
				'caseType' => 'ct-1',
				'identifier' => '2026-0042',
				'title' => 'Dakkapel Kerkstraat 12',
				'intakeChannel' => 'website',
				'email' => 'aanvrager@example.nl',
			],
			$overrides
		);

		$this->store->cases[$case['id']] = $case;

		return (string)$case['id'];
	}//end seedCase()

	/**
	 * Every electronic intake path sends one, once, and records it.
	 *
	 * @param string $channel The intake channel.
	 *
	 * @return void
	 *
	 * @dataProvider electronicIntakePaths
	 */
	public function testAnElectronicIntakeIsConfirmedOnceAndRecorded(string $channel): void {
		$caseId = $this->seedCase(['intakeChannel' => $channel]);
		$service = $this->service();

		$first = $service->acknowledge(caseId: $caseId);
		self::assertTrue($first['sent'], $channel . ' owes a confirmation of receipt');
		self::assertSame(AcknowledgementService::STATUS_MET, $first['duty']['status']);

		$stored = $this->store->cases[$caseId];
		self::assertCount(1, $stored['outboundCommunications']);

		$record = $stored['outboundCommunications'][0];
		self::assertSame('case-received', $record['moment']);
		self::assertSame('email', $record['channel']);
		self::assertSame('aanvrager@example.nl', $record['recipient']);
		self::assertSame('ontvangstbevestiging', $record['template']);
		self::assertSame(AcknowledgementService::TEMPLATE_VERSION, $record['templateVersion']);
		self::assertNotSame('', (string)$record['sentAt']);

		// Sending twice is worse than sending late: the citizen reads the
		// second one as a second case.
		$second = $service->acknowledge(caseId: $caseId);
		self::assertFalse($second['sent']);
		self::assertSame('already-met', $second['reason']);
		self::assertCount(1, $this->store->cases[$caseId]['outboundCommunications']);
	}//end testAnElectronicIntakeIsConfirmedOnceAndRecorded()

	/**
	 * The three electronic intake paths.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function electronicIntakePaths(): array {
		return [
			'the portal aanvraag' => ['website'],
			'the mail intake' => ['email'],
			'the API case creation' => ['zgw-api'],
		];
	}//end electronicIntakePaths()

	/**
	 * A case typed at the balie is not mailed.
	 *
	 * @return void
	 */
	public function testACaseTypedAtTheBalieIsNotMailed(): void {
		$caseId = $this->seedCase(['intakeChannel' => 'balie']);

		$result = $this->service()->acknowledge(caseId: $caseId);

		self::assertFalse($result['sent']);
		self::assertSame('not-required', $result['reason']);
		self::assertSame(
			AcknowledgementService::STATUS_NOT_REQUIRED,
			$this->store->cases[$caseId]['acknowledgementDuty']['status']
		);
		self::assertArrayNotHasKey('outboundCommunications', $this->store->cases[$caseId]);
	}//end testACaseTypedAtTheBalieIsNotMailed()

	/**
	 * A case with no address refuses, with a status and a sentence.
	 *
	 * 🔴 NOT AN EMPTY SEND. A recipient of '' handed to a mail transport is a
	 * message that goes nowhere and reports success, which is the exact shape
	 * that let this duty ship unperformed the first time.
	 *
	 * @return void
	 */
	public function testACaseWithNoAddressRefusesWithAStatusAndASentence(): void {
		$caseId = $this->seedCase(['email' => null]);

		try {
			$this->service()->acknowledge(caseId: $caseId);
			self::fail('a case with no address must refuse rather than send nowhere');
		} catch (RefusedException $e) {
			self::assertSame('acknowledgement-no-address', $e->getRule());
			self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
			self::assertStringContainsString('no address', $e->getSentence());
		}

		self::assertArrayNotHasKey('outboundCommunications', $this->store->cases[$caseId]);
	}//end testACaseWithNoAddressRefusesWithAStatusAndASentence()

	/**
	 * The address a mail-filed case carries is the sender it came from.
	 *
	 * @return void
	 */
	public function testTheMailIntakeSenderIsAnAddress(): void {
		$caseId = $this->seedCase(
			['email' => null, 'intakeChannel' => 'email', 'initiatorSourceId' => 'Afzender@Example.NL']
		);

		$result = $this->service()->acknowledge(caseId: $caseId);

		self::assertTrue($result['sent']);
		self::assertSame('afzender@example.nl', $result['duty']['recipient']);
	}//end testTheMailIntakeSenderIsAnAddress()

	/**
	 * A failed attempt stays pending while retries are left, then reads unmet.
	 *
	 * @return void
	 */
	public function testAFailureIsPendingThenUnmetAndNeverOnlyALogLine(): void {
		$caseId = $this->seedCase();
		$service = $this->service();

		self::assertTrue($service->recordFailedAttempt(caseId: $caseId, sentence: 'SMTP refused', attempt: 1));
		self::assertSame(
			AcknowledgementService::STATUS_PENDING,
			$this->store->cases[$caseId]['acknowledgementDuty']['status']
		);

		self::assertFalse(
			$service->recordFailedAttempt(
				caseId: $caseId,
				sentence: 'SMTP refused',
				attempt: AcknowledgementService::MAX_ATTEMPTS,
			)
		);

		$duty = $this->store->cases[$caseId]['acknowledgementDuty'];
		self::assertSame(AcknowledgementService::STATUS_UNMET, $duty['status']);
		self::assertSame('SMTP refused', $duty['lastError']);
		self::assertSame(AcknowledgementService::MAX_ATTEMPTS, $duty['attempts']);
	}//end testAFailureIsPendingThenUnmetAndNeverOnlyALogLine()

	/**
	 * A handler records that receipt was confirmed another way.
	 *
	 * The record has to name who said so and when, because an auditor asking
	 * "did we confirm receipt" is entitled to more than a green tick.
	 *
	 * @return void
	 */
	public function testAHandlerCanRecordThatItWasMetAnotherWay(): void {
		$caseId = $this->seedCase();
		$service = $this->service();
		$service->recordFailedAttempt(caseId: $caseId, sentence: 'SMTP refused', attempt: 3);

		$duty = $service->recordMetAnotherWay(caseId: $caseId, how: 'Confirmed by post', by: 'ruben');

		self::assertSame(AcknowledgementService::STATUS_MET, $duty['status']);
		self::assertSame('ruben', $duty['metBy']);
		self::assertSame('Confirmed by post', $duty['metHow']);
		self::assertNotSame('', (string)$duty['metAt']);
		self::assertCount(1, $this->store->cases[$caseId]['outboundCommunications']);
	}//end testAHandlerCanRecordThatItWasMetAnotherWay()

	/**
	 * Clearing the duty without saying how is refused.
	 *
	 * @return void
	 */
	public function testClearingTheDutyWithoutSayingHowIsRefused(): void {
		$caseId = $this->seedCase();

		$this->expectException(RefusedException::class);
		$this->service()->recordMetAnotherWay(caseId: $caseId, how: '  ', by: 'ruben');
	}//end testClearingTheDutyWithoutSayingHowIsRefused()

	/**
	 * A case with no statutory term still confirms receipt, and says so.
	 *
	 * @return void
	 */
	public function testACaseWithNoTermStillConfirmsReceipt(): void {
		$this->term = null;
		$caseId = $this->seedCase();

		$result = $this->service()->acknowledge(caseId: $caseId);

		self::assertTrue($result['sent']);
		self::assertStringContainsString('geen wettelijke beslistermijn', $result['payload']['body']);
	}//end testACaseWithNoTermStillConfirmsReceipt()

	/**
	 * A field the citizen may not see is not quoted back.
	 *
	 * The quotable set is the portal's own list, read from the portal rather
	 * than kept as a second copy that diverges quietly.
	 *
	 * @return void
	 */
	public function testAFieldTheCitizenMayNotSeeIsNotQuotedBack(): void {
		$quotable = $this->service()->quotableFieldsOf(
			case: [
				'identifier' => '2026-0042',
				'title' => 'Dakkapel',
				'initiatorSourceId' => '123456782',
				'assignee' => 'ruben',
				'portalSubject' => 'sub-1',
			]
		);

		self::assertSame(['identifier', 'title'], array_keys($quotable));
		self::assertArrayNotHasKey('initiatorSourceId', $quotable);
		self::assertArrayNotHasKey('assignee', $quotable);
		self::assertSame(
			PortalContributionProvider::CITIZEN_CASE_FIELDS,
			(new PortalContributionProvider())->citizenCaseFields(),
			'the acknowledgement reads the portal\'s own list, not a second copy'
		);
	}//end testAFieldTheCitizenMayNotSeeIsNotQuotedBack()

	/**
	 * Content that stays on the platform leaves the case out of the e-mail.
	 *
	 * @return void
	 */
	public function testContentOnThePlatformCarriesNoCaseContent(): void {
		$this->caseType = ['acknowledgement' => ['contentOnPlatform' => true]];
		$caseId = $this->seedCase();

		$result = $this->service()->acknowledge(caseId: $caseId);

		self::assertTrue($result['sent']);
		self::assertTrue($result['record']['contentWithheld']);
		self::assertStringNotContainsString('2026-0042', $result['payload']['body']);
		self::assertStringNotContainsString('Dakkapel', $result['payload']['body']);
		self::assertStringContainsString('bericht', $result['payload']['body']);
	}//end testContentOnThePlatformCarriesNoCaseContent()

	/**
	 * A citizen who chose the portal is not mailed, and the record says so.
	 *
	 * @return void
	 */
	public function testTheCitizensRecordedChannelIsTheOneRecorded(): void {
		$caseId = $this->seedCase(['communicationChannel' => 'portal']);

		$result = $this->service()->acknowledge(caseId: $caseId);

		self::assertSame('portal', $result['record']['channel']);
		self::assertSame('portal', $this->store->cases[$caseId]['acknowledgementDuty']['channel']);
	}//end testTheCitizensRecordedChannelIsTheOneRecorded()

	/**
	 * The message carries the kenmerk and the end date of the term.
	 *
	 * @return void
	 */
	public function testTheMessageCarriesTheKenmerkAndTheDeadline(): void {
		$caseId = $this->seedCase();

		$result = $this->service()->acknowledge(caseId: $caseId);

		self::assertStringContainsString('2026-0042', $result['payload']['subject']);
		self::assertStringContainsString('2026-11-01', $result['payload']['body']);
		self::assertStringContainsString('Dakkapel Kerkstraat 12', $result['payload']['body']);
	}//end testTheMessageCarriesTheKenmerkAndTheDeadline()
}//end class
