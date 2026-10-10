<?php

/**
 * BeschikkingService Unit / Lifecycle Tests.
 *
 * Drives the full beschikking lifecycle (compose -> akkoord -> onderteken ->
 * verzend -> archive) against an in-memory ObjectService fake and the real
 * mock cross-app adapters, asserting state transitions, mandaat rejection,
 * immutability, BezwaarTrigger creation, and a verifiable audit-pakket.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\BerichtenboxService;
use OCA\Dossiq\Service\Beschikking\AuditPacketBuilder;
use OCA\Dossiq\Service\Beschikking\BeschikkingDelivery;
use OCA\Dossiq\Service\Beschikking\BeschikkingRepository;
use OCA\Dossiq\Service\Beschikking\BezwaarTermijnScheduler;
use OCA\Dossiq\Service\Beschikking\CaseRemedy;
use OCA\Dossiq\Service\People\CoordinatorRequirement;
use OCA\Dossiq\Service\Beschikking\MandaatVerifier;
use OCA\Dossiq\Service\Beschikking\MockSigningAdapter;
use OCA\Dossiq\Service\Beschikking\MockTemplateEngineAdapter;
use OCA\Dossiq\Service\Beschikking\OpenRegisterArchivalAdapter;
use OCA\Dossiq\Service\BeschikkingService;
use OCA\Dossiq\Service\Notification\RequesterNoticeSender;
use OCA\Dossiq\Service\Termijn\TermNoticeSender;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StateMachineService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * In-memory ObjectService fake.
 *
 * Mirrors the subset of the OpenRegister ObjectService API the beschikking
 * pipeline relies on: find (named id/register/schema), searchObjectsBySlug
 * (positional), and saveObject (positional, assigns ids and persists).
 */
class FakeObjectService {

	/**
	 * Stored objects keyed by schema then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * Auto-increment id counter.
	 *
	 * @var integer
	 */
	private int $seq = 0;

	/**
	 * Find a single object by id.
	 *
	 * @param string $id The object id.
	 * @param string $register The register id (named).
	 * @param string $schema The schema id (named).
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $id, string $register = '', string $schema = ''): ?array {
		return ($this->store[$schema][$id] ?? null);
	}//end find()

	/**
	 * Search objects by simple equality filters (real searchObjectsBySlug()).
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $filters Equality filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
		$rows = array_values($this->store[$schema] ?? []);

		return array_values(
			array_filter(
				$rows,
				static function (array $row) use ($filters): bool {
					foreach ($filters as $key => $value) {
						// `_limit`, `_page` and friends are query parameters on the
						// real service, not object fields.
						if (str_starts_with((string)$key, '_') === true) {
							continue;
						}

						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				},
			)
		);
	}//end searchObjectsBySlug()

	/**
	 * Persist an object, assigning an id when absent.
	 *
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param array<string, mixed> $object The object payload.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(string $register, string $schema, array $object): array {
		if (empty($object['id']) === true) {
			$this->seq++;
			$object['id'] = $schema . '-' . $this->seq;
		}

		$this->store[$schema][$object['id']] = $object;

		return $object;
	}//end saveObject()
}//end class

/**
 * Unit tests for BeschikkingService.
 *
 * @covers \OCA\Dossiq\Service\BeschikkingService
 *
 * @uses \OCA\Dossiq\Service\Beschikking\BeschikkingDelivery
 * @uses \OCA\Dossiq\Service\Notification\RequesterNoticeSender
 * @uses \OCA\Dossiq\Service\Beschikking\AuditPacketBuilder
 * @uses \OCA\Dossiq\Service\Beschikking\BeschikkingRepository
 * @uses \OCA\Dossiq\Service\Beschikking\BezwaarTermijnScheduler
 * @uses \OCA\Dossiq\Service\Beschikking\MandaatVerifier
 * @uses \OCA\Dossiq\Service\Beschikking\MockSigningAdapter
 * @uses \OCA\Dossiq\Service\Beschikking\MockTemplateEngineAdapter
 * @uses \OCA\Dossiq\Service\Beschikking\OpenRegisterArchivalAdapter
 * @uses \OCA\Dossiq\Service\StateMachineService
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class BeschikkingServiceTest extends TestCase {

	/**
	 * The in-memory object store.
	 *
	 * @var FakeObjectService
	 */
	private FakeObjectService $objects;

