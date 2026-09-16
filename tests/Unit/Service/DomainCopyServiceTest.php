<?php

/**
 * Standing a whole domain up from another one, and saying what did not come.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseTypeCopyService;
use OCA\Dossiq\Service\Starter\DomainCopyService;
use OCA\Dossiq\Tests\Support\StarterStoreHarness;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for DomainCopyService.
 *
 * @covers \OCA\Dossiq\Service\Starter\DomainCopyService
 *
 * @uses \OCA\Dossiq\Service\Starter\StarterStore
 */
class DomainCopyServiceTest extends TestCase {

	/**
	 * The store and its rows.
	 *
	 * @var StarterStoreHarness
	 */
	private StarterStoreHarness $harness;

	/**
	 * The case type copy this service delegates to.
	 *
	 * @var CaseTypeCopyService|MockObject
	 */
	private CaseTypeCopyService $copier;

	/**
	 * One domain with two case types and a template scoped to one of them.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->harness = new StarterStoreHarness(test: $this);

		$this->copier = $this->getMockBuilder(CaseTypeCopyService::class)
			->disableOriginalConstructor()
			->onlyMethods(['copy'])
			->getMock();

		$this->harness->seed(
			schema: 'caseTypeGroup',
			uuid: 'dom-vth',
			row: ['id' => 'dom-vth', 'groupName' => 'VTH', 'partner' => 'ODRN', 'description' => 'Vergunning, toezicht, handhaving'],
		);
		$this->harness->seed(
			schema: 'caseType',
			uuid: 'ct-1',
			row: ['id' => 'ct-1', 'title' => 'Omgevingsvergunning', 'caseTypeGroup' => 'dom-vth'],
		);
		$this->harness->seed(
			schema: 'caseType',
			uuid: 'ct-2',
			row: ['id' => 'ct-2', 'title' => 'Handhavingszaak', 'caseTypeGroup' => 'dom-vth'],
		);
		$this->harness->seed(
			schema: 'contentTemplate',
			uuid: 'tpl-1',
			row: ['id' => 'tpl-1', 'kind' => 'task', 'name' => 'Vraag advies', 'caseTypes' => ['ct-1']],
		);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return DomainCopyService The service.
	 */
	private function service(): DomainCopyService {
		return new DomainCopyService($this->harness->store, $this->copier, new NullLogger());
	}//end service()

	/**
	 * A whole domain is stood up from another, carrying its case types and the
	 * templates scoped to them.
	 *
	 * @return void
	 */
	public function testAWholeDomainIsStoodUpFromAnother(): void {
		$this->copier->method('copy')->willReturnCallback(
			function (string $caseTypeId): array {
				$source = $this->harness->register->row(schema: 'caseType', uuid: $caseTypeId);
				$copy = ($source + []);
				$copy['id'] = ($caseTypeId . '-copy');
				$copy['isDraft'] = true;
				$this->harness->seed(schema: 'caseType', uuid: $copy['id'], row: $copy);

				return $copy;
			}
		);

		$result = $this->service()->copy(domainId: 'dom-vth', name: 'VTH Bommelerwaard');

		self::assertTrue(condition: $result['complete']);
		self::assertSame(expected: [], actual: $result['notCarried']);
		self::assertSame(expected: 2, actual: $result['carried']['caseType']);
		self::assertSame(expected: 1, actual: $result['carried']['contentTemplate']);

		$created = $this->harness->register->row(schema: 'caseTypeGroup', uuid: $result['domain']);

		self::assertSame(expected: 'VTH Bommelerwaard', actual: $created['groupName']);
		self::assertSame(expected: 'ODRN', actual: $created['partner']);
		self::assertCount(expectedCount: 2, haystack: $created['caseTypes']);
	}//end testAWholeDomainIsStoodUpFromAnother()

