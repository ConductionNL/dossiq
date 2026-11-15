<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The type and party names the case Files tab shows beside a document.
 *
 * @spec openspec/changes/case-files-list-columns/specs/document-zaakdossier/spec.md#requirement-the-case-files-list-shows-the-document-columns-of-the-board-req-zak-col-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DocumentListingLabels;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\DocumentListingLabels
 */
class DocumentListingLabelsTest extends TestCase {

	/** @var object The stub object service, counting its searches. */
	private object $objects;

	/** @var DocumentListingLabels The labels under test. */
	private DocumentListingLabels $labels;

	/**
	 * Labels over a stub type catalogue.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/** @var array<int, array<string, mixed>> Every search asked. */
			public array $searches = [];

			/**
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The type rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->searches[] = ['register' => $register, 'schema' => $schema, 'filters' => $filters];
				return [
					['@self' => ['id' => '11111111-2222-4333-8444-555555555555', 'slug' => 'iot-aanvraag'], 'description' => 'Aanvraag'],
					['@self' => ['id' => 'type-2'], 'description' => '  '],
				];
			}
		};

		$config = [
			'register' => 'dossiq',
			'dossier_informatieobjecttype_schema' => 'informatieobjecttype',
		];
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$this->labels = new DocumentListingLabels(settingsService: $settings);
	}//end setUp()

	/**
	 * Every row carries its type by name, from one catalogue read, whether it
	 * refers to the type by uuid, slug or name; an unknown uuid reads ''.
	 *
	 * @return void
	 */
	public function testEveryRowCarriesItsTypeByNameFromOneRead(): void {
		$rows = $this->labels->label(documents: [
			['informatieobjecttype' => '11111111-2222-4333-8444-555555555555'],
			['informatieobjecttype' => 'iot-aanvraag'],
			['informatieobjecttype' => 'Ontvangstbevestiging'],
			['informatieobjecttype' => '99999999-2222-4333-8444-555555555555'],
			[],
		]);

		$this->assertSame(
			expected: ['Aanvraag', 'Aanvraag', 'Ontvangstbevestiging', '', ''],
			actual: array_column(array: $rows, column_key: 'documentType'),
		);
		$this->assertCount(expectedCount: 1, haystack: $this->objects->searches);
		$this->assertSame(expected: 'informatieobjecttype', actual: $this->objects->searches[0]['schema']);
	}//end testEveryRowCarriesItsTypeByNameFromOneRead()

	/**
	 * The party is the sender of an incoming document, the recipients of an
	 * outgoing one, and for an internal one the sender before the recipients.
	 *
	 * @return void
	 */
	public function testThePartyFollowsTheDirection(): void {
		$both = ['senderName' => 'Jan Jansen', 'recipientNames' => ['Gemeente Utrecht', 'Ouder Link']];
		$rows = $this->labels->label(documents: [
			$both + ['direction' => 'incoming'],
			$both + ['direction' => 'outgoing'],
			$both + ['direction' => 'internal'],
			['senderName' => '', 'recipientNames' => ['Gemeente Utrecht'], 'direction' => 'internal'],
			['direction' => 'incoming'],
		]);

		$this->assertSame(
			expected: ['Jan Jansen', 'Gemeente Utrecht, Ouder Link', 'Jan Jansen', 'Gemeente Utrecht', ''],
			actual: array_column(array: $rows, column_key: 'partyName'),
		);
	}//end testThePartyFollowsTheDirection()

	/**
	 * An empty listing reads no catalogue.
	 *
	 * @return void
	 */
	public function testAnEmptyListingReadsNothing(): void {
		$this->assertSame(expected: [], actual: $this->labels->label(documents: []));
		$this->assertSame(expected: [], actual: $this->objects->searches);
	}//end testAnEmptyListingReadsNothing()

	/**
	 * Without a type catalogue configured every type reads as stored.
	 *
	 * @return void
	 */
	public function testWithoutACatalogueTypesReadAsStored(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturn('');

		$rows = (new DocumentListingLabels(settingsService: $settings))->label(documents: [['informatieobjecttype' => 'Besluit']]);

		$this->assertSame(expected: 'Besluit', actual: $rows[0]['documentType']);
	}//end testWithoutACatalogueTypesReadAsStored()

	/**
	 * Without OpenRegister the listing fails loudly rather than drawing empty
	 * columns.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheListingFails(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('getConfigValue')->willReturn('informatieobjecttype');

		$this->expectException(exception: RuntimeException::class);
		(new DocumentListingLabels(settingsService: $settings))->label(documents: [['title' => 'x']]);
	}//end testWithoutOpenRegisterTheListingFails()
}//end class