	/**
	 * The mocked timeline seam, and what it was handed.
	 *
	 * @var CaseTimeline|MockObject
	 */
	private CaseTimeline $timeline;

	/**
	 * Every payload the seam was handed.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $entries = [];

	/**
	 * The service under test.
	 *
	 * @var BeschikkingService
	 */
	private BeschikkingService $service;

	/**
	 * The remedy the case's decisions carry, driven per test.
	 *
	 * A double answers '' and 0 unless told otherwise, which is exactly a case
	 * type that declares no remedy, so every test written before the
	 * declaration existed keeps its six weeks.
	 *
	 * @var CaseRemedy&\PHPUnit\Framework\MockObject\MockObject
	 */
	private CaseRemedy $remedy;

	/**
	 * What digital post answers, driven per test. A tracked message by default.
	 *
	 * @var array<string, mixed>
	 */
	private array $postAnswer = [];

	/**
	 * Every digital-post send, as handed to the transport.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $posted = [];

	/**
	 * Set up fixtures with a wired-up service graph.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->entries = [];
		$this->timeline = $this->createMock(CaseTimeline::class);
		$this->timeline->method('record')->willReturnCallback(
			function (
				string $caseId,
				string $kind,
				string $message,
				array $fields = [],
				string $visibility = 'internal',
				array $relatedCaseIds = [],
			): string {
				$this->entries[] = compact('caseId', 'kind', 'message', 'fields', 'visibility');

				return 'entry-' . count($this->entries);
			}
		);

		$this->objects = new FakeObjectService();
		$this->remedy = $this->createMock(originalClassName: CaseRemedy::class);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'beschikking_schema' => 'beschikking',
					'state_machine_log_schema' => 'stateMachineLog',
					'bezwaar_trigger_schema' => 'bezwaarTrigger',
					'mandaat_regeling_schema' => 'mandaatRegeling',
					default => '',
				};
			},
		);

		$logger = $this->createMock(LoggerInterface::class);
		$stateMachine = new StateMachineService($settings, $logger);

		// The real sender over a digital-post double: the transport is the
		// one thing a unit test may not call, and what it answers is exactly
		// what verzend() must act on.
		$this->posted = [];
		$this->postAnswer = ['externalMessageId' => 'mijnoverheid-msg-1', 'sentAt' => '2026-10-10T09:00:00+02:00'];
		$post = $this->createMock(BerichtenboxService::class);
		$post->method('sendMessage')->willReturnCallback(
			function (string $caseId, string $bsn, string $subject, string $body, string $typeCode, ?string $attachmentFileId = null, string $category = 'case-update'): array {
				$this->posted[] = compact('caseId', 'bsn', 'subject', 'body', 'typeCode', 'category');

				return $this->postAnswer;
			}
		);
		$cases = $this->createMock(CaseStatusStore::class);
		$cases->method('loadCase')->willReturn(['id' => 'zaak-2026-wmo-1', 'identifier' => 'ZAAK-2026-1']);
		$delivery = new BeschikkingDelivery(
			new RequesterNoticeSender(email: $this->createMock(TermNoticeSender::class), digitalPost: $post),
			$cases,
		);

		$signingAdapter = new MockSigningAdapter();

		$this->service = new BeschikkingService(
			$stateMachine,
			$delivery,
			new MockTemplateEngineAdapter(),
			$signingAdapter,
			new OpenRegisterArchivalAdapter($this->createMock(ContainerInterface::class), $logger),
			new BeschikkingRepository($settings, $logger),
			new MandaatVerifier($settings, $logger),
			new AuditPacketBuilder($settings, $signingAdapter, $logger),
			new BezwaarTermijnScheduler($settings, $logger),
			$this->createMock(CoordinatorRequirement::class),
			$this->timeline,
			$this->remedy,
		);

		// Seed a WMO mandaatregeling covering the afdelingsmanager level.
		$this->objects->saveObject(
			'dossiq',
			'mandaatRegeling',
			[
				'id' => 'mr-2024-007-wmo',
				'name' => 'Mandaatregeling WMO',
				'mandateGroups' => [
					['level' => 'consulent', 'to_amount' => 5000, 'caseTypes' => ['wmo-melding'], 'decisionTypes' => ['toekenning']],
					['level' => 'afdelingsmanager', 'to_amount' => 25000, 'caseTypes' => ['wmo-melding'], 'decisionTypes' => ['toekenning', 'rejection']],
				],
			]
		);
	}//end setUp()

	/**
	 * Compose a beschikking in the ontwerp status with a rendered PDF.
	 *
	 * @param array<string, mixed> $addressee Who the beschikking is addressed to.
	 *
	 * @return array<string, mixed> The composed beschikking (for chaining).
	 */
	private function composeWmo(array $addressee = ['type' => 'burger', 'bsn' => '123456789', 'name' => 'M. Jansen', 'messageBoxConfirmed' => true]): array {
		$decision = $this->service->compose(
			'zaak-2026-wmo-1',
			'tpl-wmo-v1',
			[
				'decisionType' => 'toekenning',
				'addressee' => $addressee,
				'rationale' => 'Toegekend op basis van onderzoek.',
			],
		);

		// The compose path does not set zaaktype/legesbedrag; patch them in
		// (ontwerp status permits edits) so the mandaat lookup can resolve.
		return $this->service->updateFields($decision['id'], ['caseType' => 'wmo-melding', 'feeAmount' => 4000]);
	}//end composeWmo()

