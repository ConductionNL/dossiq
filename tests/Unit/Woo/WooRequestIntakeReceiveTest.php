<?php

/**
 * dossiq receives a Woo request in opencatalogi's shape, and answers armed
 * only when the written case's statutory term runs.
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
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-dossiq-receives-a-woo-request-in-opencatalogis-shape-req-wto-001
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-armed-means-a-term-runs-counted-from-when-the-requester-sent-it-req-wto-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\Dossiq\Woo\WooReceivedTerm;
use OCA\Dossiq\Woo\WooRequestIntake;
use OCA\Dossiq\Woo\WooRequestRefused;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * The intake on an in-memory register that plays OpenRegister's part: a case
 * write gets an identifier, and the deadline the term engine mirrors onto it.
 *
 * @covers \OCA\Dossiq\Woo\WooRequestIntake
 * @covers \OCA\Dossiq\Woo\WooReceivedAnswers
 * @covers \OCA\Dossiq\Woo\WooReceivedTerm
 * @covers \OCA\Dossiq\Woo\WooRequestForm
 * @covers \OCA\Dossiq\Woo\WooRequestRefused
 *
 * @uses \OCA\Dossiq\Woo\WooRequesterProperties
 * @uses \OCA\Dossiq\Woo\WooWrittenCase
 * @uses \OCA\Dossiq\Service\TermKind
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 */
class WooRequestIntakeReceiveTest extends TestCase {

	use MakesCaseDateNormaliser;

	/**
	 * The deadline the term engine mirrors onto a written case, or '' for none.
	 *
	 * @var string
	 */
	private string $mirroredDeadline = '2026-12-28';

	/**
	 * The term instances the term service answers for a case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $instances = [];

	/**
	 * Whether OpenRegister's term engine resolves.
	 *
	 * @var boolean
	 */
	private bool $engine = true;

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The timeline double.
	 *
	 * @var CaseTimeline&MockObject
	 */
	private CaseTimeline&MockObject $timeline;

	/**
	 * Seed the Woo type.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$deadline = &$this->mirroredDeadline;
		$this->store = new class($deadline) extends InMemoryRegister {

			/**
			 * The deadline to mirror, by reference to the test's.
			 *
			 * @var string
			 */
			private string $deadline;

			/**
			 * Constructor.
			 *
			 * @param string $deadline The deadline the term engine mirrors.
			 */
			public function __construct(string &$deadline) {
				$this->deadline = &$deadline;
			}

