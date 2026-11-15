<?php

/**
 * The cross-cutting guards of the ZGW business rules, branch by branch
 *
 * Pins what the entry point answers for the catalogi concept rules, the
 * publish guard, the closed-case guard and the hand-off to the dispatcher.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Zgw\ZgwRuleResults;
use OCA\Dossiq\Service\Zgw\ZgwRulesDispatcher;
use OCA\Dossiq\Service\ZgwBusinessRulesService;
use OCA\Dossiq\Service\ZgwZtcRulesService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the entry point answers per guard, and what reaches the dispatcher.
 *
 * @covers \OCA\Dossiq\Service\ZgwBusinessRulesService
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
final class ZgwBusinessRulesServiceGuardsTest extends TestCase {
	/**
	 * The calls that reached the ZTC rules and the dispatcher, in order.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	/**
	 * The ZTC rules double.
	 *
	 * @var ZgwZtcRulesService&MockObject
	 */
	private ZgwZtcRulesService $ztc;

	/**
	 * Builds the doubles; the ZTC rules mark the body they touched.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->ztc = $this->createMock(ZgwZtcRulesService::class);
		$this->ztc->method('setContext')->willReturnCallback(
			function (?object $service, ?array $mapping): void {
				$this->calls[] = ['ztc.setContext', $mapping];
			}
		);
		$this->ztc->method('defaultConcept')->willReturnCallback(
			function (array $body, string $resource): array {
				$this->calls[] = ['defaultConcept', $resource];
				return $body + ['concept' => true];
			}
		);
		$this->ztc->method('preserveConcept')->willReturnCallback(
			function (array $body, string $resource, ?array $existing): array {
				$this->calls[] = ['preserveConcept', $resource];
				return $body + ['preserved' => true];
			}
		);
	}//end setUp()

	/**
	 * The entry point over the ZTC double and a dispatcher that records what it got.
	 *
	 * @return ZgwBusinessRulesService
	 */
	private function rules(): ZgwBusinessRulesService {
		$dispatcher = $this->createMock(ZgwRulesDispatcher::class);
		$dispatcher->method('setContext')->willReturnCallback(
			function (?object $service, ?array $mapping): void {
				$this->calls[] = ['dispatcher.setContext', $mapping];
			}
		);
		$dispatcher->method('dispatch')->willReturnCallback(
			function (string $zgwApi, string $resource, string $action, array $body, ?array $existingObject = null): array {
				$this->calls[] = ['dispatch', $zgwApi, $resource, $action, $body, $existingObject];
				return ['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => $body];
			}
		);

		return new ZgwBusinessRulesService(ztcRules: $this->ztc, dispatcher: $dispatcher, results: new ZgwRuleResults(logger: $this->createMock(LoggerInterface::class)));
	}//end rules()

	/**
	 * A catalogi create gets its concept default, and the enriched body reaches the dispatcher.
	 *
	 * @return void
	 */
	public function testACatalogiCreateIsDefaultedThenDispatched(): void {
		$this->ztc->method('checkConceptProtection')->willReturnCallback(
			function (string $resource, string $action, array $body, ?array $existing, ?bool $parentDraft): ?array {
				$this->calls[] = ['checkConceptProtection', $resource, $action, $body, $existing, $parentDraft];
				return null;
			}
		);

		$result = $this->rules()->validate(zgwApi: 'catalogi', resource: 'statustypen', action: 'create', body: ['a' => 1], parentCaseTypeDraft: true, mappingConfig: ['m' => 1]);

		$this->assertSame(['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => ['a' => 1, 'concept' => true]], $result);
		$this->assertSame(
			[
				['ztc.setContext', ['m' => 1]],
				['dispatcher.setContext', ['m' => 1]],
				['defaultConcept', 'statustypen'],
				['checkConceptProtection', 'statustypen', 'create', ['a' => 1, 'concept' => true], null, true],
				['dispatch', 'catalogi', 'statustypen', 'create', ['a' => 1, 'concept' => true], null],
			],
			$this->calls
		);
	}//end testACatalogiCreateIsDefaultedThenDispatched()

	/**
	 * An update keeps its concept flag, and a concept refusal stops the write before any other guard.
	 *
	 * @return void
	 */
	public function testAConceptRefusalStopsTheWrite(): void {
		$refusal = ['valid' => false, 'status' => 403, 'detail' => 'published', 'enrichedBody' => []];
		$this->ztc->method('checkConceptProtection')->willReturn($refusal);
		$this->ztc->expects($this->never())->method('validatePublish');

		$result = $this->rules()->validate(zgwApi: 'catalogi', resource: 'zaaktypen', action: 'patch', body: ['isDraft' => false], existingObject: ['id' => 'ct', 'isDraft' => true], mappingConfig: ['sourceRegister' => 'r']);

		$this->assertSame($refusal, $result);
		$this->assertSame(['preserveConcept', 'zaaktypen'], $this->calls[2]);
		$this->assertCount(3, $this->calls, 'nothing is dispatched');
	}//end testAConceptRefusalStopsTheWrite()

	/**
	 * Publishing a draft case type with publish errors is refused with 422 and the enriched body.
	 *
	 * @return void
	 */
	public function testPublishingAnIncompleteDraftIsRefused(): void {
		$this->ztc->method('checkConceptProtection')->willReturn(null);
		$this->ztc->expects($this->once())->method('validatePublish')->with('r', 'ct')->willReturn(['no status', 'no end']);

		$result = $this->rules()->validate(zgwApi: 'catalogi', resource: 'zaaktypen', action: 'update', body: ['isDraft' => false], existingObject: ['id' => 'ct', 'isDraft' => true], mappingConfig: ['sourceRegister' => 'r']);

		$this->assertSame(
			['valid' => false, 'status' => 422, 'detail' => 'no status; no end', 'code' => 'publish_validation_failed', 'enrichedBody' => ['isDraft' => false, 'preserved' => true]],
			$result
		);
	}//end testPublishingAnIncompleteDraftIsRefused()

	/**
	 * A complete publish, a save that is no publish, and a publish without a register all go on.
	 *
	 * @return void
	 */
	public function testSavesThatAreNoRefusablePublishGoOn(): void {
		$this->ztc->method('checkConceptProtection')->willReturn(null);
		$this->ztc->expects($this->once())->method('validatePublish')->with('r', 'ct')->willReturn([]);
		$rules    = $this->rules();
		$draft    = ['id' => 'ct', 'isDraft' => true];
		$register = ['sourceRegister' => 'r'];

		$saves = [
			$rules->validate(zgwApi: 'catalogi', resource: 'zaaktypen', action: 'update', body: ['isDraft' => false], existingObject: $draft, mappingConfig: $register),
			$rules->validate(zgwApi: 'catalogi', resource: 'zaaktypen', action: 'update', body: ['isDraft' => true], existingObject: $draft, mappingConfig: $register),
			$rules->validate(zgwApi: 'catalogi', resource: 'zaaktypen', action: 'update', body: ['x' => 1], existingObject: $draft, mappingConfig: $register),
			$rules->validate(zgwApi: 'catalogi', resource: 'zaaktypen', action: 'update', body: ['isDraft' => false], existingObject: ['id' => 'ct'], mappingConfig: $register),
			$rules->validate(zgwApi: 'catalogi', resource: 'zaaktypen', action: 'update', body: ['isDraft' => false], mappingConfig: $register),
			$rules->validate(zgwApi: 'catalogi', resource: 'statustypen', action: 'update', body: ['isDraft' => false], existingObject: $draft, mappingConfig: $register),
			$rules->validate(zgwApi: 'catalogi', resource: 'zaaktypen', action: 'update', body: ['isDraft' => false], existingObject: $draft),
			$rules->validate(zgwApi: 'catalogi', resource: 'zaaktypen', action: 'update', body: ['isDraft' => false], existingObject: ['isDraft' => true], mappingConfig: $register),
			$rules->validate(zgwApi: 'catalogi', resource: 'zaaktypen', action: 'destroy', body: [], mappingConfig: $register),
		];

		foreach ($saves as $result) {
			$this->assertTrue($result['valid']);
		}
	}//end testSavesThatAreNoRefusablePublishGoOn()

	/**
	 * A write to a closed case is refused without the forced-update scope, and goes on with it.
	 *
	 * @return void
	 */
	public function testAClosedCaseNeedsTheForcedUpdateScope(): void {
		$rules = $this->rules();

		$this->assertSame(
			[
				'valid' => false,
				'status' => 403,
				'detail' => 'Zaak is afgesloten. Wijzigingen zijn niet toegestaan zonder scope zaken.geforceerd-bijwerken.',
				'code' => 'permission_denied',
				'invalidParams' => [['name' => 'nonFieldErrors', 'code' => 'zaak-closed', 'reason' => 'De zaak is afgesloten.']],
				'enrichedBody' => [],
			],
			$rules->validate(zgwApi: 'zaken', resource: 'zaken', action: 'patch', body: ['a' => 1], caseClosed: true, hasGeforceerd: false)
		);
		$this->assertCount(2, $this->calls, 'a refused write is not dispatched');

		$this->assertTrue($rules->validate(zgwApi: 'zaken', resource: 'zaken', action: 'patch', body: ['a' => 1], caseClosed: true)['valid']);
		$this->assertTrue($rules->validate(zgwApi: 'zaken', resource: 'zaken', action: 'patch', body: ['a' => 1], caseClosed: false, hasGeforceerd: false)['valid']);
		$this->assertSame(['dispatch', 'zaken', 'zaken', 'patch', ['a' => 1], null], $this->calls[4]);
	}//end testAClosedCaseNeedsTheForcedUpdateScope()
}//end class