	/**
	 * Composition produces a draft with PDF/A-3 composition metadata. [T05]
	 *
	 * @return void
	 */
	public function testComposeCreatesDraft(): void {
		$decision = $this->composeWmo();

		$this->assertSame('draft', $decision['currentStatus']);
		$this->assertSame('pdf-a3', $decision['compositeContent']['format']);
		$this->assertNotEmpty($decision['compositeContent']['fileId']);
	}//end testComposeCreatesDraft()

	/**
	 * The resolved template version is stored on the beschikking.
	 *
	 * 🔴 IT WAS RESOLVED AND DROPPED. `compose()` called the adapter's
	 * `resolveVersion()` and then used only its `templateId`, so every
	 * beschikking recorded which template made it and never which version of
	 * it. Editing a template after a decision issued left the appeal against
	 * that decision reading text nobody ever sent. This asserts the effect —
	 * the value on the STORED object — and not the adapter's return value,
	 * which was already correct and already going nowhere.
	 *
	 * @return void
	 */
	public function testComposeStoresTheResolvedTemplateVersion(): void {
		$decision = $this->composeWmo();

		$this->assertArrayHasKey('templateVersion', $decision, 'the version must be on the object');
		$this->assertSame('v1', $decision['templateVersion']);

		$stored = $this->service->find($decision['id']);
		$this->assertSame('v1', ($stored['templateVersion'] ?? null), 'and must survive the round trip');
	}//end testComposeStoresTheResolvedTemplateVersion()

	/**
	 * A besluit prints the clause its case type declares (REQ-DEC-03).
	 *
	 * The clause is read from the case, not from the template, which is the
	 * whole point: the same template on two case types prints two terms.
	 *
	 * @return void
	 */
	public function testComposePrintsTheClauseTheCaseTypeDeclares(): void {
		$this->remedy->method('clauseFor')->willReturnMap(
			[['zaak-2026-wmo-1', 'U kunt bezwaar maken tegen dit besluit. Doe dat binnen 42 dagen bij het college.']]
		);

		$decision = $this->composeWmo();

		$this->assertSame(
			expected: 'U kunt bezwaar maken tegen dit besluit. Doe dat binnen 42 dagen bij het college.',
			actual: ($this->service->find($decision['id'])['legalRemediesClause'] ?? null),
		);
	}//end testComposePrintsTheClauseTheCaseTypeDeclares()

