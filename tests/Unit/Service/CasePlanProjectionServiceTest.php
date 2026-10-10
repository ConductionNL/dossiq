<?php

/**
 * Projection tests: a dossiq `caseModel` onto an OpenRegister case-plan definition.
 *
 * One fixture per if-part operator (design.md section 2 calls the table
 * closed, so the test asserts every member of it AND asserts the refusal of a
 * non-member), both on-part shapes, the flat-to-nested tree build, and the
 * refusals that keep a mistranslation from looking like a sentry that simply
 * has not fired yet.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CasePlanProjectionService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * @covers \OCA\Dossiq\Service\CasePlanProjectionService
 * @uses \OCA\Dossiq\Service\SettingsService
 */
final class CasePlanProjectionServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var CasePlanProjectionService
	 */
	private CasePlanProjectionService $projection;

	/**
	 * Build the service over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->projection = new CasePlanProjectionService(
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Every operator the closed table names, and what JSONLogic it becomes.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function operatorFixtures(): array {
		$variable = ['var' => 'advies'];

		return [
			'eq' => [['field' => 'advies', 'operator' => 'eq', 'value' => 'positief'], ['==' => [$variable, 'positief']]],
			'neq' => [['field' => 'advies', 'operator' => 'neq', 'value' => 'positief'], ['!=' => [$variable, 'positief']]],
			'gt' => [['field' => 'advies', 'operator' => 'gt', 'value' => 3], ['>' => [$variable, 3]]],
			'gte' => [['field' => 'advies', 'operator' => 'gte', 'value' => 3], ['>=' => [$variable, 3]]],
			'lt' => [['field' => 'advies', 'operator' => 'lt', 'value' => 3], ['<' => [$variable, 3]]],
			'lte' => [['field' => 'advies', 'operator' => 'lte', 'value' => 3], ['<=' => [$variable, 3]]],
			'in' => [['field' => 'advies', 'operator' => 'in', 'value' => ['a', 'b']], ['in' => [$variable, ['a', 'b']]]],
			'notIn' => [['field' => 'advies', 'operator' => 'notIn', 'value' => ['a', 'b']], ['!' => ['in' => [$variable, ['a', 'b']]]]],
			'truthy' => [['field' => 'advies', 'operator' => 'truthy'], ['!!' => $variable]],
			'falsy' => [['field' => 'advies', 'operator' => 'falsy'], ['!' => $variable]],
		];
	}//end operatorFixtures()

	/**
	 * Each operator in the closed table converts to its named JSONLogic rule.
	 *
	 * @param array<string, mixed> $ifPart   The dossiq if-part.
	 * @param array<string, mixed> $expected The JSONLogic it must become.
	 *
	 * @return void
	 *
	 * @dataProvider operatorFixtures
	 */
	public function testEveryTableOperatorConverts(array $ifPart, array $expected): void {
		$this->assertSame($expected, $this->projection->convertIfPart(ifPart: $ifPart, where: 'fixture'));
	}//end testEveryTableOperatorConverts()

	/**
	 * The table is CLOSED: the fixtures above are the whole of it, so a new
	 * operator cannot be added to the service without a fixture beside it.
	 *
	 * @return void
	 */
	public function testTheOperatorTableHasNoMemberWithoutAFixture(): void {
		$this->assertSame(
			array_keys(self::operatorFixtures()),
			array_keys(CasePlanProjectionService::OPERATORS),
		);
	}//end testTheOperatorTableHasNoMemberWithoutAFixture()

	/**
	 * An operator outside the table is refused BY NAME, never approximated.
	 *
	 * @return void
	 */
	public function testAnOperatorOutsideTheTableIsRefusedByName(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage("uses operator 'contains', which is outside the conversion table");
		$this->projection->convertIfPart(ifPart: ['field' => 'advies', 'operator' => 'contains', 'value' => 'x'], where: 'fixture');
	}//end testAnOperatorOutsideTheTableIsRefusedByName()

	/**
	 * An if-part with no field is refused rather than treated as vacuously true.
	 *
	 * @return void
	 */
	public function testAnIfPartWithoutAFieldIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('has an ifPart with no field');
		$this->projection->convertIfPart(ifPart: ['operator' => 'eq', 'value' => 1], where: 'fixture');
	}//end testAnIfPartWithoutAFieldIsRefused()

	/**
	 * A plan-item on-part becomes the matching catalog event, naming the item.
	 *
	 * @return void
	 */
	public function testAPlanItemOnPartBecomesTheCatalogEvent(): void {
		$this->assertSame(
			['on' => ['event' => 'case.item.completed', 'item' => 'intake']],
			$this->projection->convertSentry(sentry: ['onPart' => ['planItem' => 'intake', 'standardEvent' => 'complete']], where: 'fixture'),
		);
		$this->assertSame(
			['on' => ['event' => 'case.item.terminated', 'item' => 'intake']],
			$this->projection->convertSentry(sentry: ['onPart' => ['planItem' => 'intake', 'standardEvent' => 'terminate']], where: 'fixture'),
		);
		$this->assertSame(
			['on' => ['event' => 'case.item.disabled', 'item' => 'intake']],
			$this->projection->convertSentry(sentry: ['onPart' => ['planItem' => 'intake', 'standardEvent' => 'disable']], where: 'fixture'),
		);
	}//end testAPlanItemOnPartBecomesTheCatalogEvent()

	/**
	 * A case-file on-part becomes the case object's write event.
	 *
	 * @return void
	 */
	public function testACaseFileOnPartBecomesTheObjectWriteEvent(): void {
		$this->assertSame(
			['on' => ['event' => 'object.updated']],
			$this->projection->convertSentry(sentry: ['onPart' => ['caseFileItem' => 'advies', 'caseFileEvent' => 'set']], where: 'fixture'),
		);
	}//end testACaseFileOnPartBecomesTheObjectWriteEvent()

	/**
	 * A standardEvent outside the table is refused by name.
	 *
	 * @return void
	 */
	public function testAnUnknownStandardEventIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage("names standardEvent 'suspend', which is outside the conversion table");
		$this->projection->convertSentry(sentry: ['onPart' => ['planItem' => 'intake', 'standardEvent' => 'suspend']], where: 'fixture');
	}//end testAnUnknownStandardEventIsRefused()

	/**
	 * A sentry carrying both parts keeps both, AND-ed the way both sides read them.
	 *
	 * @return void
	 */
	public function testASentryKeepsItsIdAndBothParts(): void {
		$converted = $this->projection->convertSentry(
			sentry: [
				'id' => 's1',
				'onPart' => ['planItem' => 'intake', 'standardEvent' => 'complete'],
				'ifPart' => ['field' => 'advies', 'operator' => 'eq', 'value' => 'positief'],
			],
			where: 'fixture',
		);

		$this->assertSame(
			['id' => 's1', 'on' => ['event' => 'case.item.completed', 'item' => 'intake'], 'if' => ['==' => [['var' => 'advies'], 'positief']]],
			$converted,
		);
	}//end testASentryKeepsItsIdAndBothParts()

	/**
	 * A flat `planItems` list with `parentId` links becomes the nested tree
	 * OpenRegister validates, and discretionary becomes `required: false`.
	 *
	 * @return void
	 */
	public function testTheFlatListBecomesANestedTree(): void {
		$definition = $this->projection->convertModel(caseModel: self::model());

		$this->assertSame(['intake', 'besluit'], array_column($definition['items'], 'key'));

		$intake = $definition['items'][0];
		$this->assertSame('stage', $intake['type']);
		$this->assertSame(['controle', 'extra-advies'], array_column($intake['children'], 'key'));

		$this->assertTrue($intake['children'][0]['required']);
		$this->assertFalse($intake['children'][0]['discretionary']);
		$this->assertFalse($intake['children'][1]['required']);
		$this->assertTrue($intake['children'][1]['discretionary']);

		$this->assertSame(
			[['id' => 's1', 'on' => ['event' => 'case.item.completed', 'item' => 'controle']]],
			$definition['items'][1]['entryCriteria'],
		);
	}//end testTheFlatListBecomesANestedTree()

	/**
	 * A milestone is never nested under a non-stage, and a dangling parent is
	 * refused rather than dropping the child out of the tree unreported.
	 *
	 * @return void
	 */
	public function testADanglingParentIsRefused(): void {
		$model = self::model();
		$model['planItems'][1]['parentId'] = 'nowhere';

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage("names parent 'nowhere', which the model does not declare");
		$this->projection->convertModel(caseModel: $model);
	}//end testADanglingParentIsRefused()

	/**
	 * A discretionary milestone is refused here rather than at the OpenRegister
	 * boundary, so the message names the caseModel item.
	 *
	 * @return void
	 */
	public function testADiscretionaryMilestoneIsRefused(): void {
		$model = self::model();
		$model['planItems'][3]['discretionary'] = true;

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('is a discretionary milestone');
		$this->projection->convertModel(caseModel: $model);
	}//end testADiscretionaryMilestoneIsRefused()

	/**
	 * A BPMN-managed caseType is never projected.
	 *
	 * @return void
	 */
	public function testABpmnCaseTypeIsNotProjected(): void {
		$this->assertSame(
			['projected' => false, 'reason' => 'case_not_cmmn_managed'],
			$this->projection->projectAtCaseStart(caseId: 'case-1', caseType: ['id' => 'ct-1', 'handlingModel' => 'bpmn']),
		);
	}//end testABpmnCaseTypeIsNotProjected()

	/**
	 * A caseType with no `handlingModel` at all defaults to BPMN, exactly as
	 * `CasePlanRepository` read it.
	 *
	 * @return void
	 */
	public function testAnUnsetHandlingModelDefaultsToBpmn(): void {
		$this->assertSame(
			['projected' => false, 'reason' => 'case_not_cmmn_managed'],
			$this->projection->projectAtCaseStart(caseId: 'case-1', caseType: ['id' => 'ct-1']),
		);
	}//end testAnUnsetHandlingModelDefaultsToBpmn()

	/**
	 * An absent case layer is reported, not silently treated as an empty plan.
	 *
	 * @return void
	 */
	public function testAnAbsentCaseLayerIsReported(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(null);
		$projection = new CasePlanProjectionService($settings, $this->createMock(LoggerInterface::class));

		$this->assertSame(
			['projected' => false, 'reason' => 'case_layer_unavailable'],
			$projection->projectAtCaseStart(caseId: 'case-1', caseType: ['id' => 'ct-1', 'handlingModel' => 'cmmn']),
		);
	}//end testAnAbsentCaseLayerIsReported()

	/**
	 * The plan is created through the system verb, acting as dossiq.
	 *
	 * The first bridge passed `uid: null` to `createPlan()`, which
	 * OpenRegister refuses, so every case start created nothing and logged an
	 * error. This pins the call that replaced it.
	 *
	 * @return void
	 */
	public function testThePlanIsCreatedAsTheDossiqSystemActor(): void {
		$layer = new class {
			/**
			 * What the projection handed over.
			 *
			 * @var array<string, mixed>
			 */
			public array $calls = [];

			/**
			 * Record the call, the way OpenRegister's system verb is shaped.
			 *
			 * @param string               $objectUuid The anchoring object.
			 * @param int|null             $registerId Its register.
			 * @param int|null             $schemaId   Its schema.
			 * @param array<string, mixed> $definition The definition.
			 * @param string               $app        The acting app id.
			 *
			 * @return array<string, mixed> An empty plan.
			 */
			public function createPlanAsSystem(string $objectUuid, ?int $registerId, ?int $schemaId, array $definition, string $app): array {
				$this->calls[] = compact('objectUuid', 'registerId', 'schemaId', 'definition', 'app');

				return [];
			}
		};

		$projection = new CasePlanProjectionService($this->settingsWith(layer: $layer), $this->createMock(LoggerInterface::class));
		$result = $projection->projectAtCaseStart(caseId: 'case-1', caseType: ['id' => 'ct-1', 'handlingModel' => 'cmmn']);

		$this->assertSame(['projected' => true, 'reason' => 'projected'], $result);
		$this->assertCount(1, $layer->calls);
		$this->assertSame('dossiq', $layer->calls[0]['app']);
		$this->assertSame('case-1', $layer->calls[0]['objectUuid']);
		$this->assertSame(7, $layer->calls[0]['registerId']);
		$this->assertSame(11, $layer->calls[0]['schemaId']);
		$this->assertSame('intake', $layer->calls[0]['definition']['items'][0]['key']);
	}//end testThePlanIsCreatedAsTheDossiqSystemActor()

	/**
	 * An OpenRegister without the system verb is named, not called with no identity.
	 *
	 * @return void
	 */
	public function testACaseLayerWithoutTheSystemVerbIsReportedByName(): void {
		$layer = new class {
			/**
			 * The old, identity-bound verb: calling it would be refused.
			 *
			 * @return array<string, mixed> Never.
			 */
			public function createPlan(): array {
				throw new \LogicException('the projection must not call createPlan without an identity');
			}
		};

		$projection = new CasePlanProjectionService($this->settingsWith(layer: $layer), $this->createMock(LoggerInterface::class));

		$this->assertSame(
			['projected' => false, 'reason' => 'case_layer_lacks_system_verbs'],
			$projection->projectAtCaseStart(caseId: 'case-1', caseType: ['id' => 'ct-1', 'handlingModel' => 'cmmn']),
		);
	}//end testACaseLayerWithoutTheSystemVerbIsReportedByName()

	/**
	 * Settings that resolve the given case layer and answer the fixture model.
	 *
	 * @param object $layer The stand-in case layer.
	 *
	 * @return SettingsService The settings stub.
	 */
	private function settingsWith(object $layer): SettingsService {
		$model = self::model();
		$objects = new class($model) {
			/**
			 * Hold the model to answer with.
			 *
			 * @param array<string, mixed> $model The published caseModel.
			 */
			public function __construct(private readonly array $model) {
			}//end __construct()

			/**
			 * Answer the published model for any query.
			 *
			 * @param array<string, mixed> $query The search query.
			 *
			 * @return array<int, array<string, mixed>> The model, as one hit.
			 */
			public function searchObjects(array $query): array {
				unset($query);

				return [$this->model];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn($layer);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', '7'],
				['case_schema', '', '11'],
				['case_model_schema', '', '13'],
			]
		);

		return $settings;
	}//end settingsWith()

	/**
	 * The case layer is resolved by the name OpenRegister actually publishes.
	 *
	 * @return void
	 */
	public function testTheCaseLayerIsResolvedByItsFullName(): void {
		$this->assertSame(
			'OCA\\OpenRegister\\Service\\Case\\CasePlanService',
			CasePlanProjectionService::CASE_PLAN_SERVICE,
		);
	}//end testTheCaseLayerIsResolvedByItsFullName()

	/**
	 * A two-stage model with a nested mandatory task, a nested discretionary
	 * task and a milestone gated on the task.
	 *
	 * @return array<string, mixed> The caseModel.
	 */
	private static function model(): array {
		return [
			'title' => 'Vergunningaanvraag',
			'planItems' => [
				['id' => 'intake', 'type' => 'stage', 'name' => 'Intake'],
				['id' => 'controle', 'type' => 'humanTask', 'name' => 'Controle', 'parentId' => 'intake'],
				['id' => 'extra-advies', 'type' => 'humanTask', 'name' => 'Extra advies', 'parentId' => 'intake', 'discretionary' => true],
				[
					'id' => 'besluit',
					'type' => 'milestone',
					'name' => 'Besluit genomen',
					'entryCriteria' => [['id' => 's1', 'onPart' => ['planItem' => 'controle', 'standardEvent' => 'complete']]],
				],
			],
		];
	}//end model()
}//end class
