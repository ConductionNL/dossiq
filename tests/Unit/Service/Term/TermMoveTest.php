<?php

/**
 * A term moved by a dependency on another case.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Term
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

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use OCA\Dossiq\Service\Term\TermMove;
use PHPUnit\Framework\TestCase;

/**
 * How the case that moved the term is named.
 *
 * @covers \OCA\Dossiq\Service\Term\TermMove
 */
class TermMoveTest extends TestCase {

	public function testTheSourceIsNamedByItsTitle(): void {
		$this->assertSame('Omgevingsvergunning Dorpsstraat 1', (new TermMove('c-9', 'Omgevingsvergunning Dorpsstraat 1', 14))->named());
	}

	public function testAnUntitledSourceIsNamedByItsId(): void {
		$move = new TermMove('c-9', '', -3);

		$this->assertSame('c-9', $move->named());
		$this->assertSame(-3, $move->daysImpact);
	}
}
