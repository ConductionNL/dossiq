<?php

/**
 * Case type copy value objects unit tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
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
 * @spec openspec/changes/zaaktype-copy/tasks.md#T13
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\CaseTypeChildCopy;
use OCA\Dossiq\Service\CaseType\CaseTypeCopyTarget;
use OCA\Dossiq\Service\CaseType\CaseTypeDerivation;
use PHPUnit\Framework\TestCase;

/**
 * The values a case type copy passes between its steps.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeCopyTarget
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeDerivation
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeChildCopy
 */
class CaseTypeCopyValuesTest extends TestCase {

	/**
	 * A target on another schema keeps its object service and register.
	 *
	 * @return void
	 */
	public function testATargetOnAnotherSchemaKeepsItsServiceAndRegister(): void {
		$objects = new \stdClass();
		$target = (new CaseTypeCopyTarget(objectService: $objects, register: 'dossiq', schema: 'caseType'))->forSchema(schema: 'statusType');

		$this->assertSame($objects, $target->objectService);
		$this->assertSame('dossiq', $target->register);
		$this->assertSame('statusType', $target->schema);
	}//end testATargetOnAnotherSchemaKeepsItsServiceAndRegister()

	/**
	 * A child copy on another schema keeps its key and the new case type id, and leaves the original alone.
	 *
	 * @return void
	 */
	public function testAChildCopyOnAnotherSchemaKeepsItsKeyAndNewId(): void {
		$target = new CaseTypeCopyTarget(objectService: new \stdClass(), register: 'dossiq', schema: 'caseType');
		$copy = new CaseTypeChildCopy(target: $target, configKey: 'role_type_schema', newCaseTypeId: 'ct-2');
		$moved = $copy->forSchema(schema: 'roleType');

		$this->assertSame('roleType', $moved->target->schema);
		$this->assertSame('role_type_schema', $moved->configKey);
		$this->assertSame('ct-2', $moved->newCaseTypeId);
		$this->assertSame('caseType', $copy->target->schema);
	}//end testAChildCopyOnAnotherSchemaKeepsItsKeyAndNewId()

	/**
	 * A derivation holds its source and its kind.
	 *
	 * @return void
	 */
	public function testADerivationHoldsItsSourceAndKind(): void {
		$target = new CaseTypeCopyTarget(objectService: new \stdClass(), register: 'dossiq', schema: 'caseType');
		$derivation = new CaseTypeDerivation(target: $target, sourceId: 'ct-1', asVersion: true);

		$this->assertSame($target, $derivation->target);
		$this->assertSame('ct-1', $derivation->sourceId);
		$this->assertTrue($derivation->asVersion);
	}//end testADerivationHoldsItsSourceAndKind()
}//end class
