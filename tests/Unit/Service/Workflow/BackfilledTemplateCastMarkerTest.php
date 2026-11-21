<?php

/**
 * Which stored backfilled template is to be healed.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Workflow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\Workflow\BackfilledTemplateLabels;
use PHPUnit\Framework\TestCase;

/**
 * BackfilledTemplateLabels::castMarked().
 *
 * @covers \OCA\Dossiq\Service\Workflow\BackfilledTemplateLabels
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class BackfilledTemplateCastMarkerTest extends TestCase {

	/**
	 * A template with an id whose labels read as the cast of a map is to be healed; any other is not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testOnlyATemplateWithAnIdAndTheMarkerIsHealed(): void {
		$labels = new BackfilledTemplateLabels();
		$marked = ['steps' => '[{"title":"' . BackfilledTemplateLabels::CAST_OF_A_MAP . '"}]'];

		$this->assertTrue(condition: $labels->castMarked(id: 'tpl-1', template: $marked));
		$this->assertFalse(condition: $labels->castMarked(id: '', template: $marked));
		$this->assertFalse(condition: $labels->castMarked(id: 'tpl-1', template: ['steps' => '[{"title":"Ontvangen"}]']));
	}//end testOnlyATemplateWithAnIdAndTheMarkerIsHealed()
}//end class
