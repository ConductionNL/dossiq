<?php

/**
 * A term carried over from one case to the next.
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

use OCA\Dossiq\Service\Term\CarriedTerm;
use PHPUnit\Framework\TestCase;

/**
 * The status a carried term takes, and the copy with a definition.
 *
 * @covers \OCA\Dossiq\Service\Term\CarriedTerm
 */
class CarriedTermTest extends TestCase {

	private function term(int $extensions = 0, bool $suspended = false): CarriedTerm {
		return new CarriedTerm(deadline: '2026-12-01', extensions: $extensions, suspended: $suspended, reason: 'doorzending', days: 42);
	}

	public function testASuspendedTermIsPausedEvenWhenExtended(): void {
		$this->assertSame('paused', $this->term(suspended: true)->status());
		$this->assertSame('paused', $this->term(extensions: 2, suspended: true)->status());
	}

	public function testAnExtendedTermIsVerlengdAndOtherwiseLopend(): void {
		$this->assertSame('verlengd', $this->term(extensions: 1)->status());
		$this->assertSame('lopend', $this->term()->status());
	}

	public function testWithDefinitieCopiesEverythingElse(): void {
		$term   = $this->term(extensions: 1, suspended: true);
		$copied = $term->withDefinitie(['name' => 'Beslistermijn', 'days' => 56]);

		$this->assertNotSame($term, $copied);
		$this->assertSame([], $term->definitie);
		$this->assertSame(['name' => 'Beslistermijn', 'days' => 56], $copied->definitie);
		$this->assertSame(
			['2026-12-01', 1, true, 'doorzending', 42],
			[$copied->deadline, $copied->extensions, $copied->suspended, $copied->reason, $copied->days]
		);
	}
}
