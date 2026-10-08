<?php

/**
 * A case template: a case row that is never worked.
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
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Starter\CaseTemplateService;
use OCA\Dossiq\Tests\Support\StarterStoreHarness;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for CaseTemplateService.
 *
 * @covers \OCA\Dossiq\Service\Starter\CaseTemplateService
 *
 * @uses \OCA\Dossiq\Service\Starter\StarterStore
 */
class CaseTemplateTest extends TestCase {

	/**
	 * The store and its rows.
	 *
	 * @var StarterStoreHarness
	 */
	private StarterStoreHarness $harness;

	/**
	 * The service under test.
	 *
	 * @var CaseTemplateService
	 */
	private CaseTemplateService $templates;

	/**
	 * Build the service over a real store, with one template and one real case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->harness = new StarterStoreHarness(test: $this);
		$this->templates = new CaseTemplateService($this->harness->store, new NullLogger());

		$this->harness->seed(
			schema: 'case',
			uuid: 'tpl-1',
			row: [
				'id' => 'tpl-1',
				'isTemplate' => true,
				'templateName' => 'Standaardzaak sloopmelding',
				'title' => 'Sloopmelding',
				'caseType' => 'ct-vth',
				'assignedGroup' => 'toezicht',
				'confidentiality' => 'intern',
				'identifier' => 'ZAAK-0001',
				'startDate' => '2026-01-01',
				'deadline' => '2026-02-12',
			],
		);

		$this->harness->seed(
			schema: 'case',
			uuid: 'case-1',
			row: ['id' => 'case-1', 'isTemplate' => false, 'title' => 'Echte zaak', 'caseType' => 'ct-vth'],
		);
	}//end setUp()

	/**
	 * A template presetting a case type, a group and two fields hands them to
	 * the new case, and the case records where it came from.
	 *
	 * @return void
	 */
	public function testACaseStartedFromATemplateCarriesItsValuesAndNamesIt(): void {
		$result = $this->templates->startFrom(templateId: 'tpl-1');

		self::assertTrue(condition: $result['ok']);
		self::assertSame(expected: 'ct-vth', actual: $result['case']['caseType']);
		self::assertSame(expected: 'toezicht', actual: $result['case']['assignedGroup']);
		self::assertSame(expected: 'intern', actual: $result['case']['confidentiality']);
		self::assertSame(expected: 'tpl-1', actual: $result['case']['startedFromTemplate']);
		self::assertFalse(condition: $result['case']['isTemplate']);
	}//end testACaseStartedFromATemplateCarriesItsValuesAndNamesIt()

	/**
	 * The template's own identity does not come along.
	 *
	 * 🔑 A ZAAKNUMMER BELONGS TO ONE CASE. Copying `identifier` would give two
	 * cases the same one, which is an archive problem rather than a cosmetic
	 * one, and the dates would make a case look like it started in January.
	 *
	 * @return void
	 */
	public function testTheTemplatesOwnIdentityDoesNotComeAlong(): void {
		$result = $this->templates->startFrom(templateId: 'tpl-1');

		self::assertArrayNotHasKey(key: 'identifier', array: $result['case']);
		self::assertArrayNotHasKey(key: 'startDate', array: $result['case']);
		self::assertArrayNotHasKey(key: 'deadline', array: $result['case']);
		self::assertArrayNotHasKey(key: 'templateName', array: $result['case']);
	}//end testTheTemplatesOwnIdentityDoesNotComeAlong()

	/**
	 * What the handler typed beats what the template presets.
	 *
	 * @return void
	 */
	public function testTheHandlersOwnValuesBeatThePresets(): void {
		$result = $this->templates->startFrom(
			templateId: 'tpl-1',
			overrides: ['title' => 'Sloopmelding Kerkstraat 4'],
		);

		self::assertSame(expected: 'Sloopmelding Kerkstraat 4', actual: $result['case']['title']);
	}//end testTheHandlersOwnValuesBeatThePresets()

	/**
	 * Starting from an ordinary case is refused, because that gesture is a copy
	 * and something else already answers it.
	 *
	 * @return void
	 */
	public function testStartingFromAnOrdinaryCaseIsRefused(): void {
		$result = $this->templates->startFrom(templateId: 'case-1');

		self::assertFalse(condition: $result['ok']);
		self::assertSame(expected: 'not_a_template', actual: $result['reason']);
	}//end testStartingFromAnOrdinaryCaseIsRefused()

	/**
	 * A template is not work: it is excluded by the same filter every working
	 * list, count and term report carries.
	 *
	 * @return void
	 */
	public function testATemplateIsNotWork(): void {
		self::assertSame(expected: ['isTemplate' => false], actual: CaseTemplateService::EXCLUSION);

		$template = $this->harness->register->row(schema: 'case', uuid: 'tpl-1');
		$real = $this->harness->register->row(schema: 'case', uuid: 'case-1');

		self::assertTrue(condition: $this->templates->isTemplate(case: $template));
		self::assertFalse(condition: $this->templates->isTemplate(case: $real));
	}//end testATemplateIsNotWork()

	/**
	 * No term is bound to a template.
	 *
	 * A template of a case type with a statutory term would otherwise start
	 * counting the day it was saved and sit overdue on somebody's report
	 * forever.
	 *
	 * @return void
	 */
	public function testATemplateDoesNotStartATerm(): void {
		$template = $this->harness->register->row(schema: 'case', uuid: 'tpl-1');
		$real = $this->harness->register->row(schema: 'case', uuid: 'case-1');

		self::assertFalse(condition: $this->templates->bindsTerm(case: $template));
		self::assertTrue(condition: $this->templates->bindsTerm(case: $real));
	}//end testATemplateDoesNotStartATerm()

	/**
	 * The offered templates are the templates, and only of the case type asked for.
	 *
	 * @return void
	 */
	public function testOnlyTemplatesAreOfferedAndTheyAreScopedToTheirCaseType(): void {
		$this->harness->seed(
			schema: 'case',
			uuid: 'tpl-2',
			row: ['id' => 'tpl-2', 'isTemplate' => true, 'templateName' => 'Bezwaar', 'caseType' => 'ct-bezwaar'],
		);

		$all = $this->templates->templates();
		$vth = $this->templates->templates(caseTypeId: 'ct-vth');

		self::assertNotNull(actual: $all);
		self::assertCount(expectedCount: 2, haystack: $all);
		self::assertNotNull(actual: $vth);
		self::assertCount(expectedCount: 1, haystack: $vth);
		self::assertSame(expected: 'Standaardzaak sloopmelding', actual: $vth[0]['templateName']);
	}//end testOnlyTemplatesAreOfferedAndTheyAreScopedToTheirCaseType()
}//end class
