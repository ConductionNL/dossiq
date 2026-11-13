<?php

/**
 * CaseRelationStore lookups, writes and relation rows, edge by edge.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Relation
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
 * @spec openspec/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use JsonSerializable;
use OCA\Dossiq\Service\Relation\CaseRelationStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Lookups answer null on any gap, writes carry the relations and typed lists
 * in one save, and relation rows degrade to none with a warning.
 *
 * @covers \OCA\Dossiq\Service\Relation\CaseRelationStore
 * @uses   \OCA\Dossiq\Service\Support\SerialisedResult
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CaseRelationStoreBehaviourTest extends TestCase {

	/**
	 * Every logger call: level, message, context.
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * A store over the given object service and configuration.
	 *
	 * @param object|null           $objectService The object service, null when OpenRegister is absent.
	 * @param array<string, string> $config        The app config values.
	 *
	 * @return CaseRelationStore The store.
	 */
	private function store(?object $objectService, array $config = ['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType']): CaseRelationStore {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		return new CaseRelationStore(settingsService: $settings, logger: $logger);

	}//end store()

	/**
	 * A serialisable OpenRegister entity.
	 *
	 * @param mixed $data What jsonSerialize answers.
	 *
	 * @return JsonSerializable The entity.
	 */
	private static function entity(mixed $data): JsonSerializable {
		return new class ($data) implements JsonSerializable {

			/**
			 * Constructor.
			 *
			 * @param mixed $data The data.
			 */
			public function __construct(private readonly mixed $data) {
			}//end __construct()

			/**
			 * The data.
			 *
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return $this->data;
			}//end jsonSerialize()
		};

	}//end entity()

	/**
	 * A case and a case type are found under their own schema, an entity is
	 * serialised and an array answered as is.
	 *
	 * @return void
	 */
	public function testACaseAndACaseTypeAreFoundUnderTheirSchema(): void {
		$objects = new FakeObjectService(objects: ['c-1' => self::entity(['id' => 'c-1', 'title' => 'Bouw']), 't-1' => ['id' => 't-1']]);
		$store = $this->store(objectService: $objects);

		$this->assertSame(['id' => 'c-1', 'title' => 'Bouw'], $store->fetchCase(caseUuid: 'c-1'));
		$this->assertSame(['id' => 't-1'], $store->fetchCaseType(caseTypeUuid: 't-1'));
		$this->assertSame([['find', 'c-1', 'dossiq', 'case'], ['find', 't-1', 'dossiq', 'caseType']], $objects->calls);
		$this->assertSame([], $this->logged);
	}//end testACaseAndACaseTypeAreFoundUnderTheirSchema()

	/**
	 * Nothing found, or an entity that does not serialise to an array, is null.
	 *
	 * @return void
	 */
	public function testAMissOrAnUnserialisableAnswerIsNull(): void {
		$store = $this->store(objectService: new FakeObjectService(objects: ['odd' => self::entity('a string'), 'plain' => new \stdClass()]));

		$this->assertNull($store->fetchCase(caseUuid: 'gone'));
		$this->assertNull($store->fetchCase(caseUuid: 'odd'));
		$this->assertNull($store->fetchCase(caseUuid: 'plain'));
	}//end testAMissOrAnUnserialisableAnswerIsNull()

	/**
	 * An empty uuid, no OpenRegister, or no register or schema configured
	 * answers null without asking OpenRegister.
	 *
	 * @return void
	 */
	public function testALookupWithAGapAnswersNullWithoutAsking(): void {
		$objects = new FakeObjectService(objects: ['c-1' => ['id' => 'c-1']]);

		$this->assertNull($this->store(objectService: $objects)->fetchCase(caseUuid: ''));
		$this->assertNull($this->store(objectService: null)->fetchCase(caseUuid: 'c-1'));
		$this->assertNull($this->store(objectService: $objects, config: ['case_schema' => 'case'])->fetchCase(caseUuid: 'c-1'));
		$this->assertNull($this->store(objectService: $objects, config: ['register' => 'dossiq', 'case_schema' => 'case'])->fetchCaseType(caseTypeUuid: 'c-1'));
		$this->assertSame([], $objects->calls);
	}//end testALookupWithAGapAnswersNullWithoutAsking()

	/**
	 * A lookup that throws is null and logged at debug with the uuid and cause.
	 *
	 * @return void
	 */
	public function testAFailingLookupIsNullAndLogged(): void {
		$store = $this->store(objectService: new FakeObjectService(objects: ['t-9' => new RuntimeException('table missing')]));

		$this->assertNull($store->fetchCaseType(caseTypeUuid: 't-9'));
		$this->assertSame([['debug', 'CaseRelationService: case type lookup failed', ['uuid' => 't-9', 'error' => 'table missing']]], $this->logged);
	}//end testAFailingLookupIsNullAndLogged()

	/**
	 * The relations are written as a JSON list in the case's own save, and the
	 * typed lists ride along as lists.
	 *
	 * @return void
	 */
	public function testRelationsAndTypedListsAreWrittenInOneSave(): void {
		$objects = new FakeObjectService();
		$case = ['id' => 'c-1', 'title' => 'Bouw', 'blockingCases' => ['old']];

		$this->store(objectService: $objects)->persistRelations(
			case: $case,
			relations: ['x' => ['uuid' => 'c-2', 'type' => 'waitsOn'], 'y' => ['uuid' => 'c-3', 'type' => 'related']],
			typedLinks: ['blockingCases' => ['k' => 'c-2'], 'relatedCases2' => []]
		);

		$this->assertSame(
			[[
				'saveObject',
				[
					'id' => 'c-1',
					'title' => 'Bouw',
					'blockingCases' => ['c-2'],
					'relatedCases' => '[{"uuid":"c-2","type":"waitsOn"},{"uuid":"c-3","type":"related"}]',
					'relatedCases2' => [],
				],
				'dossiq',
				'case',
			]],
			$objects->calls
		);
	}//end testRelationsAndTypedListsAreWrittenInOneSave()

	/**
	 * Without typed lists only relatedCases changes.
	 *
	 * @return void
	 */
	public function testWithoutTypedListsOnlyTheRelationsChange(): void {
		$objects = new FakeObjectService();

		$this->store(objectService: $objects)->persistRelations(case: ['id' => 'c-1', 'blockingCases' => ['keep']], relations: []);

		$this->assertSame(['id' => 'c-1', 'blockingCases' => ['keep'], 'relatedCases' => '[]'], $objects->calls[0][1]);
	}//end testWithoutTypedListsOnlyTheRelationsChange()

	/**
	 * No OpenRegister or no register or schema configured writes nothing.
	 *
	 * @return void
	 */
	public function testAWriteWithAGapIsSkipped(): void {
		$objects = new FakeObjectService();

		$this->store(objectService: null)->persistRelations(case: ['id' => 'c-1'], relations: []);
		$this->store(objectService: $objects, config: ['register' => 'dossiq'])->persistRelations(case: ['id' => 'c-1'], relations: []);
		$this->store(objectService: $objects, config: ['case_schema' => 'case'])->persistRelations(case: ['id' => 'c-1'], relations: []);

		$this->assertSame([], $objects->calls);
		$this->assertSame([], $this->logged);
	}//end testAWriteWithAGapIsSkipped()

	/**
	 * A save that throws is swallowed and logged as a warning.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsLoggedNotThrown(): void {
		$this->store(objectService: new FakeObjectService(saveFails: new RuntimeException('locked')))->persistRelations(case: ['id' => 'c-1'], relations: []);

		$this->assertSame([['warning', 'CaseRelationService: failed to persist relatedCases', ['error' => 'locked']]], $this->logged);
	}//end testAFailingSaveIsLoggedNotThrown()

	/**
	 * Incoming rows come from used-by, outgoing from uses; entities are
	 * serialised and anything not an array is dropped.
	 *
	 * @return void
	 */
	public function testRelationRowsAreReadPerDirectionAndSerialised(): void {
		$objects = new FakeObjectService(
			usedBy: ['c-1' => ['results' => [['id' => 'in-1'], self::entity(['id' => 'in-2']), self::entity('x'), 'loose', 7, new \stdClass()]]],
			uses: ['c-1' => ['results' => [['id' => 'out-1']]]]
		);
		$store = $this->store(objectService: $objects);

		$this->assertSame([['id' => 'in-1'], ['id' => 'in-2']], $store->relationRows(caseUuid: 'c-1', incoming: true));
		$this->assertSame([['id' => 'out-1']], $store->relationRows(caseUuid: 'c-1', incoming: false));
		$this->assertSame(
			[
				['setRegister', 'dossiq'],
				['setSchema', 'case'],
				['getObjectUsedBy', 'c-1'],
				['setRegister', 'dossiq'],
				['setSchema', 'case'],
				['getObjectUses', 'c-1'],
			],
			$objects->calls
		);
	}//end testRelationRowsAreReadPerDirectionAndSerialised()

	/**
	 * An answer without results, or with results that are not a list, is none.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutResultsIsNone(): void {
		$store = $this->store(objectService: new FakeObjectService(usedBy: ['a' => ['total' => 0], 'b' => ['results' => 'none']]));

		$this->assertSame([], $store->relationRows(caseUuid: 'a', incoming: true));
		$this->assertSame([], $store->relationRows(caseUuid: 'b', incoming: true));
	}//end testAnAnswerWithoutResultsIsNone()

	/**
	 * Without a register or schema configured the scope is left as it is, and
	 * OpenRegister is still asked.
	 *
	 * @return void
	 */
	public function testWithoutConfigTheScopeIsNotSet(): void {
		$objects = new FakeObjectService(uses: ['c-1' => ['results' => [['id' => 'o']]]]);

		$rows = $this->store(objectService: $objects, config: ['register' => 'dossiq'])->relationRows(caseUuid: 'c-1', incoming: false);

		$this->assertSame([['id' => 'o']], $rows);
		$this->assertSame([['getObjectUses', 'c-1']], $objects->calls);
	}//end testWithoutConfigTheScopeIsNotSet()

	/**
	 * An empty uuid or no OpenRegister is none, without asking.
	 *
	 * @return void
	 */
	public function testRelationRowsWithAGapAreNone(): void {
		$objects = new FakeObjectService();

		$this->assertSame([], $this->store(objectService: $objects)->relationRows(caseUuid: '', incoming: true));
		$this->assertSame([], $this->store(objectService: null)->relationRows(caseUuid: 'c-1', incoming: false));
		$this->assertSame([], $objects->calls);
	}//end testRelationRowsWithAGapAreNone()

	/**
	 * OpenRegister failing to answer is none plus a warning naming the case,
	 * the direction and the cause.
	 *
	 * @return void
	 */
	public function testAFailingRelationReadDegradesWithAWarning(): void {
		$store = $this->store(objectService: new FakeObjectService(usedBy: ['c-1' => new RuntimeException('timeout')]));

		$this->assertSame([], $store->relationRows(caseUuid: 'c-1', incoming: true));
		$this->assertSame(
			[['warning', 'Dossiq: OpenRegister could not answer the relation rows for a case', ['uuid' => 'c-1', 'incoming' => true, 'error' => 'timeout']]],
			$this->logged
		);
	}//end testAFailingRelationReadDegradesWithAWarning()
}//end class
