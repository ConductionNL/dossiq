<?php

/**
 * Template metadata tests
 *
 * What the template library lists for one decoded template.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\TemplateLibrary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\TemplateLibrary;

use OCA\Dossiq\Service\TemplateLibrary\TemplateMetadata;
use PHPUnit\Framework\TestCase;

/**
 * The library entry of one template.
 *
 * @covers \OCA\Dossiq\Service\TemplateLibrary\TemplateMetadata
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class TemplateMetadataTest extends TestCase {

	/**
	 * A full template keeps its own values; fills lose their keys, notes are trimmed.
	 *
	 * @return void
	 */
	public function testAFullTemplateKeepsItsValues(): void {
		$entry = (new TemplateMetadata())->entry(
			data: [
				'id' => 'besluit',
				'title' => 'Besluit',
				'description' => 'Een besluit',
				'category' => 'decisions',
				'version' => '3.1.0',
				'fills' => ['first' => 'case.title', 'second' => 'case.date'],
				'notes' => [' Bezwaartermijn zes weken ', "\t", null],
			],
			file: '/somewhere/besluit-v3.json'
		);

		self::assertSame(
			[
				'id' => 'besluit',
				'title' => 'Besluit',
				'description' => 'Een besluit',
				'category' => 'decisions',
				'version' => '3.1.0',
				'file' => 'besluit-v3.json',
				'fills' => ['case.title', 'case.date'],
				'notes' => ['Bezwaartermijn zes weken'],
			],
			$entry
		);
	}//end testAFullTemplateKeepsItsValues()

	/**
	 * An empty template takes its id from the file name and the library defaults.
	 *
	 * @return void
	 */
	public function testAnEmptyTemplateFallsBackToTheDefaults(): void {
		self::assertSame(
			[
				'id' => 'plain',
				'title' => '',
				'description' => '',
				'category' => 'general',
				'version' => '1.0.0',
				'file' => 'plain.json',
				'fills' => [],
				'notes' => [],
			],
			(new TemplateMetadata())->entry(data: [], file: '/dir/plain.json')
		);
	}//end testAnEmptyTemplateFallsBackToTheDefaults()
}//end class
