<?php

/**
 * The library hands each template's fills to the generate dialog.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @link https://www.conduction.nl
 *
 * @spec openspec/specs/generate-fills-per-template/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TemplateLibraryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The template list carries each template's `fills`.
 */
class TemplateLibraryFillsTest extends TestCase {
	/**
	 * The adjournment letter names the decision deadline and the extension it fills in,
	 * the reasons it still needs and the status it is generated in.
	 *
	 * @return void
	 */
	public function testTheAdjournmentLetterListsWhatItFillsIn(): void {
		$service = new TemplateLibraryService(settingsService: $this->createMock(originalClassName: SettingsService::class), logger: new NullLogger());
		$byId = [];
		foreach ($service->listTemplates() as $template) {
			$byId[$template['id']] = $template;
		}

		$this->assertSame(
			expected: [
				['label' => 'Decision deadline', 'field' => 'legalDecisionDate', 'as' => 'deadline', 'term' => 'statutoryTerm'],
				['label' => 'Adjournment', 'field' => 'statutoryExtension', 'as' => 'extension', 'from' => 'legalDecisionDate'],
				['label' => 'Effective date', 'field' => 'statutoryExtension', 'as' => 'after-extension', 'from' => 'legalDecisionDate'],
				['label' => 'Statement of reasons', 'field' => 'extensionReason', 'required' => true],
				['label' => 'Status after generating', 'value' => 'Draft (concept)'],
			],
			actual: $byId['verdagingsbrief']['fills']
		);
		$this->assertSame(expected: [], actual: $byId['ontvangstbevestiging']['fills']);
	}//end testTheAdjournmentLetterListsWhatItFillsIn()

	/**
	 * The Woo decision templates forward their note about the objection period; a template without notes answers [].
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dialogs-answer-what-the-boards-draw/specs/generate-fills-per-template/spec.md#requirement-a-template-carries-notes-for-the-generate-dialog
	 */
	public function testTheWooDecisionTemplatesCarryTheirNotes(): void {
		$service = new TemplateLibraryService(settingsService: $this->createMock(originalClassName: SettingsService::class), logger: new NullLogger());
		$byId = [];
		foreach ($service->listTemplates() as $template) {
			$byId[$template['id']] = $template;
		}

		$this->assertCount(expectedCount: 1, haystack: $byId['woo-besluit-openbaar']['notes']);
		$this->assertStringContainsString(needle: 'bezwaartermijn', haystack: $byId['woo-besluit-openbaar']['notes'][0]);
		$this->assertCount(expectedCount: 1, haystack: $byId['woo-besluit-deels-openbaar']['notes']);
		$this->assertSame(expected: [], actual: $byId['ontvangstbevestiging']['notes']);
	}//end testTheWooDecisionTemplatesCarryTheirNotes()
}//end class
