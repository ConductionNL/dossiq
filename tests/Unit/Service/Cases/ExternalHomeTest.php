<?php

/**
 * Reading a case's external-home declaration.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;


use OCA\Dossiq\Service\Cases\ExternalHome;
use PHPUnit\Framework\TestCase;

/**
 * The application is the test; the sentence names where the work is.
 *
 * @covers \OCA\Dossiq\Service\Cases\ExternalHome
 *
 * @spec openspec/specs/case-management/spec.md
 */
class ExternalHomeTest extends TestCase {

	/**
	 * A case naming an application is homed elsewhere, with its declaration trimmed.
	 *
	 * @return void
	 */
	public function testACaseNamingAnApplicationIsHomedElsewhere(): void {
		$case = [
			ExternalHome::APPLICATION => '  Key2Burgerzaken ',
			ExternalHome::IDENTIFIER => ' BZ-2026-17 ',
			ExternalHome::URL => ' https://bz.example/zaak/17 ',
		];
		$home = new ExternalHome();

		self::assertTrue($home->isHomedElsewhere($case));
		self::assertSame(
			['application' => 'Key2Burgerzaken', 'identifier' => 'BZ-2026-17', 'url' => 'https://bz.example/zaak/17'],
			$home->declarationOn($case)
		);
		self::assertSame('This case is handled in Key2Burgerzaken, as BZ-2026-17.', $home->whereTheWorkIs($case));
	}//end testACaseNamingAnApplicationIsHomedElsewhere()

	/**
	 * Without an identifier the sentence names only the application, and the declaration keeps empty fields.
	 *
	 * @return void
	 */
	public function testWithoutAnIdentifierTheSentenceNamesOnlyTheApplication(): void {
		$case = [ExternalHome::APPLICATION => 'Suite4Sociaal'];
		$home = new ExternalHome();

		self::assertSame(['application' => 'Suite4Sociaal', 'identifier' => '', 'url' => ''], $home->declarationOn($case));
		self::assertSame('This case is handled in Suite4Sociaal.', $home->whereTheWorkIs($case));
	}//end testWithoutAnIdentifierTheSentenceNamesOnlyTheApplication()

	/**
	 * Cases that only carry a stray reference, or nothing at all.
	 *
	 * @return array<string, array{0: array<string, mixed>}> The cases.
	 */
	public static function handledHereProvider(): array {
		return [
			'nothing' => [[]],
			'blank application' => [[ExternalHome::APPLICATION => '   ']],
			'identifier only' => [[ExternalHome::IDENTIFIER => 'BZ-1']],
			'url only' => [[ExternalHome::URL => 'https://bz.example/zaak/1']],
			'null application' => [[ExternalHome::APPLICATION => null, ExternalHome::IDENTIFIER => 'BZ-1']],
		];
	}//end handledHereProvider()

	/**
	 * Without an application the case is handled here: no declaration, no sentence.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @dataProvider handledHereProvider
	 *
	 * @return void
	 */
	public function testWithoutAnApplicationTheCaseIsHandledHere(array $case): void {
		$home = new ExternalHome();

		self::assertFalse($home->isHomedElsewhere($case));
		self::assertSame([], $home->declarationOn($case));
		self::assertSame('', $home->whereTheWorkIs($case));
	}//end testWithoutAnApplicationTheCaseIsHandledHere()
}//end class
