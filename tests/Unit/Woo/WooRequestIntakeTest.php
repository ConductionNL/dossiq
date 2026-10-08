<?php

/**
 * One service opens every Woo request, from the portal and from pipelinq alike.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Woo
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooRequestIntake;
use OCA\Dossiq\Woo\WooRequestRefused;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Runs the intake against an in-memory register holding the seeded type and a C1-shaped dossier.
 *
 * @covers \OCA\Dossiq\Woo\WooRequestIntake
 * @covers \OCA\Dossiq\Woo\WooRequestRefused
 * @covers \OCA\Dossiq\Woo\WooRequestForm
 *
 * The two readers the intake builds for itself: what the case type asks about
 * the requester, and what the write answered. They are used here, not covered:
 * their own rules are asserted through this path because that is the only
 * caller either has.
 *
 * @uses   \OCA\Dossiq\Woo\WooRequesterProperties
 * @uses   \OCA\Dossiq\Woo\WooWrittenCase
 */
class WooRequestIntakeTest extends TestCase {

	private const RESIDENT = 'subject-ref-anna';

	private const COLLECTION = '0c0c0c0c-0000-4000-a000-000000000001';

	private const PUB_A = 'aaaaaaaa-0000-4000-a000-00000000000a';

	private const PUB_B = 'bbbbbbbb-0000-4000-a000-00000000000b';

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The service under test.
	 *
	 * @var WooRequestIntake
	 */
	private WooRequestIntake $intake;