	/**
	 * Sending the besluit binds the remedy clock on the declared term (REQ-DEC-04).
	 *
	 * @return void
	 */
	public function testSendingBindsTheRemedyTermOnTheDeclaredDays(): void {
		$this->remedy->method('termDaysFor')->willReturn(28);
		$this->remedy->expects($this->once())->method('bindTerm')->with(
			'zaak-2026-wmo-1',
			$this->isType(type: 'string'),
			$this->isInstanceOf(className: \DateTimeImmutable::class),
		);

		$id = $this->composeWmo()['id'];
		$this->service->akkoord($id, 'afdelingsmanager-wmo-15');
		$this->service->onderteken($id, 'kpn-gekwalificeerde-handtekening', 'afdelingsmanager-wmo-15');
		$sent = $this->service->verzend($id, 'afdelingsmanager-wmo-15');

		// The stored end is the declared 28 days, not the scheduler's six
		// weeks: the printed clause and the stored date come from one read.
		$expected = (new \DateTimeImmutable((string)$sent['announcementDate']))
			->modify('+28 days')->format('Y-m-d');
		$this->assertSame(expected: $expected, actual: $sent['objectionTermEndDate']);
	}//end testSendingBindsTheRemedyTermOnTheDeclaredDays()

	/**
	 * The full lifecycle reaches gearchiveerd with all evidence recorded. [V01]
	 *
	 * @return void
	 */
	public function testFullLifecycle(): void {
		$decision = $this->composeWmo();
		$id = $decision['id'];

		$afterApproved = $this->service->akkoord($id, 'afdelingsmanager-wmo-15');
		$this->assertSame('approved-mandate', $afterApproved['currentStatus']);
		// Outer key renamed; the inner `mandaatNiveau` is nested JSON and is
		// deliberately left Dutch until the JSON-rewrite migration.
		$this->assertSame('afdelingsmanager', $afterApproved['mandateGranted']['mandateLevel']);

		$afterSign = $this->service->onderteken($id, 'kpn-gekwalificeerde-handtekening', 'afdelingsmanager-wmo-15');
		$this->assertSame('signed', $afterSign['currentStatus']);
		$this->assertNotEmpty($afterSign['signature']['validationRapportId']);

		$afterSend = $this->service->verzend($id, 'afdelingsmanager-wmo-15');
		$this->assertSame('sent', $afterSend['currentStatus']);
		$this->assertNotEmpty($afterSend['objectionTermEndDate']);

		// A BezwaarTrigger was created with a 6-week termijn. [V08]
		$triggers = $this->objects->searchObjectsBySlug('dossiq', 'bezwaarTrigger', ['decisionId' => $id]);
		$this->assertCount(1, $triggers);
		$this->assertTrue($triggers[0]['archiveTriggerActive']);

		$afterArchive = $this->service->archive($id);
		$this->assertSame('archived', $afterArchive['currentStatus']);
		$this->assertNotEmpty($afterArchive['archive']['archiveId']);
		$this->assertNotEmpty($afterArchive['archive']['destructionDate']);

		// Every transition was logged. [V05 logging]
		$logs = $this->objects->searchObjectsBySlug('dossiq', 'stateMachineLog', ['decisionId' => $id]);
		$this->assertGreaterThanOrEqual(4, count($logs));
	}//end testFullLifecycle()

