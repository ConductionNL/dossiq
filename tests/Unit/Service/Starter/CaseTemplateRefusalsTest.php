<?php

/**
 * Case templates when the store cannot answer.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Starter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Starter;

use OCA\Dossiq\Service\Starter\CaseTemplateService;
use OCA\Dossiq\Service\Starter\StarterStore;
use OCA\Dossiq\Tests\Support\StarterStoreHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for CaseTemplateService's refusals.
 */
#[CoversClass(CaseTemplateService::class)]
#[UsesClass(StarterStore::class)]
final class CaseTemplateRefusalsTest extends TestCase {

	/**
	 * An unconfigured case schema lists no templates: null, not an empty list.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredCaseSchemaListsNull(): void {
		$harness = new StarterStoreHarness(test: $this, unconfigured: [CaseTemplateService::CASES]);

		self::assertNull(actual: (new CaseTemplateService($harness->store, new NullLogger()))->templates(caseTypeId: 'ct-1'));
	}//end testAnUnconfiguredCaseSchemaListsNull()

	/**
	 * Templates are filtered to the case type and shaped with a name falling back to the title.
	 *
	 * @return void
	 */
	public function testTemplatesAreFilteredByCaseType(): void {
		$harness = new StarterStoreHarness(test: $this);
		$harness->seed(schema: 'case', uuid: 'c-1', row: ['id' => 'c-1', 'isTemplate' => true, 'caseType' => 'ct-1', 'title' => 'Basis']);
		$harness->seed(schema: 'case', uuid: 'c-2', row: ['id' => 'c-2', 'isTemplate' => true, 'caseType' => 'ct-2', 'templateName' => 'Ander']);
		$harness->seed(schema: 'case', uuid: 'c-3', row: ['id' => 'c-3', 'isTemplate' => false, 'caseType' => 'ct-1']);

		self::assertSame(
			expected: [['id' => 'c-1', 'templateName' => 'Basis', 'caseType' => 'ct-1']],
			actual: (new CaseTemplateService($harness->store, new NullLogger()))->templates(caseTypeId: 'ct-1')
		);
	}//end testTemplatesAreFilteredByCaseType()

	/**
	 * Starting from a template that is not there is refused as not_found, writing nothing.
	 *
	 * @return void
	 */
	public function testStartingFromAnUnknownTemplateIsNotFound(): void {
		$harness = new StarterStoreHarness(test: $this);

		self::assertSame(
			expected: ['ok' => false, 'reason' => 'not_found', 'case' => []],
			actual: (new CaseTemplateService($harness->store, new NullLogger()))->startFrom(templateId: 'c-missing')
		);
		self::assertSame(expected: 0, actual: $harness->register->writes);
	}//end testStartingFromAnUnknownTemplateIsNotFound()
}//end class
