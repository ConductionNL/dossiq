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
