<?php

/**
 * RecordImportRequest carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Import;

use OCA\Dossiq\Service\Import\RecordImportRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Import\RecordImportRequest
 */
class RecordImportRequestTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$value = new RecordImportRequest(
			caseType: 'woo-verzoek',
			importKey: 'opencatalogi',
			dryRun: true
		);

		self::assertSame(expected: 'woo-verzoek', actual: $value->caseType);
		self::assertSame(expected: 'opencatalogi', actual: $value->importKey);
		self::assertSame(expected: true, actual: $value->dryRun);
	}//end testEveryPropertyReadsBack()
}//end class
