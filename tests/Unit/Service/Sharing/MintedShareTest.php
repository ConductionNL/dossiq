<?php

/**
 * MintedShare carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Sharing;

use OCA\Dossiq\Service\Sharing\MintedShare;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Sharing\MintedShare
 */
class MintedShareTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$caseId = 'value-1';
		$label = 'value-2';
		$createdBy = 'value-3';
		$link = ['key' => 4];
		$capabilities = ['key' => 5];
		$documents = ['key' => 6];
		$extra = ['key' => 7];

		$value = new MintedShare(
			caseId: $caseId,
			label: $label,
			createdBy: $createdBy,
			link: $link,
			capabilities: $capabilities,
			documents: $documents,
			extra: $extra,
		);

		self::assertSame(expected: $caseId, actual: $value->caseId);
		self::assertSame(expected: $label, actual: $value->label);
		self::assertSame(expected: $createdBy, actual: $value->createdBy);
		self::assertSame(expected: $link, actual: $value->link);
		self::assertSame(expected: $capabilities, actual: $value->capabilities);
		self::assertSame(expected: $documents, actual: $value->documents);
		self::assertSame(expected: $extra, actual: $value->extra);
	}//end testEveryPropertyReadsBack()
}//end class
