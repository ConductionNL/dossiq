<?php

/**
 * A stand-in for opencatalogi's withheld record door, for the unit tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use RuntimeException;

/**
 * A stand-in for opencatalogi's record door with its exact signature
 * (opencatalogi lib/Service/Woo/WithheldDocuments.php:122).
 */
class FakeWithheldDocuments {

	/**
	 * The calls made.
	 *
	 * @var list<array{0: string, 1: array, 2: string}>
	 */
	public array $calls = [];

	/**
	 * The answer to give, or null to throw.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $answer = ['recorded' => 0, 'refused' => []];

	/**
	 * Record a publication's withheld list.
	 *
	 * @param string $publicationId The publication.
	 * @param array  $entries       The entries.
	 * @param string $source        The source.
	 *
	 * @return array<string, mixed>
	 */
	public function record(string $publicationId, array $entries, string $source): array {
		$this->calls[] = [$publicationId, $entries, $source];
		if ($this->answer === null) {
			throw new RuntimeException('opencatalogi exploded');
		}

		return $this->answer;
	}//end record()
}//end class
