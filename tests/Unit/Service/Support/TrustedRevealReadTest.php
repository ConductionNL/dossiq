<?php

/**
 * dossiq's server reads that need the BSN run trusted in OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/bsn-reveal-logged/specs/party-pages/spec.md#requirement-the-bsn-is-shown-through-a-logged-server-reveal
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\TrustedRevealRead;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Support\TrustedRevealRead
 */
class TrustedRevealReadTest extends TestCase {

	/**
	 * The read runs inside OpenRegister's trust.
	 *
	 * @return void
	 */
	public function testTheReadRunsTrusted(): void {
		$reveal = new class {
			public bool $trusted = false;

			/**
			 * @param callable $operation The read.
			 *
			 * @return mixed
			 */
			public function trustedDuring(callable $operation): mixed {
				$this->trusted = true;
				$result = $operation();
				$this->trusted = false;
				return $result;
			}
		};

		$seen = (new TrustedRevealRead(reveal: $reveal))->run(fn () => $reveal->trusted);

		$this->assertTrue($seen);
	}//end testTheReadRunsTrusted()

	/**
	 * Without explicit reveal in OpenRegister the read simply runs.
	 *
	 * @return void
	 */
	public function testWithoutExplicitRevealTheReadRuns(): void {
		$this->assertSame('rows', (new TrustedRevealRead())->run(fn () => 'rows'));
	}//end testWithoutExplicitRevealTheReadRuns()
}//end class
