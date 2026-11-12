<?php

/**
 * The publish guard of the ZGW publish endpoint
 *
 * Publishing a zaaktype through `POST /zaaktypen/{uuid}/publish` runs the same
 * CT-02b check as an update that sets `isDraft: false`.
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

use OCA\Dossiq\Service\Zgw\LookupNotCompleted;
use OCA\Dossiq\Service\Zgw\ZgwRuleResults;
use OCA\Dossiq\Service\Zgw\ZgwRulesDispatcher;
use OCA\Dossiq\Service\ZgwBusinessRulesService;
use OCA\Dossiq\Service\ZgwZtcRulesService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the publish endpoint's guard answers per stored type and per verdict of the publish check.
 *
 * @covers \OCA\Dossiq\Service\ZgwBusinessRulesService
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 * @uses \OCA\Dossiq\Service\Zgw\LookupNotCompleted
 *
 * @spec openspec/changes/zgw-publish-endpoint-guard/specs/zgw-business-rules-compliance/spec.md
 */
class ZgwBusinessRulesServicePublishEndpointTest extends TestCase {
	/**
	 * The ZTC rules, whose publish check the guard asks.
	 *
	 * @var ZgwZtcRulesService|MockObject
	 */
	private ZgwZtcRulesService $ztc;

	/**
	 * The service under test.
	 *
	 * @var ZgwBusinessRulesService
	 */
	private ZgwBusinessRulesService $service;

	/**
	 * Build the service with the ZTC rules mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->ztc = $this->createMock(ZgwZtcRulesService::class);
		$this->service = new ZgwBusinessRulesService(
			ztcRules: $this->ztc,
			dispatcher: $this->createMock(ZgwRulesDispatcher::class),
			results: new ZgwRuleResults(logger: $this->createMock(LoggerInterface::class))
		);
	}//end setUp()

	/**
	 * The guard's answer for publishing a stored type.
	 *
	 * @param string $resource The ZGW resource
	 * @param array $stored The stored type
	 *
	 * @return array|null
	 */
	private function publish(string $resource, array $stored): ?array {
		return $this->service->guardPublishEndpoint(
			resource: $resource,
			existingObject: $stored,
			objectService: new \stdClass(),
			mappingConfig: ['sourceRegister' => 'reg', 'sourceSchema' => 'zaaktype']
		);
	}//end publish()

	/**
	 * A draft zaaktype without a final status and a valid-from date is refused with 422, and the detail lists what is missing.
	 *
	 * @return void
	 */
	public function testAnIncompleteDraftZaaktypeIsRefused(): void {
		$this->ztc->expects($this->once())->method('setContext');
		$this->ztc->method('validatePublish')->with('reg', 'zt-1')->willReturn(
			['At least one status type must be marked as final', "'Valid from' date must be set before publishing"]
		);

		$this->assertSame(
			[
				'valid' => false,
				'status' => 422,
				'detail' => "At least one status type must be marked as final; 'Valid from' date must be set before publishing",
				'code' => 'publish_validation_failed',
				'enrichedBody' => ['isDraft' => false],
			],
			$this->publish(resource: 'zaaktypen', stored: ['id' => 'zt-1', 'isDraft' => true])
		);
	}//end testAnIncompleteDraftZaaktypeIsRefused()

	/**
	 * A complete draft zaaktype may be published.
	 *
	 * @return void
	 */
	public function testACompleteDraftZaaktypePasses(): void {
		$this->ztc->expects($this->once())->method('validatePublish')->with('reg', 'zt-1')->willReturn([]);

		$this->assertNull($this->publish(resource: 'zaaktypen', stored: ['id' => 'zt-1', 'isDraft' => true]));
	}//end testACompleteDraftZaaktypePasses()

	/**
	 * The check applies to a zaaktype that is a draft. A published zaaktype and the other catalogue types are not checked.
	 *
	 * @return void
	 */
	public function testOnlyADraftZaaktypeIsChecked(): void {
		$this->ztc->expects($this->never())->method('validatePublish');

		$this->assertNull($this->publish(resource: 'zaaktypen', stored: ['id' => 'zt-1', 'isDraft' => false]));
		$this->assertNull($this->publish(resource: 'besluittypen', stored: ['id' => 'bt-1', 'isDraft' => true]));
		$this->assertNull($this->publish(resource: 'informatieobjecttypen', stored: ['id' => 'iot-1', 'isDraft' => true]));
	}//end testOnlyADraftZaaktypeIsChecked()

	/**
	 * A publish check that could not read the store refuses with 503 `check-not-completed`.
	 *
	 * @return void
	 */
	public function testACheckThatCouldNotRunRefusesThePublish(): void {
		$this->ztc->method('validatePublish')->willThrowException(
			new LookupNotCompleted(lookup: 'zaaktype zt-1', cause: new \RuntimeException('store down'))
		);

		$refusal = $this->publish(resource: 'zaaktypen', stored: ['id' => 'zt-1', 'isDraft' => true]);

		$this->assertSame([503, 'check-not-completed'], [$refusal['status'], $refusal['code']]);
		$this->assertFalse($refusal['valid']);
	}//end testACheckThatCouldNotRunRefusesThePublish()
}//end class