	/**
	 * Seed the Woo type and one dossier in the exact C1 shape.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryRegister();
		$this->store->seed(
			schema: 'caseType',
			uuid: WooRequestIntake::CASE_TYPE_ID,
			row: ['title' => 'Woo-verzoek', 'identifier' => 'woo-verzoek', 'initialStatus' => 'status-ontvangst']
		);
		// The three requester questions the Woo type asks, as
		// register.d/81-woo-verzoek.json declares them.
		foreach (['verzoekerNaam', 'verzoekerEmail', 'verzoekerType'] as $index => $name) {
			$this->store->seed(
				schema: 'propertyDefinition',
				uuid: 'definition-' . ($index + 1),
				row: ['caseType' => WooRequestIntake::CASE_TYPE_ID, 'name' => $name, 'propertyType' => 'string']
			);
		}

		$this->store->seed(schema: 'publication', uuid: self::PUB_A, row: ['title' => 'Raadsbesluit parkeren 2025']);
		$this->store->seed(schema: 'publication', uuid: self::PUB_B, row: ['title' => 'Nota parkeren']);
		$this->store->seed(
			schema: 'collection',
			uuid: self::COLLECTION,
			row: [
				'title' => 'Parkeren in het centrum',
				'owner' => self::RESIDENT,
				'items' => [
					['id' => 'item-1', 'publication' => self::PUB_A, 'attachment' => null, 'note' => 'Het besluit', 'addedAt' => '2026-09-01T10:00:00+00:00', 'addedBy' => 'resident'],
					['id' => 'item-2', 'publication' => self::PUB_B, 'attachment' => '4711', 'note' => '', 'addedAt' => '2026-09-02T10:00:00+00:00', 'addedBy' => 'resident'],
				],
				'share' => null,
				'sourceOf' => [],
			]
		);

		$this->intake = $this->intakeOn(store: $this->store);
	}//end setUp()

	/**
	 * The intake on a given store.
	 *
	 * @param object $store The register the intake reads and writes.
	 *
	 * @return WooRequestIntake
	 */
	private function intakeOn(object $store): WooRequestIntake {
		/** @var SettingsService&MockObject $settings */
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				'case_object_schema' => 'caseObject',
				'property_definition_schema' => 'propertyDefinition',
			][$key] ?? $default
		);
		$settings->method('getWooPublicationConfigValue')->willReturnCallback(
			fn (string $key): string => SettingsService::WOO_PUBLICATION_DEFAULTS[$key] ?? ''
		);

		/** @var IURLGenerator&MockObject $urls */
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'https://gemeente.test' . $path);

		return new WooRequestIntake(
			settingsService: $settings,
			urlGenerator: $urls,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end intakeOn()

	/**
	 * The seeded rows in a store that behaves like OpenRegister on a request
	 * without a Nextcloud user (a portal forward): a read scoped by RBAC or
	 * multitenancy finds nothing and a scoped write is refused, so only
	 * `_rbac: false, _multitenancy: false` reaches a row.
	 *
	 * @param InMemoryRegister $seeded The store holding the seeded rows.
	 *
	 * @return InMemoryRegister
	 */
	private function anonymousStore(InMemoryRegister $seeded): InMemoryRegister {
		$store = new class extends InMemoryRegister {

			/**
			 * Find, unscoped only.
			 *
			 * @param int|string $id            The uuid.
			 * @param mixed      $_extend       Ignored.
			 * @param bool       $files         Ignored.
			 * @param int|string $register      Ignored.
			 * @param int|string $schema        The schema slug.
			 * @param bool       $_rbac         Must be false to see a row.
			 * @param bool       $_multitenancy Must be false to see a row.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(
				int|string $id,
				mixed $_extend = null,
				bool $files = false,
				int|string $register = '',
				int|string $schema = '',
				bool $_rbac = true,
				bool $_multitenancy = true,
			): ?array {
				if ($_rbac === true || $_multitenancy === true) {
					return null;
				}

				return parent::find(id: $id, register: $register, schema: $schema);
			}

			/**
			 * Save, unscoped only.
			 *
			 * @param array<string, mixed> $object        The row.
			 * @param int|string           $register      Ignored.
			 * @param int|string           $schema        The schema slug.
			 * @param string|null          $uuid          The uuid, or null to create.
			 * @param bool                 $_rbac         Must be false to write.
			 * @param bool                 $_multitenancy Must be false to write.
			 *
			 * @return array<string, mixed>
			 *
			 * @throws RuntimeException When the write is scoped.
			 */
			public function saveObject(
				array $object,
				int|string $register = '',
				int|string $schema = '',
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				if ($_rbac === true || $_multitenancy === true) {
					throw new RuntimeException("User 'Anonymous' does not have permission to 'create' objects");
				}

				return parent::saveObject(object: $object, register: $register, schema: $schema, uuid: $uuid);
			}
		};
		$store->rows = $seeded->rows;

		return $store;
	}//end anonymousStore()

	/**
	 * A valid portal request from the owner.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function request(array $overrides = []): array {
		return array_merge(
			[
				'subjectRef' => self::RESIDENT,
				'collectionId' => self::COLLECTION,
				'onderwerp' => 'Parkeerbeleid centrum',
				'omschrijving' => 'Alle stukken over het parkeerbeleid.',
				'periodeVan' => '2025-01-01',
				'periodeTot' => '2025-12-31',
				'origin' => 'portal',
				'originReference' => null,
			],
			$overrides
		);
	}//end request()

	/**
	 * The resident's request becomes a Woo case stamped with their subjectRef.
	 *
	 * @return void
	 */
	public function testARequestOpensAWooCaseForTheResident(): void {
		$result = $this->intake->start($this->request());

		$case = $this->store->row(schema: 'case', uuid: $result['caseId']);
		self::assertSame(WooRequestIntake::CASE_TYPE_ID, $case['caseType']);
		self::assertSame(self::RESIDENT, $case['portalSubject']);
		self::assertSame('Parkeerbeleid centrum', $case['title']);
		self::assertSame('Alle stukken over het parkeerbeleid.', $case['description']);
		self::assertSame('status-ontvangst', $case['status']);
		self::assertSame('website', $case['intakeChannel']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $case['startDate']);
		self::assertSame(
			[
				'onderwerp' => 'Parkeerbeleid centrum',
				'omschrijving' => 'Alle stukken over het parkeerbeleid.',
				'periodeVan' => '2025-01-01',
				'periodeTot' => '2025-12-31',
				'origin' => 'portal',
				'originReference' => '',
				'collectionId' => self::COLLECTION,
			],
			$case['wooRequest']
		);
		self::assertSame('https://gemeente.test/index.php/apps/dossiq/cases/' . $result['caseId'], $result['caseUrl']);
	}//end testARequestOpensAWooCaseForTheResident()

	/**
	 * The new answers are kept on the request, and the requester details also
	 * answer the case type's own questions.
	 *
	 * @return void
	 */
	public function testTheRequestKeepsTheDocumentKindsAndTheRequester(): void {
		$result = $this->intake->start(
			$this->request(
				[
					'documentSoorten' => ['besluiten', 'correspondentie'],
					'toelichting' => 'Het gaat om de Lindelaan.',
					'verzoekerNaam' => 'Sanne de Vries',
					'verzoekerEmail' => 'sanne@example.org',
					'verzoekerType' => 'journalist',
				]
			)
		);

		$case = $this->store->row(schema: 'case', uuid: $result['caseId']);
		self::assertSame(['besluiten', 'correspondentie'], $case['wooRequest']['documentSoorten']);
		self::assertSame('Het gaat om de Lindelaan.', $case['wooRequest']['toelichting']);
		self::assertSame('Sanne de Vries', $case['wooRequest']['verzoekerNaam']);
		self::assertSame('sanne@example.org', $case['wooRequest']['verzoekerEmail']);
		self::assertSame('journalist', $case['wooRequest']['verzoekerType']);

		// And on the case's own properties, where a handler reads them beside
		// the question the type asked.
		self::assertSame(
			[
				['propertyDefinition' => 'definition-1', 'name' => 'verzoekerNaam', 'value' => 'Sanne de Vries'],
				['propertyDefinition' => 'definition-2', 'name' => 'verzoekerEmail', 'value' => 'sanne@example.org'],
				['propertyDefinition' => 'definition-3', 'name' => 'verzoekerType', 'value' => 'journalist'],
			],
			$case['properties']
		);
	}//end testTheRequestKeepsTheDocumentKindsAndTheRequester()

	/**
	 * 🔴 An answer outside its list is refused and nothing is written: a
	 * value the schema's enum would reject must not reach a write that then
	 * fails halfway, and a kind nobody declared is not a kind.
	 *
	 * @return void
	 */
	public function testAnAnswerOutsideItsListIsRefusedAndWritesNothing(): void {
		$refusals = [
			['documentSoorten' => ['geheim']],
			['verzoekerType' => 'ambtenaar'],
			['verzoekerEmail' => 'geen adres'],
		];

		foreach ($refusals as $overrides) {
			$before = count($this->store->all(schema: 'case'));
			try {
				$this->intake->start($this->request($overrides));
				self::fail('a refused answer must not open a case: ' . (string)json_encode($overrides));
			} catch (WooRequestRefused $refused) {
				self::assertSame(WooRequestRefused::INVALID, $refused->getReason());
			}

			self::assertCount($before, $this->store->all(schema: 'case'), 'nothing was written');
		}
	}//end testAnAnswerOutsideItsListIsRefusedAndWritesNothing()

	/**
	 * A conversion with only the subject still opens its case, and carries no
	 * empty answers: pipelinq converts a phone call where not every question
	 * was asked.
	 *
	 * @return void
	 */
	public function testAConversionWithoutTheNewAnswersCarriesNone(): void {
		$result = $this->intake->start(
			$this->request(['collectionId' => '', 'origin' => 'pipelinq', 'originReference' => 'ticket-9'])
		);

		$case = $this->store->row(schema: 'case', uuid: $result['caseId']);
		foreach (['documentSoorten', 'toelichting', 'verzoekerNaam', 'verzoekerEmail', 'verzoekerType'] as $key) {
			self::assertArrayNotHasKey($key, $case['wooRequest'], $key . ' was not asked, so it is not written');
		}

		self::assertArrayNotHasKey('properties', $case, 'and the case answers none of the type\'s questions');
	}//end testAConversionWithoutTheNewAnswersCarriesNone()

	/**
	 * The answer names the case number and the date, and leaves the date out
	 * when the case has none yet.
	 *
	 * @return void
	 */
	public function testTheAnswerNamesTheCaseNumberAndTheDeadline(): void {
		$plain = $this->intake->start($this->request());
		self::assertArrayNotHasKey(
			'deadline',
			$plain,
			'a case written without a deadline answers no empty one'
		);

		// The same write, on a store that stamps a number and a deadline the
		// way OpenRegister's calculations do.
		$stamping = new class extends InMemoryRegister {
			public function saveObject(
				array $object,
				int|string $register = '',
				int|string $schema = '',
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$saved = parent::saveObject(object: $object, register: $register, schema: $schema, uuid: $uuid);
				if ((string)$schema !== 'case') {
					return $saved;
				}

				$saved['identifier'] = '2026-0003';
				$saved['deadline'] = '2026-10-30';
				return $saved;
			}
		};
		$stamping->rows = $this->store->rows;

		$answer = $this->intakeOn(store: $stamping)->start($this->request());

		self::assertSame('2026-0003', $answer['identifier']);
		self::assertSame('2026-10-30', $answer['deadline']);
	}//end testTheAnswerNamesTheCaseNumberAndTheDeadline()

	/**
	 * One case object per dossier item, pointing at the public publication.
	 *
	 * @return void
	 */
	public function testEveryDossierItemBecomesACaseObject(): void {
		$result = $this->intake->start($this->request());

		$objects = $this->store->all(schema: 'caseObject');
		self::assertCount(2, $objects);
		foreach ($objects as $object) {
			self::assertSame($result['caseId'], $object['case']);
			self::assertSame(WooRequestIntake::OBJECT_TYPE, $object['objectType']);
		}

		self::assertSame(
			'https://gemeente.test/index.php/apps/opencatalogi/publication/' . self::PUB_A,
			$objects[0]['objectUrl']
		);
		self::assertSame(['publication' => self::PUB_A, 'attachment' => null], json_decode($objects[0]['objectIdentification'], true));
		self::assertSame(['publication' => self::PUB_B, 'attachment' => '4711'], json_decode($objects[1]['objectIdentification'], true));
		self::assertSame('Raadsbesluit parkeren 2025: Het besluit', $objects[0]['description']);
		self::assertSame('Nota parkeren', $objects[1]['description']);
	}//end testEveryDossierItemBecomesACaseObject()

	/**
	 * The dossier records the case once and keeps its items as they were.
	 *
	 * @return void
	 */
	public function testTheDossierRecordsTheCaseOnce(): void {
		$before = $this->store->row(schema: 'collection', uuid: self::COLLECTION)['items'];
		$result = $this->intake->start($this->request());

		$collection = $this->store->row(schema: 'collection', uuid: self::COLLECTION);
		self::assertSame(['dossiq:case:' . $result['caseId']], $collection['sourceOf']);
		self::assertSame($before, $collection['items']);
		self::assertSame(self::RESIDENT, $collection['owner']);
	}//end testTheDossierRecordsTheCaseOnce()

	/**
	 * A portal forward arrives without a Nextcloud user. OpenRegister then
	 * scopes a plain read to nobody, so the dossier looked missing and every
	 * resident got "No such dossier." (Woo e2e J5, 1 Oct 2026). The intake pins
	 * register and schema itself and checks the owner, so it reads and writes
	 * unscoped, and the request goes through.
	 *
	 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/woo-request-intake/spec.md#requirement-only-the-owners-dossier-starts-a-request-req-wri-003
	 *
	 * @return void
	 */
	public function testAPortalRequestWithoutANextcloudUserStillOpensTheCase(): void {
		$this->store = $this->anonymousStore(seeded: $this->store);
		$this->intake = $this->intakeOn(store: $this->store);

		$result = $this->intake->start($this->request());

		$case = $this->store->row(schema: 'case', uuid: $result['caseId']);
		self::assertSame(self::RESIDENT, $case['portalSubject']);
		self::assertSame('status-ontvangst', $case['status']);
		self::assertCount(2, $this->store->all(schema: 'caseObject'));
		self::assertSame('Raadsbesluit parkeren 2025: Het besluit', $this->store->all(schema: 'caseObject')[0]['description']);
		self::assertSame(
			['dossiq:case:' . $result['caseId']],
			$this->store->row(schema: 'collection', uuid: self::COLLECTION)['sourceOf']
		);
	}//end testAPortalRequestWithoutANextcloudUserStillOpensTheCase()

	/**
	 * Unscoped reads do not weaken the owner check: without a Nextcloud user,
	 * someone else's dossier is still not found.
	 *
	 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/woo-request-intake/spec.md#requirement-only-the-owners-dossier-starts-a-request-req-wri-003
	 *
	 * @return void
	 */
	public function testWithoutANextcloudUserSomeoneElsesDossierIsStillNotFound(): void {
		$this->store = $this->anonymousStore(seeded: $this->store);
		$this->intake = $this->intakeOn(store: $this->store);

		try {
			$this->intake->start($this->request(['subjectRef' => 'subject-ref-bob']));
			self::fail('another subject must not start a request from this dossier');
		} catch (WooRequestRefused $refused) {
			self::assertSame(WooRequestRefused::NOT_FOUND, $refused->getReason());
		}

		self::assertSame([], $this->store->all(schema: 'case'));
	}//end testWithoutANextcloudUserSomeoneElsesDossierIsStillNotFound()

	/**
	 * Someone else's dossier is refused as not found, and nothing is written.
	 *
	 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/woo-request-intake/spec.md#requirement-only-the-owners-dossier-starts-a-request-req-wri-003
	 *
	 * @return void
	 */
	public function testSomeoneElsesDossierIsNotFound(): void {
		$writes = $this->store->writes;
		try {
			$this->intake->start($this->request(['subjectRef' => 'subject-ref-bob']));
			self::fail('another subject must not start a request from this dossier');
		} catch (WooRequestRefused $refused) {
			self::assertSame(WooRequestRefused::NOT_FOUND, $refused->getReason());
		}

		self::assertSame($writes, $this->store->writes);
		self::assertSame([], $this->store->all(schema: 'case'));
	}//end testSomeoneElsesDossierIsNotFound()

	/**
	 * A dossier that does not exist answers the same as someone else's.
	 *
	 * @return void
	 */
	public function testAMissingDossierIsNotFound(): void {
		$this->expectException(WooRequestRefused::class);
		$this->expectExceptionMessage(WooRequestRefused::NOT_FOUND);
		$this->intake->start($this->request(['collectionId' => 'ffffffff-0000-4000-a000-000000000000']));
	}//end testAMissingDossierIsNotFound()

	/**
	 * pipelinq's conversion opens the same kind of case, with its ticket as origin.
	 *
	 * @return void
	 */
	public function testAConvertedQuestionOpensTheSameKindOfCase(): void {
		$result = $this->intake->start($this->request(['origin' => 'pipelinq', 'originReference' => 'pipelinq:ticket:42']));

		$case = $this->store->row(schema: 'case', uuid: $result['caseId']);
		self::assertSame(WooRequestIntake::CASE_TYPE_ID, $case['caseType']);
		self::assertSame(self::RESIDENT, $case['portalSubject']);
		self::assertSame('other', $case['intakeChannel']);
		self::assertSame('pipelinq:ticket:42', $case['wooRequest']['originReference']);
		self::assertCount(2, $this->store->all(schema: 'caseObject'));
	}//end testAConvertedQuestionOpensTheSameKindOfCase()

	/**
	 * Without a dossier the case is opened with no case objects and no dossier write.
	 *
	 * @return void
	 */
	public function testARequestWithoutADossier(): void {
		$result = $this->intake->start($this->request(['collectionId' => null]));

		self::assertNotSame('', $result['caseId']);
		self::assertSame([], $this->store->all(schema: 'caseObject'));
		self::assertSame([], $this->store->row(schema: 'collection', uuid: self::COLLECTION)['sourceOf']);
	}//end testARequestWithoutADossier()

	/**
	 * Unusable requests are refused before anything is written.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function unusableRequests(): array {
		return [
			'no subject' => [['subjectRef' => '']],
			'no onderwerp' => [['onderwerp' => '  ']],
			'unknown origin' => [['origin' => 'email']],
			'period the wrong way round' => [['periodeVan' => '2026-01-01', 'periodeTot' => '2025-01-01']],
			'not a date' => [['periodeVan' => 'gisteren']],
		];
	}//end unusableRequests()

	/**
	 * Each unusable request is refused as invalid.
	 *
	 * @param array<string, mixed> $overrides What makes it unusable.
	 *
	 * @dataProvider unusableRequests
	 *
	 * @return void
	 */
	public function testAnUnusableRequestIsRefused(array $overrides): void {
		try {
			$this->intake->start($this->request($overrides));
			self::fail('the request must be refused');
		} catch (WooRequestRefused $refused) {
			self::assertSame(WooRequestRefused::INVALID, $refused->getReason());
		}

		self::assertSame([], $this->store->all(schema: 'case'));
	}//end testAnUnusableRequestIsRefused()

	/**
	 * Without the seeded case type the intake says it is unavailable.
	 *
	 * @return void
	 */
	public function testWithoutTheCaseTypeTheIntakeIsUnavailable(): void {
		unset($this->store->rows['caseType'][WooRequestIntake::CASE_TYPE_ID]);
		$this->expectException(WooRequestRefused::class);
		$this->expectExceptionMessage(WooRequestRefused::UNAVAILABLE);
		$this->intake->start($this->request());
	}//end testWithoutTheCaseTypeTheIntakeIsUnavailable()
}//end class
