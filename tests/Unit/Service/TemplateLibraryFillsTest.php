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
	 * The adjournment letter names the decision deadline and the extension it fills in.
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
				['label' => 'Decision deadline', 'field' => 'legalDecisionDate', 'as' => 'date'],
				['label' => 'Adjournment', 'field' => 'statutoryExtension', 'as' => 'extension', 'from' => 'legalDecisionDate'],
			],
			actual: $byId['verdagingsbrief']['fills']
		);
		$this->assertSame(expected: [], actual: $byId['ontvangstbevestiging']['fills']);
	}//end testTheAdjournmentLetterListsWhatItFillsIn()
}//end class
