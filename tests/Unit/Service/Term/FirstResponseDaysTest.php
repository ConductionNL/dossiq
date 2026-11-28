<?php

/**
 * Unit tests for FirstResponseDays.
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use OCA\Dossiq\Service\Term\FirstResponseDays;
use OCA\Dossiq\Service\Term\TermResolution;
use OCA\Dossiq\Service\TermKind;
use PHPUnit\Framework\TestCase;

/**
 * A declared first-response term wins; nothing declared is the fallback.
 *
 * @covers \OCA\Dossiq\Service\Term\FirstResponseDays
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class FirstResponseDaysTest extends TestCase {

	/**
	 * The case type's declared days win over the fallback, read as the first-response kind.
	 *
	 * @return void
	 */
	public function testADeclaredTermWins(): void {
		$resolution = $this->createMock(TermResolution::class);
		$resolution->expects($this->once())
			->method('resolve')
			->with('klacht', [], TermKind::FIRST_RESPONSE)
			->willReturn(['definition' => ['standardDurationDays' => 3]]);

		self::assertSame(3, (new FirstResponseDays(resolution: $resolution))->days(caseType: 'klacht', fallback: 5));
	}//end testADeclaredTermWins()

	/**
	 * No resolution, no declaration or a zero term all answer the fallback.
	 *
	 * @return void
	 */
	public function testNothingDeclaredIsTheFallback(): void {
		self::assertSame(5, (new FirstResponseDays())->days(caseType: 'klacht', fallback: 5));

		$none = $this->createMock(TermResolution::class);
		$none->method('resolve')->willReturn(null);
		self::assertSame(5, (new FirstResponseDays(resolution: $none))->days(caseType: 'klacht', fallback: 5));

		$zero = $this->createMock(TermResolution::class);
		$zero->method('resolve')->willReturn(['definition' => ['standardDurationDays' => 0]]);
		self::assertSame(5, (new FirstResponseDays(resolution: $zero))->days(caseType: 'klacht', fallback: 5));
	}//end testNothingDeclaredIsTheFallback()
}//end class