			/**
			 * Save, and on a case create do what OpenRegister and the term listener do.
			 *
			 * @param array<string, mixed> $object        The row.
			 * @param int|string           $register      Ignored.
			 * @param int|string           $schema        The schema slug.
			 * @param string|null          $uuid          The uuid, or null to create.
			 * @param bool                 $_rbac         Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(
				array $object,
				int|string $register = '',
				int|string $schema = '',
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$saved = parent::saveObject(object: $object, register: $register, schema: $schema, uuid: $uuid);
				if ((string)$schema !== 'case' || $uuid !== null) {
					return $saved;
				}

				$saved['identifier'] = 'ZAAK-2026-0042';
				if ($this->deadline !== '') {
					$saved['deadline'] = $this->deadline;
				}

				$this->rows['case'][$saved['id']] = $saved;

				return $saved;
			}
		};
		$this->store->seed(
			schema: 'caseType',
			uuid: WooRequestIntake::CASE_TYPE_ID,
			row: ['title' => 'Woo-verzoek', 'identifier' => 'woo-verzoek', 'initialStatus' => '3c0f5a00-0000-4000-a000-00000000b001']
		);
		$this->instances = [
			['id' => 'term-1', 'case' => 'generated-1', 'kind' => 'statutory', 'engineTimerId' => 'timer-1', 'endDateCurrent' => '2026-12-28', 'status' => 'lopend'],
		];
		$this->timeline = $this->createMock(CaseTimeline::class);
	}//end setUp()

	/**
	 * The intake as the container builds it.
	 *
	 * @param bool $withTerms Whether a term service is wired.
	 *
	 * @return WooRequestIntake
	 */
	private function intake(bool $withTerms = true): WooRequestIntake {
		/** @var SettingsService&MockObject $settings */
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				'property_definition_schema' => 'propertyDefinition',
			][$key] ?? $default
		);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			fn (string $class): ?object => ($class === TermijnTimerService::ENGINE_CLASS && $this->engine === true) ? new stdClass() : null
		);

		/** @var IURLGenerator&MockObject $urls */
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'https://gemeente.test' . $path);

		$terms = null;
		if ($withTerms === true) {
			$terms = $this->createMock(TermijnService::class);
			$terms->method('instancesForCase')->willReturnCallback(fn (string $caseId): array => $this->instances);
		}

		return new WooRequestIntake(
			settingsService: $settings,
			urlGenerator: $urls,
			logger: $this->createMock(LoggerInterface::class),
			receivedTerm: new WooReceivedTerm(settingsService: $settings, terms: $terms, timeline: $this->timeline),
			dates: $this->caseDates(),
		);
	}//end intake()

	/**
	 * opencatalogi's answers, as its portal form sends them.
	 *
	 * @param array<string, mixed> $overrides Answers to change.
	 *
	 * @return array<string, mixed>
	 */
	private function answers(array $overrides = []): array {
		return array_merge(
			[
				'requestedInformation' => 'Alle adviezen over de Stationsweg 2025',
				'requesterName' => 'J. de Vries',
				'requesterEmail' => 'j@example.nl',
			],
			$overrides
		);
	}//end answers()

	/**
	 * The spec's anonymous portal form becomes a Woo case, armed, with the
	 * answer keys mapped where the case keeps them.
	 *
	 * @return void
	 */
	public function testOpencatalogisAnswerKeysBecomeAWooCase(): void {
		$answer = $this->intake()->receive(
			$this->answers(['requesterPhone' => '0612345678', 'requesterAddress' => 'Stationsweg 1, Zuiddrecht']),
			'2026-11-27T10:15:00+01:00'
		);

		self::assertSame('armed', $answer['outcome'], $answer['message']);
		self::assertSame('generated-1', $answer['requestId']);
		self::assertSame('ZAAK-2026-0042', $answer['reference']);
		self::assertSame('2026-12-28', $answer['dueAt']);
		self::assertSame('https://gemeente.test/index.php/apps/dossiq/cases/generated-1', $answer['caseUrl']);

		$case = $this->store->row(schema: 'case', uuid: 'generated-1');
		self::assertSame(WooRequestIntake::CASE_TYPE_ID, $case['caseType']);
		self::assertSame('website', $case['intakeChannel']);
		self::assertSame('Alle adviezen over de Stationsweg 2025', $case['title']);
		self::assertArrayNotHasKey('portalSubject', $case);
		self::assertSame(
			[
				'onderwerp' => 'Alle adviezen over de Stationsweg 2025',
				'omschrijving' => 'Alle adviezen over de Stationsweg 2025',
				'origin' => 'portal-form',
				'originReference' => '',
				'collectionId' => '',
				'verzoekerNaam' => 'J. de Vries',
				'verzoekerEmail' => 'j@example.nl',
				'verzoekerTelefoon' => '0612345678',
				'verzoekerAdres' => 'Stationsweg 1, Zuiddrecht',
			],
			$case['wooRequest']
		);
	}//end testOpencatalogisAnswerKeysBecomeAWooCase()

	/**
	 * opencatalogi's channel values land in the case's own enum, and a long
	 * request is cut to a subject on a word boundary.
	 *
	 * @return void
	 */
	public function testTheChannelIsMappedAndALongRequestIsCutToASubject(): void {
		$long = str_repeat('Stukken over de herinrichting ', 6);
		$answer = $this->intake()->receive($this->answers(['requestedInformation' => $long, 'channel' => 'counter']), '2026-11-27T10:15:00+01:00', 'opencatalogi');

		$case = $this->store->row(schema: 'case', uuid: $answer['requestId']);
		self::assertSame('balie', $case['intakeChannel']);
		self::assertSame('opencatalogi', $case['wooRequest']['origin']);
		self::assertLessThanOrEqual(120, mb_strlen($case['title']));
		self::assertStringEndsWith('herinrichting', $case['title']);
		self::assertSame(trim($long), $case['wooRequest']['omschrijving']);
	}//end testTheChannelIsMappedAndALongRequestIsCutToASubject()

	/**
	 * Nothing asked, an unknown channel, an origin receive() does not take,
	 * or an address that is not one: refused, and no case written.
	 *
	 * @return void
	 */
	public function testARequestForNothingIsRefusedAndWritesNothing(): void {
		$refusals = [
			[$this->answers(['requestedInformation' => '   ']), 'portal-form', 'Say which information'],
			[$this->answers(['channel' => 'fax']), 'portal-form', 'channel'],
			[$this->answers(), 'portal', 'origin'],
			[$this->answers(['requesterEmail' => 'geen adres']), 'portal-form', 'e-mail'],
		];

		foreach ($refusals as [$answers, $origin, $says]) {
			$answer = $this->intake()->receive($answers, '2026-11-27T10:15:00+01:00', $origin);
			self::assertSame('refused', $answer['outcome']);
			self::assertStringContainsString($says, $answer['message']);
			self::assertSame('', $answer['requestId']);
			self::assertSame('', $answer['reference']);
			self::assertSame('', $answer['dueAt']);
		}

		self::assertSame([], $this->store->all(schema: 'case'));
	}//end testARequestForNothingIsRefusedAndWritesNothing()

	/**
	 * The six keys, each a string, on every outcome receive() can answer.
	 *
	 * @return void
	 */
	public function testEveryOutcomeCarriesAllSixKeysAsStrings(): void {
		$answers = [];
		$answers[] = $this->intake()->receive($this->answers(), '2026-11-27T10:15:00+01:00');
		$answers[] = $this->intake()->receive($this->answers(['requestedInformation' => '']));
		$this->instances = [];
		$answers[] = $this->intake()->receive($this->answers(), '2026-11-27T10:15:00+01:00');
		$this->engine = false;
		$answers[] = $this->intake()->receive($this->answers(), '2026-11-27T10:15:00+01:00');

		self::assertSame(['armed', 'refused', 'not-armed', 'unavailable'], array_column($answers, 'outcome'));
		foreach ($answers as $answer) {
			self::assertSame(['outcome', 'requestId', 'reference', 'dueAt', 'message', 'caseUrl'], array_keys($answer));
			foreach ($answer as $value) {
				self::assertIsString($value);
			}
		}
	}//end testEveryOutcomeCarriesAllSixKeysAsStrings()

	/**
	 * start() keeps its dossier rule: portal and pipelinq still need a resident.
	 *
	 * @return void
	 */
	public function testThePortalDossierOriginStillNeedsASubject(): void {
		try {
			$this->intake()->start(['onderwerp' => 'Parkeren', 'omschrijving' => '', 'origin' => 'portal']);
			self::fail('A portal request without a resident was accepted.');
		} catch (WooRequestRefused $e) {
			self::assertSame(WooRequestRefused::INVALID, $e->getReason());
			self::assertSame('The request names no resident.', $e->getDetail());
		}

		self::assertSame([], $this->store->all(schema: 'case'));
	}//end testThePortalDossierOriginStillNeedsASubject()

	/**
	 * The case receive() writes fits the merged case schema, the new origin
	 * and requester fields included.
	 *
	 * @return void
	 */
	public function testTheReceivedCaseValidatesAgainstTheCaseSchema(): void {
		$answer = $this->intake()->receive(
			$this->answers(['requesterPhone' => '0612345678', 'requesterAddress' => 'Stationsweg 1', 'channel' => 'web']),
			'2026-11-27T23:50:00+01:00',
			'opencatalogi'
		);

		$case = $this->store->row(schema: 'case', uuid: $answer['requestId']);
		// What OpenRegister answers on top of the write is not part of it.
		unset($case['identifier'], $case['deadline']);
		self::assertSame([], (new RealSchemaValidator())->errors(slug: 'case', payload: $case), (string)json_encode($case));
	}//end testTheReceivedCaseValidatesAgainstTheCaseSchema()

	/**
	 * The term counts from when the requester sent it: a form sent late on
	 * 27 November and delivered on 30 November starts on the 27th.
	 *
	 * @return void
	 */
	public function testTheTermCountsFromWhenTheRequesterSentIt(): void {
		$answer = $this->intake()->receive($this->answers(), '2026-11-27T23:50:00+01:00');

		$case = $this->store->row(schema: 'case', uuid: $answer['requestId']);
		self::assertSame('2026-11-27', $case['startDate']);
		self::assertSame('2026-11-27T23:50:00+01:00', $case['receivedAt']);
		self::assertSame('2026-12-28', $answer['dueAt']);
	}//end testTheTermCountsFromWhenTheRequesterSentIt()

	/**
	 * A written case whose term engine did not arm a timer is not armed: the
	 * answer names the case, carries no due date, and the case gets an
	 * internal timeline entry saying why.
	 *
	 * @return void
	 */
	public function testARefusedTimerIsNotArmed(): void {
		$this->instances[0]['engineTimerId'] = '';
		$this->timeline->expects(self::once())->method('record')->with(
			'generated-1',
			'termijngebeurtenis',
			self::stringContains('timer'),
			['event' => 'not-started', 'term' => 'statutory'],
			'internal'
		);

		$answer = $this->intake()->receive($this->answers(), '2026-11-27T10:15:00+01:00');

		self::assertSame('not-armed', $answer['outcome']);
		self::assertSame('generated-1', $answer['requestId']);
		self::assertSame('ZAAK-2026-0042', $answer['reference']);
		self::assertSame('', $answer['dueAt']);
		self::assertStringContainsString('timer', $answer['message']);
	}//end testARefusedTimerIsNotArmed()

	/**
	 * A case deadline that is not the term's end date is not armed either.
	 *
	 * @return void
	 */
	public function testADeadlineThatIsNotTheTermsEndIsNotArmed(): void {
		$this->mirroredDeadline = '2026-12-25';

		$answer = $this->intake()->receive($this->answers(), '2026-11-27T10:15:00+01:00');

		self::assertSame('not-armed', $answer['outcome']);
		self::assertStringContainsString('deadline', $answer['message']);
	}//end testADeadlineThatIsNotTheTermsEndIsNotArmed()

	/**
	 * Without the term engine, or without a term service, nothing is written.
	 *
	 * @return void
	 */
	public function testNoTermEngineWritesNoCase(): void {
		$this->engine = false;
		$answer = $this->intake()->receive($this->answers(), '2026-11-27T10:15:00+01:00');
		self::assertSame('unavailable', $answer['outcome']);

		$this->engine = true;
		$answer = $this->intake(withTerms: false)->receive($this->answers(), '2026-11-27T10:15:00+01:00');
		self::assertSame('unavailable', $answer['outcome']);

		self::assertSame([], $this->store->all(schema: 'case'));
	}//end testNoTermEngineWritesNoCase()
}//end class
