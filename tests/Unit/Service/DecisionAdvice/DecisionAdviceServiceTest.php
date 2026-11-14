<?php

/**
 * Unit tests for DecisionAdviceService: the case type's decision table asked
 * with the inputs it declares, the recommendation stored on the case, and the
 * handler's choice recorded with a reason for a deviation.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\DecisionAdvice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\DecisionAdvice;

use DateTime;
use OCA\Dossiq\Service\DecisionAdvice\DecisionAdviceRefusal;
use OCA\Dossiq\Service\DecisionAdvice\DecisionAdviceService;
use OCA\Dossiq\Service\DecisionAdvice\DecisionAdviceStore;
use OCA\Dossiq\Service\Deelzaak\CaseObjectReader;
use OCA\Dossiq\Service\Dmn\StoredDecisionTables;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An object service stand-in that records what is saved and finds by id.
 */
class RecordingAdviceStore {

	/**
	 * Every save, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * Rows by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $rows = [];

	public bool $refuseSave = false;

	public function saveObject(string $register, string $schema, array $object): array {
		if ($this->refuseSave === true) {
			throw new RuntimeException('refused');
		}

		$this->saved[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
		$object['id'] = ($object['id'] ?? 'advice-1');
		$this->rows[$object['id']] = $object;
		return $object;
	}

	public function find(string $id, string $register, string $schema): ?array {
		if (isset($this->rows[$id]) === false) {
			throw new RuntimeException('not found');
		}

		return $this->rows[$id];
	}
}

/**
 * @covers \OCA\Dossiq\Service\DecisionAdvice\DecisionAdviceService
 * @covers \OCA\Dossiq\Service\DecisionAdvice\DecisionAdviceRefusal
 * @covers \OCA\Dossiq\Service\DecisionAdvice\DecisionAdviceStore
 */
class DecisionAdviceServiceTest extends TestCase {

	private const TABLE = [
		'key' => 'dossiq.lhs-intervention',
		'inputs' => [['name' => 'behaviour', 'type' => 'string'], ['name' => 'consequence', 'type' => 'string']],
		'outputs' => [['name' => 'interventionStep', 'type' => 'string'], ['name' => 'description', 'type' => 'string']],
	];

	private CaseObjectReader&MockObject $cases;

	private StoredDecisionTables&MockObject $tables;

	private SettingsService&MockObject $settings;

	private RecordingAdviceStore $store;

	private DecisionAdviceService $service;

