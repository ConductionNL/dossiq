<?php

/**
 * DrcLockRule unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
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

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\DrcLockRule;
use OCA\Dossiq\Service\Zgw\ZgwRuleResults;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Drc-006: a locked document is changed only with its own lock id.
 *
 * @covers \OCA\Dossiq\Service\Zgw\DrcLockRule
 * @uses   \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class DrcLockRuleTest extends TestCase {

	/**
	 * The rule over a real results builder.
	 *
	 * @return DrcLockRule
	 */
	private function rule(): DrcLockRule {
		return new DrcLockRule(results: new ZgwRuleResults(logger: $this->createMock(originalClassName: LoggerInterface::class)));
	}//end rule()

	/**
	 * No stored document, and one without a lock, need no lock id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAnUnlockedDocumentNeedsNoLock(): void {
		$this->assertNull(actual: $this->rule()->refusal(body: [], existingObject: null));
		$this->assertNull(actual: $this->rule()->refusal(body: [], existingObject: ['locked' => false]));
		$this->assertNull(actual: $this->rule()->refusal(body: ['lock' => 'x'], existingObject: ['lock' => '']));
	}//end testAnUnlockedDocumentNeedsNoLock()

	/**
	 * A missing lock id and another lock id are refused on the lock field; the right one passes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testALockedDocumentNeedsItsOwnLockId(): void {
		$missing = $this->rule()->refusal(body: [], existingObject: ['locked' => 'lock-1']);
		$this->assertSame(expected: 400, actual: $missing['status']);
		$this->assertSame(expected: 'Het document is vergrendeld. Geef een geldig lock ID mee.', actual: $missing['detail']);
		$this->assertSame(
			expected: [['name' => 'lock', 'code' => 'required', 'reason' => 'Het document is vergrendeld.']],
			actual: $missing['invalidParams']
		);

		$wrong = $this->rule()->refusal(body: ['lock' => 'lock-2'], existingObject: ['lock' => 'lock-1']);
		$this->assertSame(expected: 'Het opgegeven lock ID is niet geldig.', actual: $wrong['detail']);
		$this->assertSame(
			expected: [['name' => 'lock', 'code' => 'incorrect-lock-id', 'reason' => 'Het opgegeven lock ID komt niet overeen.']],
			actual: $wrong['invalidParams']
		);

		$this->assertNull(actual: $this->rule()->refusal(body: ['lock' => 'lock-1'], existingObject: ['locked' => 'lock-1']));
	}//end testALockedDocumentNeedsItsOwnLockId()
}//end class
