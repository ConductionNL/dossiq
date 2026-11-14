<?php

/**
 * Tests for AccessLinkRequest: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Sharing
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
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Sharing;

use OCA\Dossiq\Service\Sharing\AccessLinkRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AccessLinkRequest.
 *
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
 */
class AccessLinkRequestTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$subject = new AccessLinkRequest(
			userId: 'userId-value',
			subjectType: 'subjectType-value',
			subjectId: 'subjectId-value',
			capabilities: ['capabilities' => 1],
			expiresAt: 'expiresAt-value',
			password: 'password-value',
			label: 'label-value'
		);

		self::assertSame(expected: 'userId-value', actual: $subject->userId);
		self::assertSame(expected: 'subjectType-value', actual: $subject->subjectType);
		self::assertSame(expected: 'subjectId-value', actual: $subject->subjectId);
		self::assertSame(expected: ['capabilities' => 1], actual: $subject->capabilities);
		self::assertSame(expected: 'expiresAt-value', actual: $subject->expiresAt);
		self::assertSame(expected: 'password-value', actual: $subject->password);
		self::assertSame(expected: 'label-value', actual: $subject->label);
	}//end testPropertiesReadBack()
}//end class