	protected function setUp(): void {
		$this->cases = $this->createMock(CaseObjectReader::class);
		$this->tables = $this->createMock(StoredDecisionTables::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->store = new RecordingAdviceStore();
		$this->settings->method('getObjectService')->willReturn($this->store);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'decision_advice_schema' => 'decisionAdvice',
				default => '',
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-13T10:00:00+00:00'));

		$this->service = new DecisionAdviceService($this->cases, $this->tables, new DecisionAdviceStore($this->settings), $time);
	}//end setUp()

	/**
	 * A case whose type names the table, or names none.
	 *
	 * @param string $table What the case type names.
	 *
	 * @return void
	 */
	private function caseOfTypeNaming(string $table): void {
		$this->cases->method('fetchCaseById')->with('case-1')->willReturn(['id' => 'case-1', 'caseType' => ['id' => 'type-1']]);
		$this->cases->method('loadCaseType')->with('type-1')->willReturn(['id' => 'type-1', 'adviceDecisionTable' => $table]);
	}//end caseOfTypeNaming()

	/**
	 * The service's refusal of a call, as status and message.
	 *
	 * @param callable $call The call.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function refusal(callable $call): array {
		try {
			$call();
		} catch (DecisionAdviceRefusal $e) {
			return [$e->getStatus(), $e->getMessage()];
		}

		$this->fail('Expected a refusal.');
	}//end refusal()

	public function testTheTableIsAskedWithItsDeclaredInputsAndTheAdviceIsStoredOnTheCase(): void {
		$this->caseOfTypeNaming(table: 'dossiq.lhs-intervention');
		$this->tables->expects($this->once())->method('find')->with('dossiq.lhs-intervention')->willReturn(self::TABLE);
		$this->tables->expects($this->once())->method('evaluate')
			->with('dossiq.lhs-intervention', ['behaviour' => 'C', 'consequence' => '3'])
			->willReturn(['outputs' => ['interventionStep' => 'Bestuurlijke sanctie', 'description' => 'Leg een last op.']]);

		$advice = $this->service->recommend(
			caseId: 'case-1',
			inputs: ['behaviour' => ' C ', 'consequence' => '3', 'severity' => 'ignored'],
			userId: 'handler'
		);

		$this->assertSame(
			[
				'case' => 'case-1',
				'decisionTable' => 'dossiq.lhs-intervention',
				'inputs' => ['behaviour' => 'C', 'consequence' => '3'],
				'outputs' => ['interventionStep' => 'Bestuurlijke sanctie', 'description' => 'Leg een last op.'],
				'recommendation' => 'Bestuurlijke sanctie',
				'recommendedBy' => 'handler',
				'recommendedAt' => '2026-11-13T10:00:00+00:00',
			],
			$this->store->saved[0]['object']
		);
		$this->assertSame(['dossiq', 'decisionAdvice'], [$this->store->saved[0]['register'], $this->store->saved[0]['schema']]);
		$this->assertSame('advice-1', $advice['id']);
	}

	public function testACaseTypeWithoutATableIsRefusedWith409(): void {
		$this->caseOfTypeNaming(table: '  ');
		$this->tables->expects($this->never())->method('find');

		[$status, $message] = $this->refusal(fn () => $this->service->recommend(caseId: 'case-1', inputs: ['behaviour' => 'A'], userId: 'handler'));

		$this->assertSame(409, $status);
		$this->assertSame('Het zaaktype van deze zaak noemt geen beslistabel voor advies', $message);
		$this->assertSame([], $this->store->saved);
	}

	public function testAnUnknownCaseIsRefusedWith404(): void {
		$this->cases->method('fetchCaseById')->willReturn(null);

		$this->assertSame(404, $this->refusal(fn () => $this->service->recommend(caseId: 'nope', inputs: [], userId: 'h'))[0]);
	}

	public function testAMissingInputIsNamedAndNothingIsEvaluated(): void {
		$this->caseOfTypeNaming(table: 'dossiq.lhs-intervention');
		$this->tables->method('find')->willReturn(self::TABLE);
		$this->tables->expects($this->never())->method('evaluate');

		[$status, $message] = $this->refusal(fn () => $this->service->recommend(caseId: 'case-1', inputs: ['behaviour' => 'A', 'consequence' => ['x']], userId: 'h'));

		$this->assertSame([400, 'Vul in: consequence'], [$status, $message]);
	}

	public function testATableThatCannotBeFoundOrAnswersNothingIsRefused(): void {
		$this->caseOfTypeNaming(table: 'dossiq.lhs-intervention');
		$this->tables->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('no table')),
			null,
			['inputs' => []],
			self::TABLE,
			self::TABLE,
			self::TABLE
		);
		$this->tables->method('evaluate')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('no rule matches')),
			['outputs' => []],
			['outputs' => ['interventionStep' => '', 'description' => 'x']]
		);
		$ask = fn () => $this->service->recommend(caseId: 'case-1', inputs: ['behaviour' => 'A', 'consequence' => '1'], userId: 'h');

		$this->assertSame(409, $this->refusal($ask)[0]);
		$this->assertSame(503, $this->refusal($ask)[0]);
		$this->assertSame([409, 'De beslistabel declareert geen invoer'], $this->refusal($ask));
		$this->assertSame([422, 'De beslistabel geeft geen advies voor deze invoer: no rule matches'], $this->refusal($ask));
		$this->assertSame(422, $this->refusal($ask)[0]);
		$this->assertSame(422, $this->refusal($ask)[0]);
		$this->assertSame([], $this->store->saved);
	}

	public function testAcceptingRecordsTheRecommendationAsTheOutcome(): void {
		$this->store->rows['advice-1'] = ['id' => 'advice-1', 'case' => 'case-1', 'recommendation' => 'Bestuurlijke sanctie'];

		$decided = $this->service->decide(
			advice: $this->service->find(adviceId: 'advice-1'),
			outcome: 'Bestuurlijke sanctie',
			reason: '',
			userId: 'handler'
		);

		$this->assertSame('Bestuurlijke sanctie', $decided['outcome']);
		$this->assertTrue($decided['accepted']);
		$this->assertSame('', $decided['reason']);
		$this->assertSame('handler', $decided['decidedBy']);
		$this->assertSame('2026-11-13T10:00:00+00:00', $decided['decidedAt']);
		$this->assertSame('case-1', $decided['case']);
	}

	public function testADeviationWithoutAReasonIsRefusedAndNothingIsStored(): void {
		$advice = ['id' => 'advice-1', 'case' => 'case-1', 'recommendation' => 'Bestuurlijke sanctie'];

		[$status, $message] = $this->refusal(fn () => $this->service->decide(advice: $advice, outcome: 'Aanspreken', reason: "  \n", userId: 'h'));

		$this->assertSame([400, 'Geef een reden voor de afwijking'], [$status, $message]);
		$this->assertSame([], $this->store->saved);
	}

	public function testADeviationWithAReasonIsRecorded(): void {
		$advice = ['id' => 'advice-1', 'case' => 'case-1', 'recommendation' => 'Bestuurlijke sanctie'];

		$decided = $this->service->decide(advice: $advice, outcome: ' Aanspreken ', reason: ' Eerste overtreding ', userId: 'h');

		$this->assertSame(['Aanspreken', false, 'Eerste overtreding'], [$decided['outcome'], $decided['accepted'], $decided['reason']]);
	}

	public function testNoOutcomeOrASecondDecisionIsRefused(): void {
		$open = ['id' => 'advice-1', 'case' => 'case-1', 'recommendation' => 'X'];

		$this->assertSame(400, $this->refusal(fn () => $this->service->decide(advice: $open, outcome: ' ', reason: '', userId: 'h'))[0]);
		$this->assertSame(409, $this->refusal(fn () => $this->service->decide(advice: $open + ['outcome' => 'X'], outcome: 'X', reason: '', userId: 'h'))[0]);
	}

	public function testUnknownAdviceAndAnUnavailableStoreAreRefused(): void {
		$this->assertSame(404, $this->refusal(fn () => $this->service->find(adviceId: 'missing'))[0]);

		$this->store->refuseSave = true;
		$this->assertSame(500, $this->refusal(fn () => $this->service->decide(advice: ['case' => 'c', 'recommendation' => 'X'], outcome: 'X', reason: '', userId: 'h'))[0]);
	}

	public function testWithoutOpenRegisterNothingIsAsked(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$service = new DecisionAdviceService($this->cases, $this->tables, new DecisionAdviceStore($settings), $this->createMock(ITimeFactory::class));

		$this->assertSame(503, $this->refusal(fn () => $service->find(adviceId: 'a'))[0]);
	}

	public function testTheFormListsTheDeclaredInputsWithTheValuesTheRulesUseAndTheOutcomes(): void {
		$this->caseOfTypeNaming(table: 'dossiq.lhs-intervention');
		$this->tables->expects($this->never())->method('evaluate');
		$this->tables->expects($this->once())->method('find')->with('dossiq.lhs-intervention')->willReturn(
			self::TABLE + [
				'rules' => [
					['inputEntries' => ['A', '1'], 'outputEntries' => ['Aanspreken', 'x']],
					['inputEntries' => ['"B"', '1'], 'outputEntries' => ['Waarschuwen', 'y']],
					['inputEntries' => ['A', '-'], 'outputEntries' => ['Aanspreken', 'z']],
					['inputEntries' => ['C', ''], 'outputEntries' => [['not', 'scalar'], 'w']],
					'not a rule',
				],
			]
		);

		$this->assertSame(
			[
				'available' => true,
				'decisionTable' => 'dossiq.lhs-intervention',
				'inputs' => [
					['name' => 'behaviour', 'type' => 'string', 'options' => ['A', 'B', 'C']],
					['name' => 'consequence', 'type' => 'string', 'options' => ['1']],
				],
				'outcomes' => ['Aanspreken', 'Waarschuwen'],
			],
			$this->service->form(caseId: 'case-1')
		);
		$this->assertSame([], $this->store->saved);
	}

	public function testTheFormOfACaseTypeWithoutATableIsUnavailableAndReadsNoTable(): void {
		$this->caseOfTypeNaming(table: '');
		$this->tables->expects($this->never())->method('find');

		$this->assertSame(
			['available' => false, 'decisionTable' => '', 'inputs' => [], 'outcomes' => []],
			$this->service->form(caseId: 'case-1')
		);
	}

	public function testTheFormOfATableWithoutInputsIsUnavailable(): void {
		$this->caseOfTypeNaming(table: 'dossiq.lhs-intervention');
		$this->tables->method('find')->willReturn(['inputs' => [['type' => 'string'], 'x'], 'outputs' => [], 'rules' => []]);

		$this->assertFalse($this->service->form(caseId: 'case-1')['available']);
	}

	public function testTheFormOfAnUnknownCaseIsRefusedWith404(): void {
		$this->cases->method('fetchCaseById')->willReturn(null);

		$this->assertSame(404, $this->refusal(fn () => $this->service->form(caseId: 'nope'))[0]);
	}

	public function testTheFormOfAMissingTableIsRefusedWith409(): void {
		$this->caseOfTypeNaming(table: 'gone');
		$this->tables->method('find')->willThrowException(new RuntimeException('no'));

		$this->assertSame(
			[409, 'De beslistabel "gone" is niet gevonden'],
			$this->refusal(fn () => $this->service->form(caseId: 'case-1'))
		);
	}
}
