<?php

/**
 * Unit tests for LookupNotCompleted.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\LookupNotCompleted;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A lookup that could not complete names the lookup and carries its cause.
 *
 * @covers \OCA\Dossiq\Service\Zgw\LookupNotCompleted
 */
final class LookupNotCompletedTest extends TestCase {
	/**
	 * The cause's message becomes the message and the cause stays reachable as the previous exception.
	 *
	 * @return void
	 */
	public function testTheCauseIsKeptAndItsMessageReused(): void {
		$cause = new \LogicException('store unavailable', 42);

		$failure = new LookupNotCompleted(lookup: 'case_schema object abc', cause: $cause);

		$this->assertInstanceOf(RuntimeException::class, $failure);
		$this->assertSame('case_schema object abc', $failure->lookup);
		$this->assertSame('store unavailable', $failure->getMessage());
		$this->assertSame($cause, $failure->getPrevious());
		$this->assertSame(0, $failure->getCode(), 'the cause\'s code is not copied');
	}//end testTheCauseIsKeptAndItsMessageReused()
}//end class