	/**
	 * A delivered beschikking lands on the case timeline, and it lands public.
	 *
	 * The applicant is holding the letter and the six weeks to object started
	 * the day it went, so this is the one entry the public timeline may not be
	 * missing. The entry carries the channel and the day, and the assertion
	 * says so field by field rather than counting entries: an entry written
	 * internal looks exactly like one written public until someone reads the
	 * flag.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testADeliveredBeschikkingIsOnThePublicTimeline(): void {
		$decision = $this->composeWmo();
		$id = $decision['id'];

		$this->service->akkoord($id, 'afdelingsmanager-wmo-15');
		$this->service->onderteken($id, 'kpn-gekwalificeerde-handtekening', 'afdelingsmanager-wmo-15');
		$this->service->verzend($id, 'afdelingsmanager-wmo-15');

		$delivered = array_values(
			array_filter(
				$this->entries,
				static fn (array $entry): bool => $entry['kind'] === 'beschikking-verzonden'
			)
		);

		$this->assertCount(1, $delivered);
		$this->assertSame('zaak-2026-wmo-1', $delivered[0]['caseId']);
		$this->assertSame('public', $delivered[0]['visibility']);
		$this->assertSame('digital-post', $delivered[0]['fields']['channel']);
		$this->assertNotSame('', $delivered[0]['fields']['sentOn']);
		$this->assertSame($id, $delivered[0]['fields']['beschikkingId']);
		$this->assertSame('toekenning', $delivered[0]['fields']['decisionType']);
	}//end testADeliveredBeschikkingIsOnThePublicTimeline()

	/**
	 * A beschikking no transport took is not marked sent (decision 148).
	 *
	 * 🔴 IT WAS. verzend() asked the routing service for a channel name, which
	 * calls nothing, then set the status to `sent`, started the six weeks to
	 * object and wrote a PUBLIC "Beschikking verzonden" line, for a letter
	 * nobody received. Here the requester has no address anywhere, so nothing
	 * can take it: the decision stays signed, no clock starts, and the line
	 * that says why is internal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
	 */
	public function testABeschikkingNoTransportTookIsNotMarkedSent(): void {
		// Addressed to a burger with no BSN, on a case with no address.
		$id = $this->composeWmo(['type' => 'burger', 'name' => 'M. Jansen'])['id'];
		$this->service->akkoord($id, 'afdelingsmanager-wmo-15');
		$this->service->onderteken($id, 'kpn-gekwalificeerde-handtekening', 'afdelingsmanager-wmo-15');

		$refused = null;
		try {
			$this->service->verzend($id, 'afdelingsmanager-wmo-15');
		} catch (RefusedException $e) {
			$refused = $e;
		}

		$this->assertNotNull($refused, 'verzend() must refuse when no transport took the beschikking');
		$this->assertSame('beschikking-no-address', $refused->getRule());
		$this->assertSame('signed', ($this->service->find($id)['currentStatus'] ?? null), 'it stays not-sent');
		$this->assertSame([], $this->objects->searchObjectsBySlug('dossiq', 'bezwaarTrigger', ['decisionId' => $id]), 'no objection clock starts');

		$public = array_filter($this->entries, static fn (array $entry): bool => $entry['visibility'] === 'public');
		$this->assertSame([], array_values($public), 'nothing public says it was sent');

		$internal = array_values(array_filter($this->entries, static fn (array $entry): bool => $entry['kind'] === 'beschikking-verzonden'));
		$this->assertCount(1, $internal);
		$this->assertSame('internal', $internal[0]['visibility']);
		$this->assertSame('Beschikking niet verzonden', $internal[0]['message']);
		$this->assertSame('no-channel', $internal[0]['fields']['reasonCode']);
	}//end testABeschikkingNoTransportTookIsNotMarkedSent()

	/**
	 * A transport that refuses leaves the beschikking signed, with its sentence (decision 148).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
	 */
	public function testABeschikkingDigitalPostRefusedStaysSigned(): void {
		$this->postAnswer = ['refused' => true, 'code' => 'no-message-box', 'error' => 'This person has no MijnOverheid message box.'];

		$id = $this->composeWmo()['id'];
		$this->service->akkoord($id, 'afdelingsmanager-wmo-15');
		$this->service->onderteken($id, 'kpn-gekwalificeerde-handtekening', 'afdelingsmanager-wmo-15');

		try {
			$this->service->verzend($id, 'afdelingsmanager-wmo-15');
			$this->fail('verzend() must refuse when digital post refused and no other channel exists');
		} catch (RefusedException $e) {
			$this->assertSame('beschikking-not-sent', $e->getRule());
			$this->assertSame('This person has no MijnOverheid message box.', $e->getSentence());
			$this->assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
		}

		$this->assertCount(1, $this->posted, 'digital post was asked, on the addressee BSN');
		$this->assertSame('123456789', $this->posted[0]['bsn']);
		$this->assertSame('signed', ($this->service->find($id)['currentStatus'] ?? null));
		$this->assertSame('no-message-box', ($this->entries[0]['fields']['reasonCode'] ?? null));
		$this->assertSame('internal', ($this->entries[0]['visibility'] ?? null));
	}//end testABeschikkingDigitalPostRefusedStaysSigned()

