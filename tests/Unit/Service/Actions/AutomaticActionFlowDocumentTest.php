<?php

/**
 * Unit tests for AutomaticActionFlowDocument: a migrated automatic action
 * becomes a manual OpenRegister flow running its steps in order between the
 * trigger and the end, named and described after the action it came from.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Actions;

use OCA\Dossiq\Service\Actions\AutomaticActionFlowDocument;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Actions\AutomaticActionFlowDocument
 */
class AutomaticActionFlowDocumentTest extends TestCase {

	public function testTheStepsRunInOrderBetweenTriggerAndEnd(): void {
		$flow = (new AutomaticActionFlowDocument())->build(
			['slug' => 'send-receipt', 'title' => 'Ontvangstbevestiging', 'description' => 'Stuur een bevestiging.'],
			'migrated:send-receipt',
			[['type' => 'dossiq.send-email', 'config' => ['template' => 'receipt']], ['type' => 'dossiq.set-status', 'config' => ['status' => 'ontvangen']]]
		);

		$this->assertSame('Ontvangstbevestiging', $flow['name']);
		$this->assertSame('Stuur een bevestiging. — Migrated from the Dossiq automatic action "send-receipt".', $flow['description']);
		$this->assertSame(['dossiq', true, 'manual', 'migrated:send-receipt'], [$flow['app'], $flow['enabled'], $flow['trigger'], $flow['notes']]);
		$this->assertSame(
			[
				['id' => 'trigger', 'type' => 'openregister.trigger-manual'],
				['id' => 'action', 'type' => 'dossiq.send-email', 'config' => ['template' => 'receipt']],
				['id' => 'action--2', 'type' => 'dossiq.set-status', 'config' => ['status' => 'ontvangen']],
				['id' => 'end', 'type' => 'openregister.end'],
			],
			$flow['nodes']
		);
		$this->assertSame(
			[
				['id' => 'trigger-action', 'from' => ['trigger'], 'to' => ['action']],
				['id' => 'action-action--2', 'from' => ['action'], 'to' => ['action--2']],
				['id' => 'action--2-end', 'from' => ['action--2'], 'to' => ['end']],
			],
			$flow['edges']
		);
	}//end testTheStepsRunInOrderBetweenTriggerAndEnd()

	public function testAnUntitledActionIsNamedByItsSlugAndDescribedByItsOrigin(): void {
		$flow = (new AutomaticActionFlowDocument())->build(['slug' => 'archive'], 'm', [['type' => 'dossiq.archive', 'config' => []]]);

		$this->assertSame('archive', $flow['name']);
		$this->assertSame('Migrated from the Dossiq automatic action "archive".', $flow['description']);
	}//end testAnUntitledActionIsNamedByItsSlugAndDescribedByItsOrigin()

	public function testAnActionWithoutStepsLinksTheTriggerToTheEnd(): void {
		$flow = (new AutomaticActionFlowDocument())->build(['slug' => 'noop', 'title' => 'Niets'], 'm', []);

		$this->assertSame(['trigger', 'end'], array_column($flow['nodes'], 'id'));
		$this->assertSame([['id' => 'trigger-end', 'from' => ['trigger'], 'to' => ['end']]], $flow['edges']);
	}//end testAnActionWithoutStepsLinksTheTriggerToTheEnd()
}//end class
