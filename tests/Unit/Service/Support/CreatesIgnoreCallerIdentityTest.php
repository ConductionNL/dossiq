<?php

/**
 * Every create built from caller fields drops the fields that name a stored object.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\AppointmentService;
use OCA\Dossiq\Service\Bezwaar\BeroepService;
use OCA\Dossiq\Service\BezwaarDecisionDelegationService;
use OCA\Dossiq\Service\Bezwaar\DecisionService;
use OCA\Dossiq\Service\Bezwaar\DecisionValidator;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\ComplaintService;
use OCA\Dossiq\Service\Consultation\ConsultationDependencyGraph;
use OCA\Dossiq\Service\Consultation\ConsultationRecords;
use OCA\Dossiq\Service\Consultation\ConsultationRepository;
use OCA\Dossiq\Service\Conversation\TalkConversationBroker;
use OCA\Dossiq\Service\ConsultationService;
use OCA\Dossiq\Service\Deelzaak\SubCaseDeriver;
use OCA\Dossiq\Service\Obligations\ObligationService;
use OCA\Dossiq\Service\DispositionService;
use OCA\Dossiq\Service\HearingCalendarService;
use OCA\Dossiq\Service\HearingService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Subsidie\BeschikkingService;
use OCA\Dossiq\Service\Subsidie\BewijsstukService;
use OCA\Dossiq\Service\Subsidie\SubsidieService;
use OCA\Dossiq\Service\Subsidie\TussenrapportageService;
use OCA\Dossiq\Service\Support\OwningCaseResolver;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * A store that writes to the object a payload names, as OpenRegister's saveObject() does.
 */
class ObjectNamingStore {

	/**
	 * Stored rows, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * Every payload saved, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * Uuids of stored rows a save wrote over.
	 *
	 * @var array<int, string>
	 */
	public array $overwritten = [];

	/**
	 * Save one object: to the uuid given, else to `@self.id` or `id`, else to a new one.
	 *
	 * @param array<string, mixed> $object The payload.
	 * @param mixed                ...$rest Register, schema, uuid and flags, by name.
	 *
	 * @return ObjectEntity The stored object.
	 */
	public function saveObject(array $object, mixed ...$rest): ObjectEntity {
		$this->saved[] = $object;
		$uuid = (string)($rest['uuid'] ?? ($object['@self']['id'] ?? ($object['id'] ?? '')));
		if ($uuid !== '' && isset($this->rows[$uuid]) === true) {
			$this->overwritten[] = $uuid;
		}

		if ($uuid === '') {
			$uuid = 'new-' . count($this->saved);
		}

		$this->rows[$uuid] = $object;
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($object + ['id' => $uuid]);

		return $entity;
	}//end saveObject()

	/**
	 * Find one stored object.
	 *
	 * @param int|string $id      The uuid.
	 * @param mixed      ...$rest Ignored.
	 *
	 * @return ObjectEntity|null The object, or null.
	 */
	public function find(int|string $id, mixed ...$rest): ?ObjectEntity {
		if (isset($this->rows[(string)$id]) === false) {
			return null;
		}

		$entity = new ObjectEntity();
		$entity->setUuid((string)$id);
		$entity->setObject($this->rows[(string)$id] + ['id' => (string)$id]);

		return $entity;
	}//end find()
}//end class