	/**
	 * A sent beschikking stores the channel and message id the transport answered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
	 */
	public function testASentBeschikkingCarriesTheTransportsMessageId(): void {
		$id = $this->composeWmo()['id'];
		$this->service->akkoord($id, 'afdelingsmanager-wmo-15');
		$this->service->onderteken($id, 'kpn-gekwalificeerde-handtekening', 'afdelingsmanager-wmo-15');
		$sent = $this->service->verzend($id, 'afdelingsmanager-wmo-15');

		$this->assertSame('sent', $sent['currentStatus']);
		$this->assertSame('digital-post', $sent['dispatch']['notificationChannel']);
		$this->assertSame('mijnoverheid-msg-1', $sent['dispatch']['messageId']);
		$this->assertSame('besluit', $this->posted[0]['category']);
		$this->assertSame('beschikking', $this->posted[0]['typeCode']);
		$this->assertStringStartsWith('Besluit over uw zaak', $this->posted[0]['subject']);
		$this->assertStringContainsString('Toegekend op basis van onderzoek.', $this->posted[0]['body']);
	}//end testASentBeschikkingCarriesTheTransportsMessageId()

	/**
	 * Mandaat is rejected when the approver level cannot cover the bedrag. [V03]
	 *
	 * @return void
	 */
	public function testMandaatRejectedWhenOverLimit(): void {
		$decision = $this->composeWmo();
		// Raise the bedrag above the consulent limit while still ontwerp.
		$decision = $this->service->updateFields($decision['id'], ['feeAmount' => 9000]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('mandaat_insufficient');

		// A consulent may only sign up to 5000.
		$this->service->akkoord($decision['id'], 'consulent-wmo-3');
	}//end testMandaatRejectedWhenOverLimit()

	/**
	 * Editing a content field once ondertekend is rejected. [V02]
	 *
	 * @return void
	 */
	public function testImmutabilityAfterSigning(): void {
		$decision = $this->composeWmo();
		$id = $decision['id'];

		$this->service->akkoord($id, 'afdelingsmanager-wmo-15');
		$this->service->onderteken($id, 'kpn-gekwalificeerde-handtekening', 'afdelingsmanager-wmo-15');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('immutable');

		$this->service->updateFields($id, ['rationale' => 'gewijzigd']);
	}//end testImmutabilityAfterSigning()

	/**
	 * An invalid transition (verzend before onderteken) is rejected. [V05]
	 *
	 * @return void
	 */
	public function testInvalidTransitionRejected(): void {
		$decision = $this->composeWmo();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('invalid_transition');

		// Cannot verzend straight from ontwerp.
		$this->service->verzend($decision['id'], 'afdelingsmanager-wmo-15');
	}//end testInvalidTransitionRejected()

	/**
	 * The audit-pakket is a non-empty, verifiable ZIP. [V04]
	 *
	 * @return void
	 */
	public function testAuditPacketIsZip(): void {
		$decision = $this->composeWmo();
		$id = $decision['id'];

		$this->service->akkoord($id, 'afdelingsmanager-wmo-15');
		$this->service->onderteken($id, 'kpn-gekwalificeerde-handtekening', 'afdelingsmanager-wmo-15');

		$zip = $this->service->exportAuditPacket($id);

		// ZIP local-file-header magic bytes.
		$this->assertSame("PK\x03\x04", substr($zip, 0, 4));
		$this->assertGreaterThan(100, strlen($zip));
	}//end testAuditPacketIsZip()
}//end class
