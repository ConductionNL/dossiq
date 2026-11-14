<?php

/**
 * Tests for DocumentLinkStore: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\DocumentLinkStore;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for DocumentLinkStore.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class DocumentLinkStoreTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$objectService = new stdClass();
		$subject = new DocumentLinkStore(
			objectService: $objectService,
			register: 'register-value',
			schema: 'schema-value'
		);

		self::assertSame(expected: $objectService, actual: $subject->objectService);
		self::assertSame(expected: 'register-value', actual: $subject->register);
		self::assertSame(expected: 'schema-value', actual: $subject->schema);
	}//end testPropertiesReadBack()
}//end class
