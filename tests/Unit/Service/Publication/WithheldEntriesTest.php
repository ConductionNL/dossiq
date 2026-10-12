<?php

/**
 * Unit tests for WithheldEntries.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-decision-records-what-was-withheld/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-published-woo-decision-records-its-withheld-documents-in-opencatalogi-req-wrw-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use OCA\Dossiq\Service\Publication\WithheldEntries;
use PHPUnit\Framework\TestCase;

/**
 * The entries a publication's withheld list is recorded with.
 */
class WithheldEntriesTest extends TestCase {

	/**
	 * Five assessed documents, numbers 3 and 7 in inventory order withheld.
	 *
	 * Inventory order is documentRef order, so the refs are given out of
	 * order on purpose: the position must not be the order they arrive in.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function assessments(): array {
		$row = static fn (string $ref, string $classification, array $grounds = []): array => [
			'id' => 'assessment-' . $ref,
			'documentRef' => $ref,
			'classification' => $classification,
			'weigeringsgronden' => $grounds,
			'redactedDocumentRef' => 'redacted-' . $ref,
			'fileId' => 4711,
			'sha256' => str_repeat('a', 64),
			'title' => 'Advies over de locatiekeuze ' . $ref,
		];

		return [
			$row('doc-g', 'niet_openbaar', ['5.1.2.e']),
			$row('doc-a', 'openbaar'),
			$row('doc-c', 'niet_openbaar', ['5.1.2.e']),
			$row('doc-b', 'deels_openbaar', ['5.1.2.e']),
			$row('doc-d', 'openbaar'),
			$row('doc-e', 'openbaar'),
			$row('doc-f', 'openbaar'),
		];
	}//end assessments()

	/**
	 * Build the entries the Woo case type configures: `niet_openbaar` with `weigeringsgronden`.
	 *
	 * @param array<int, array<string, mixed>> $assessments The assessments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function build(array $assessments): array {
		return (new WithheldEntries())->fromAssessments(
			assessments: $assessments,
			withheldValue: 'niet_openbaar',
			groundsField: 'weigeringsgronden',
		);
	}//end build()

	/**
	 * Only the documents withheld in full are listed.
	 *
	 * @return void
	 */
	public function testOnlyWithheldDocumentsAreListed(): void {
		$entries = $this->build($this->assessments());

		self::assertCount(2, $entries);
	}//end testOnlyWithheldDocumentsAreListed()

	/**
	 * The position is the document's number in the inventory: documentRef order, from 1.
	 *
	 * @return void
	 */
	public function testPositionsMatchTheInventoryNumbers(): void {
		$entries = $this->build($this->assessments());

		self::assertSame(
			[
				['position' => 3, 'grounds' => ['5.1.2.e']],
				['position' => 7, 'grounds' => ['5.1.2.e']],
			],
			$entries
		);
	}//end testPositionsMatchTheInventoryNumbers()

	/**
	 * Nothing but the position and the grounds leaves dossiq.
	 *
	 * @return void
	 */
	public function testNoKeyButPositionAndGrounds(): void {
		foreach ($this->build($this->assessments()) as $entry) {
			self::assertSame(['position', 'grounds'], array_keys($entry));
		}
	}//end testNoKeyButPositionAndGrounds()

	/**
	 * A title never travels, not even inside the grounds.
	 *
	 * @return void
	 */
	public function testNoTitleIsEverSent(): void {
		$encoded = json_encode($this->build($this->assessments()));

		self::assertStringNotContainsString('Advies', (string)$encoded);
		self::assertStringNotContainsString('doc-', (string)$encoded);
		self::assertStringNotContainsString('4711', (string)$encoded);
	}//end testNoTitleIsEverSent()

	/**
	 * A ground list stored as a string or with blanks is reduced to its codes.
	 *
	 * @return void
	 */
	public function testGroundsAreReducedToNonEmptyCodes(): void {
		$entries = $this->build(
			[
				['documentRef' => 'a', 'classification' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.1.c', '', 7]],
				['documentRef' => 'b', 'classification' => 'niet_openbaar', 'weigeringsgronden' => 'not-a-list'],
			]
		);

		self::assertSame(
			[
				['position' => 1, 'grounds' => ['5.1.1.c']],
				['position' => 2, 'grounds' => []],
			],
			$entries
		);
	}//end testGroundsAreReducedToNonEmptyCodes()
}//end class