/**
 * A caller who sends `id`, `uuid` or `@self` on a create gets a new object,
 * and the object those fields name is left as it was.
 *
 * @covers \OCA\Dossiq\Service\AppointmentService
 * @covers \OCA\Dossiq\Service\ComplaintService
 * @covers \OCA\Dossiq\Service\ConsultationService
 * @covers \OCA\Dossiq\Service\HearingService
 * @covers \OCA\Dossiq\Service\DispositionService
 * @covers \OCA\Dossiq\Service\Bezwaar\DecisionService
 * @covers \OCA\Dossiq\Service\Bezwaar\BeroepService
 * @covers \OCA\Dossiq\Service\Subsidie\BeschikkingService
 * @covers \OCA\Dossiq\Service\Subsidie\SubsidieService
 * @covers \OCA\Dossiq\Service\Subsidie\TussenrapportageService
 * @covers \OCA\Dossiq\Service\Subsidie\BewijsstukService
 * @covers \OCA\Dossiq\Service\Deelzaak\SubCaseDeriver
 * @uses \OCA\Dossiq\Service\Support\CallerIdentity
 * @uses \OCA\Dossiq\Service\Deelzaak\SubCaseLink
 * @uses \OCA\Dossiq\Service\Deelzaak\SubCaseStore
 * @uses \OCA\Dossiq\Service\AppointmentBackend\JccBackend
 * @uses \OCA\Dossiq\Service\Consultation\ConsultationRecords
 * @uses \OCA\Dossiq\Service\Consultation\ConsultationTerms
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Bezwaar\DecisionValidator
 * @uses \OCA\Dossiq\Service\Subsidie\StaatssteunClassifier
 * @uses \OCA\Dossiq\Service\Subsidie\CofinancieringValidator
 */
class CreatesIgnoreCallerIdentityTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The fields a forging caller sends, all naming the stored victim.
	 */
	private const FORGED = ['id' => 'victim-1', 'uuid' => 'victim-1', '@self' => ['id' => 'victim-1']];

	/**
	 * The stored object the forged fields name.
	 */
	private const VICTIM = ['title' => 'Somebody else\'s record', 'owner' => 'other-user'];

	/**
	 * The store.
	 *
	 * @var ObjectNamingStore
	 */
	private ObjectNamingStore $store;

	/**
	 * Settings over the store, naming every schema after its key.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	/**
	 * Seed the victim.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new ObjectNamingStore();
		$this->store->rows['victim-1'] = self::VICTIM;
		$this->store->rows['decision-1'] = ['effectiveDate' => '2026-10-01'];
		$this->store->rows['parent-1'] = ['title' => 'Parent'];

		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getObjectService')->willReturn($this->store);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, mixed ...$rest): string => match ($key) {
				'register' => '1',
				'appointment_backend' => 'jcc',
				default => str_ends_with($key, '_schema') === true ? '2' : '',
			}
		);
		$this->settings->method('getOpenRegisterClass')->willReturn(null);
	}//end setUp()

	/**
	 * Each create path, as the call it makes with forged caller fields.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function createPaths(): array {
		return [
			'appointment' => ['appointment'],
			'complaint' => ['complaint'],
			'consultation' => ['consultation'],
			'complaint hearing' => ['hearing'],
			'disposition' => ['disposition'],
			'bezwaar decision draft' => ['decision'],
			'beroep' => ['beroep'],
			'subsidy decision draft' => ['beschikking'],
			'subsidy application' => ['aanvraag'],
			'interim report' => ['tussenrapportage'],
			'evidence' => ['bewijsstuk'],
			'sub-case' => ['subCase'],
		];
	}//end createPaths()

	/**
	 * A forged id on create opens a new object and leaves the named one as it was.
	 *
	 * @param string $path The create path.
	 *
	 * @return void
	 *
	 * @dataProvider createPaths
	 */
	public function testAForgedIdOnCreateDoesNotTouchTheNamedObject(string $path): void {
		$this->create(path: $path);

		$this->assertNotContains('victim-1', $this->store->overwritten, 'the create wrote over the object the caller named');
		$this->assertSame(self::VICTIM, $this->store->rows['victim-1']);
		$this->assertNotSame([], $this->store->saved, 'the create must still be written');
		foreach (array_keys(self::FORGED) as $key) {
			$this->assertArrayNotHasKey($key, $this->store->saved[0]);
		}
	}//end testAForgedIdOnCreateDoesNotTouchTheNamedObject()

	/**
	 * Run one create path with the forged fields in its caller data.
	 *
	 * @param string $path The create path.
	 *
	 * @return void
	 */
	private function create(string $path): void {
		$logger = new NullLogger();
		$users  = $this->createMock(IUserSession::class);
		match ($path) {
			'appointment' => $this->appointments()->bookAppointment(caseId: 'case-1', data: self::FORGED + ['start' => '2026-11-10T10:00:00+01:00']),
			'complaint' => (new ComplaintService($this->settings, $logger, $this->workingDays(), $this->caseDates()))
				->createComplaint(data: self::FORGED + ['subject' => 'S', 'description' => 'D', 'receiptDate' => '2026-11-02']),
			'consultation' => $this->consultations()->createConsultation(data: self::FORGED + ['parentCase' => 'case-1', 'adviceAuthority' => 'milieu', 'questionFormulation' => 'Q']),
			'hearing' => (new HearingService($this->settings, $logger, $this->createMock(HearingCalendarService::class), $this->createMock(TalkConversationBroker::class)))
				->scheduleHearing('complaint-1', self::FORGED + ['date' => '2026-11-20T10:00:00', 'type' => 'fysiek']),
			'disposition' => (new DispositionService($this->settings, $logger))->submitDisposition('complaint-1', self::FORGED + ['opinion' => 'dismissed', 'reasoning' => 'R']),
			'decision' => (new DecisionService($this->settings, $users, $this->createMock(StatusTransitionService::class), $logger, $this->createMock(BezwaarDecisionDelegationService::class), new DecisionValidator()))
				->draft(objectionId: 'objection-1', payload: self::FORGED + ['dispositionType' => 'dismissed', 'reasoning' => 'R', 'legalBasis' => 'Awb 7:11']),
			'beroep' => (new BeroepService($this->settings, $this->createMock(StatusTransitionService::class), $logger))
				->register(caseId: 'case-1', sourceObjectionId: 'objection-1', contestedDecisionId: 'decision-1', filingDate: '2026-10-20', payload: self::FORGED),
			'beschikking' => (new BeschikkingService($this->settings, new SubsidieService($this->settings, $logger), $users, $logger))->createDraft('request-1', self::FORGED + ['grantedAmount' => 1000], 1),
			'aanvraag' => (new SubsidieService($this->settings, $logger))->createAanvraag(payload: self::FORGED + ['subsidyScheme' => 'regeling-1']),
			'tussenrapportage' => (new TussenrapportageService($this->settings, $users, $logger))->createExpected('uitvoering-1', self::FORGED),
			'bewijsstuk' => (new BewijsstukService($this->settings, $logger))->create(payload: self::FORGED + ['linkedIn' => 'request', 'evidenceType' => 'budget']),
			'subCase' => (new SubCaseDeriver($this->settings, $logger))->derive(parentCaseUuid: 'parent-1', childData: self::FORGED + ['title' => 'Deelzaak']),
		};
	}//end create()

	/**
	 * The appointment service, with no booking backend configured.
	 *
	 * @return AppointmentService
	 */
	private function appointments(): AppointmentService {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			fn (array $object, mixed ...$rest): ObjectEntity => $this->store->saveObject($object, uuid: ($rest[3] ?? null))
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException(new \Exception('booking backend is offline'));
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new AppointmentService(
			$this->settings,
			$apps,
			$clients,
			$container,
			new NullLogger(),
			$this->createMock(OwningCaseResolver::class),
		);
	}//end appointments()

	/**
	 * A working-day calculator that adds calendar days.
	 *
	 * @return WorkingDayCalculator
	 */
	private function workingDays(): WorkingDayCalculator {
		$days = $this->createMock(WorkingDayCalculator::class);
		$days->method('addWorkingDays')->willReturnCallback(
			static fn (\DateTimeImmutable $start, int $days): \DateTimeImmutable => $start->modify('+' . $days . ' days')
		);

		return $days;
	}//end workingDays()

	/**
	 * The consultation service, over the store.
	 *
	 * @return ConsultationService
	 */
	private function consultations(): ConsultationService {
		return new ConsultationService(
			$this->settings,
			new NullLogger(),
			$this->createMock(AdviceDelegationService::class),
			new ConsultationRecords(
				$this->createMock(ConsultationRepository::class),
				$this->createMock(ConsultationDependencyGraph::class),
				$this->createMock(ObligationService::class),
			),
			$this->caseDates(),
		);
	}//end consultations()
}//end class