	/**
	 * A copied template points at the copied case types, not the originals.
	 *
	 * A template repointed at the source's case types would be offered on the
	 * wrong domain's cases, and nothing would say so.
	 *
	 * @return void
	 */
	public function testACopiedTemplatePointsAtTheCopiedCaseTypes(): void {
		$this->copier->method('copy')->willReturnCallback(
			function (string $caseTypeId): array {
				$copy = ($this->harness->register->row(schema: 'caseType', uuid: $caseTypeId) + []);
				$copy['id'] = ($caseTypeId . '-copy');
				$this->harness->seed(schema: 'caseType', uuid: $copy['id'], row: $copy);

				return $copy;
			}
		);

		$this->service()->copy(domainId: 'dom-vth', name: 'VTH Bommelerwaard');

		$templates = $this->harness->register->all(schema: 'contentTemplate');
		$copied = array_values(array_filter($templates, static fn (array $row): bool => $row['id'] !== 'tpl-1'));

		self::assertCount(expectedCount: 1, haystack: $copied);
		self::assertSame(expected: ['ct-1-copy'], actual: $copied[0]['caseTypes']);
	}//end testACopiedTemplatePointsAtTheCopiedCaseTypes()

	/**
	 * A copy that dropped a case type says so and does not report success.
	 *
	 * 🔑 THE SILENT PARTIAL IS THE FAILURE THIS CLASS EXISTS TO PREVENT. An
	 * administrator seeing a green tick finds the missing case type three weeks
	 * later, on the day somebody needs it.
	 *
	 * @return void
	 */
	public function testACopyThatDroppedSomethingSaysSo(): void {
		$this->copier->method('copy')->willReturnCallback(
			function (string $caseTypeId): ?array {
				if ($caseTypeId === 'ct-2') {
					return null;
				}

				$copy = ($this->harness->register->row(schema: 'caseType', uuid: $caseTypeId) + []);
				$copy['id'] = ($caseTypeId . '-copy');
				$this->harness->seed(schema: 'caseType', uuid: $copy['id'], row: $copy);

				return $copy;
			}
		);

		$result = $this->service()->copy(domainId: 'dom-vth', name: 'VTH Bommelerwaard');

		self::assertFalse(condition: $result['complete']);
		self::assertSame(expected: ['caseType:Handhavingszaak'], actual: $result['notCarried']);
		self::assertSame(expected: 1, actual: $result['carried']['caseType']);
	}//end testACopyThatDroppedSomethingSaysSo()

	/**
	 * A template store nobody configured is reported as not carried, never as
	 * "there were none".
	 *
	 * @return void
	 */
	public function testAnUnconfiguredTemplateStoreIsReportedNotCarried(): void {
		$blind = new StarterStoreHarness(test: $this, unconfigured: ['content_template_schema']);
		$blind->register->rows = $this->harness->register->rows;

		$this->copier->method('copy')->willReturnCallback(
			function (string $caseTypeId) use ($blind): array {
				$copy = ($blind->register->row(schema: 'caseType', uuid: $caseTypeId) + []);
				$copy['id'] = ($caseTypeId . '-copy');
				$blind->register->seed(schema: 'caseType', uuid: $copy['id'], row: $copy);

				return $copy;
			}
		);

		$service = new DomainCopyService($blind->store, $this->copier, new NullLogger());
		$result = $service->copy(domainId: 'dom-vth', name: 'VTH Bommelerwaard');

		self::assertFalse(condition: $result['complete']);
		self::assertContains(needle: 'contentTemplate', haystack: $result['notCarried']);
	}//end testAnUnconfiguredTemplateStoreIsReportedNotCarried()

	/**
	 * A domain that is not there is a 404, not an empty copy.
	 *
	 * @return void
	 */
	public function testCopyingADomainThatIsNotThereIsRefused(): void {
		$result = $this->service()->copy(domainId: 'dom-nowhere', name: 'Nieuw');

		self::assertFalse(condition: $result['complete']);
		self::assertSame(expected: 'not_found', actual: $result['reason']);
		self::assertSame(expected: '', actual: $result['domain']);
	}//end testCopyingADomainThatIsNotThereIsRefused()
}//end class
