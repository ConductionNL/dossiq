<?php

/**
 * ChecksIdentificatieUnique — the identificatie uniqueness check of a ZGW body.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\ChecksIdentificatieUnique;
use PHPUnit\Framework\TestCase;

/**
 * No identificatie asks nothing; one is checked with its bronorganisatie.
 *
 * @covers \OCA\Dossiq\Service\Support\ChecksIdentificatieUnique
 */
final class ChecksIdentificatieUniqueTest extends TestCase {

	/**
	 * A class using the trait that records the uniqueness question.
	 *
	 * @return object
	 */
	private function subject(): object {
		return new class() {
			use ChecksIdentificatieUnique {
				checkIdentificatieUnique as public;
			}

			/**
			 * The questions asked.
			 *
			 * @var array<int, array<string, string>>
			 */
			public array $asked = [];

			/**
			 * The recorded uniqueness check.
			 *
			 * @param string $field1Value  The first value.
			 * @param string $field1Search The first search field.
			 * @param string $field2Value  The second value.
			 * @param string $field2Search The second search field.
			 * @param string $errorField   The field an error names.
			 *
			 * @return array<string, mixed>|null
			 */
			private function checkFieldUniqueness(string $field1Value, string $field1Search, string $field2Value, string $field2Search, string $errorField): ?array {
				$this->asked[] = [$field1Value, $field1Search, $field2Value, $field2Search, $errorField];
				return ['status' => 400];
			}
		};
	}//end subject()

	/**
	 * The check is asked with the identificatie and bronorganisatie.
	 *
	 * @return void
	 */
	public function testAsksWithBothFields(): void {
		$subject = $this->subject();

		self::assertNull($subject->checkIdentificatieUnique([]));
		self::assertSame([], $subject->asked);
		self::assertSame(['status' => 400], $subject->checkIdentificatieUnique(['identificatie' => 'Z1', 'bronorganisatie' => '123']));
		self::assertSame([['Z1', 'identifier', '123', 'sourceOrganisation', 'identificatie']], $subject->asked);
		$subject->checkIdentificatieUnique(['identificatie' => 'Z2']);
		self::assertSame(['Z2', 'identifier', '', 'sourceOrganisation', 'identificatie'], $subject->asked[1]);
	}//end testAsksWithBothFields()
}//end class
