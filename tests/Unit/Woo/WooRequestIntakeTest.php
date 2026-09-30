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

/**
 * Runs the intake against an in-memory register holding the seeded type and a C1-shaped dossier.
 *
 * @covers \OCA\Dossiq\Woo\WooRequestIntake
 * @covers \OCA\Dossiq\Woo\WooRequestRefused
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

		/** @var SettingsService&MockObject $settings */
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				'case_object_schema' => 'caseObject',
			][$key] ?? $default
		);
		$settings->method('getWooPublicationConfigValue')->willReturnCallback(
			fn (string $key): string => SettingsService::WOO_PUBLICATION_DEFAULTS[$key] ?? ''
		);

		/** @var IURLGenerator&MockObject $urls */
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'https://gemeente.test' . $path);

		$this->intake = new WooRequestIntake(
			settingsService: $settings,
			urlGenerator: $urls,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

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
		self::assertSame('portal', $case['intakeChannel']);
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
		self::assertSame('pipelinq', $case['intakeChannel']);
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
