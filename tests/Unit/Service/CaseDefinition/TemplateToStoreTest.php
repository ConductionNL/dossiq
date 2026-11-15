<?php

/**
 * Unit test for TemplateToStore.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseDefinition
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\TemplateToStore;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\CaseDefinition\TemplateToStore
 */
class TemplateToStoreTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$name = 'name-value';
		$template = ['template' => 1];
		$packageId = 'packageId-value';
		$conflict = true;

		$value = new TemplateToStore(
			name: $name,
			template: $template,
			packageId: $packageId,
			conflict: $conflict,
		);

		$this->assertSame(expected: $name, actual: $value->name);
		$this->assertSame(expected: $template, actual: $value->template);
		$this->assertSame(expected: $packageId, actual: $value->packageId);
		$this->assertSame(expected: $conflict, actual: $value->conflict);
	}//end testEveryPropertyReadsBack()
}//end class
