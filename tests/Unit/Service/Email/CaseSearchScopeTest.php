<?php

/**
 * Tests for CaseSearchScope: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
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
 * @spec openspec/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\CaseSearchScope;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for CaseSearchScope.
 *
 * @spec openspec/specs/email-case-matching/spec.md
 */
class CaseSearchScopeTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$objectService = new stdClass();
		$owner = $this->createMock(originalClassName: IUser::class);
		$subject = new CaseSearchScope(
			objectService: $objectService,
			register: 'register-value',
			schema: 'schema-value',
			owner: $owner
		);

		self::assertSame(expected: $objectService, actual: $subject->objectService);
		self::assertSame(expected: 'register-value', actual: $subject->register);
		self::assertSame(expected: 'schema-value', actual: $subject->schema);
		self::assertSame(expected: $owner, actual: $subject->owner);
	}//end testPropertiesReadBack()
}//end class
