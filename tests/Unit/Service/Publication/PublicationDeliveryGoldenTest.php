<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use OCA\Dossiq\Service\Publication\PublicationSources;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Every outcome of `collectDelivery`: what goes out, what never does, and what each item records.
 */
class PublicationDeliveryGoldenTest extends TestCase {

	/**
	 * The documents the loader was asked for, in order.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * Run collectDelivery over the assessments with a loader that knows `doc-*` refs.
	 *
	 * @param array<int, array<string, mixed>> $assessments The assessments.
	 *
	 * @return array{documents: array<int, array<string, mixed>>, items: array<int, array<string, mixed>>} The delivery.
	 */
	private function deliver(array $assessments): array {
		$sources = new PublicationSources(settingsService: $this->createMock(SettingsService::class));

		return $sources->collectDelivery(
			assessments: $assessments,
			documentLoader: function (string $ref): ?array {
				$this->asked[] = $ref;
				if (str_starts_with($ref, 'doc-') === false) {
					return null;
				}

				return ['id' => $ref, 'title' => 'T ' . $ref, 'content' => 'C ' . $ref];
			},
		);
	}//end deliver()

	/**
	 * Allowed: openbaar as itself, deels_openbaar as its redaction, with the item recording both.
	 *
	 * @return void
	 */
	public function testWhatGoesOut(): void {
		$delivery = $this->deliver(
			[
				['id' => 'a-1', 'classification' => 'openbaar', 'documentRef' => 'doc-1'],
				['uuid' => 'a-2', 'classification' => 'deels_openbaar', 'documentRef' => 'doc-2', 'redactedDocumentRef' => 'doc-2r'],
				['@self' => ['id' => 'a-3'], 'classification' => 'openbaar', 'documentRef' => 'doc-3'],
			]
		);

		self::assertSame(['doc-1', 'doc-2r', 'doc-3'], $this->asked);
		self::assertSame(['doc-1', 'doc-2r', 'doc-3'], array_column($delivery['documents'], 'id'));
		self::assertSame(
			[
				['assessment' => 'a-1', 'classification' => 'openbaar', 'deliveredRef' => 'doc-1', 'originalRef' => 'doc-1', 'fileName' => 'T doc-1', 'content' => 'C doc-1'],
				['assessment' => 'a-2', 'classification' => 'deels_openbaar', 'deliveredRef' => 'doc-2r', 'originalRef' => 'doc-2', 'fileName' => 'T doc-2r', 'content' => 'C doc-2r'],
				['assessment' => 'a-3', 'classification' => 'openbaar', 'deliveredRef' => 'doc-3', 'originalRef' => 'doc-3', 'fileName' => 'T doc-3', 'content' => 'C doc-3'],
			],
			$delivery['items']
		);
	}//end testWhatGoesOut()

	/**
	 * Refused: niet_openbaar, an unknown or missing classification, deels_openbaar without a
	 * redaction (never its original), and a document the loader cannot find.
	 *
	 * @return void
	 */
	public function testWhatNeverGoesOut(): void {
		$delivery = $this->deliver(
			[
				['id' => 'r-1', 'classification' => 'niet_openbaar', 'documentRef' => 'doc-1'],
				['id' => 'r-2', 'classification' => 'deels_openbaar', 'documentRef' => 'doc-2'],
				['id' => 'r-3', 'classification' => 'Openbaar', 'documentRef' => 'doc-3'],
				['id' => 'r-4', 'documentRef' => 'doc-4'],
				['id' => 'r-5', 'classification' => 'openbaar', 'documentRef' => 'gone-5'],
				['id' => 'r-6', 'classification' => 'openbaar'],
			]
		);

		self::assertSame(['gone-5'], $this->asked);
		self::assertSame(['documents' => [], 'items' => []], $delivery);
	}//end testWhatNeverGoesOut()

	/**
	 * An item without id or file name records blanks, and the title stands in for the file name.
	 *
	 * @return void
	 */
	public function testBlanksAndFileName(): void {
		$sources = new PublicationSources(settingsService: $this->createMock(SettingsService::class));
		$delivery = $sources->collectDelivery(
			assessments: [
				['classification' => 'openbaar', 'documentRef' => 'x'],
				['classification' => 'openbaar', 'documentRef' => 'y'],
			],
			documentLoader: static fn (string $ref): ?array => ($ref === 'x') ? ['fileName' => 'x.pdf', 'title' => 'X'] : [],
		);

		self::assertSame(
			[
				['assessment' => '', 'classification' => 'openbaar', 'deliveredRef' => 'x', 'originalRef' => 'x', 'fileName' => 'x.pdf', 'content' => ''],
				['assessment' => '', 'classification' => 'openbaar', 'deliveredRef' => 'y', 'originalRef' => 'y', 'fileName' => '', 'content' => ''],
			],
			$delivery['items']
		);
	}//end testBlanksAndFileName()
}//end class
