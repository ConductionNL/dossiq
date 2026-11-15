<?php

/**
 * LinkCommentCursor carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Consultation;

use OCA\Dossiq\Service\Consultation\LinkCommentCursor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Consultation\LinkCommentCursor
 */
class LinkCommentCursorTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$value = new LinkCommentCursor(actorId: 'link:abc', after: 7);

		self::assertSame(expected: 'link:abc', actual: $value->actorId);
		self::assertSame(expected: 7, actual: $value->after);
	}//end testEveryPropertyReadsBack()
}//end class
