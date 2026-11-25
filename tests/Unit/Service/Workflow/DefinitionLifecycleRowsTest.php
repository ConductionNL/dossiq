<?php

/**
 * DefinitionLifecycleRows unit tests.
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

use OCA\Dossiq\Service\Workflow\DefinitionLifecycleRows;
use OCA\Dossiq\Service\Workflow\WorkflowJsonProperty;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use PHPUnit\Framework\TestCase;

/**
 * The rows a draft, a clone, a publish and a deprecation write.
 *
 * @covers \OCA\Dossiq\Service\Workflow\DefinitionLifecycleRows
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class DefinitionLifecycleRowsTest extends TestCase {

	/**
	 * The rows over a guard that reads the row's own route and an encoder that writes JSON.
	 *
	 * @return DefinitionLifecycleRows
	 */
	private function rows(): DefinitionLifecycleRows {
		$guard = $this->createMock(originalClassName: WorkflowLifecycleGuard::class);
		$guard->method('variantOf')->willReturnCallback(
			static fn (array $row): string => (string)($row['variant'] ?? 'standaard')
		);
		$json = $this->createMock(originalClassName: WorkflowJsonProperty::class);
		$json->method('encode')->willReturnCallback(static fn (mixed $value): string => (string)json_encode($value));

		return new DefinitionLifecycleRows(guard: $guard, json: $json);
	}//end rows()

	/**
	 * A draft is an inactive draft carrying its content encoded; a clone keeps its source's route and content.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testADraftAndACloneStartInactive(): void {
		$draft = $this->rows()->draft(
			payload: ['title' => 'Melding', 'caseType' => 'ct-1', 'steps' => [['id' => 's1']], 'variant' => 'spoed'],
			version: 3
		);
		$this->assertSame(
			expected: [
				'title' => 'Melding',
				'description' => '',
				'caseType' => 'ct-1',
				'variant' => 'spoed',
				'version' => 3,
				'isActive' => false,
				'isDraft' => true,
				'lifecycleStatus' => 'draft',
				'steps' => '[{"id":"s1"}]',
				'transitions' => '[]',
				'nodePositions' => '',
			],
			actual: $draft
		);

		$clone = $this->rows()->cloned(source: ['title' => 'Melding ', 'caseType' => 'ct-1', 'steps' => '[]'], version: 4);
		$this->assertSame(expected: 'Melding (kopie)', actual: $clone['title']);
		$this->assertSame(expected: 'standaard', actual: $clone['variant']);
		$this->assertSame(expected: 4, actual: $clone['version']);
		$this->assertSame(expected: '[]', actual: $clone['steps']);
		$this->assertFalse(condition: $clone['isActive']);
	}//end testADraftAndACloneStartInactive()

	/**
	 * A publish activates and carries enriched transitions only when there are any; a deprecation deactivates.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAPublishActivatesAndADeprecationRetires(): void {
		$rows = $this->rows();

		$this->assertSame(
			expected: ['lifecycleStatus' => 'published', 'isActive' => true, 'isDraft' => false],
			actual: $rows->published(authoredTransitions: null)
		);
		$this->assertSame(
			expected: '[{"from":"a"}]',
			actual: $rows->published(authoredTransitions: [['from' => 'a']])['transitions']
		);
		$this->assertSame(expected: ['lifecycleStatus' => 'deprecated', 'isActive' => false], actual: $rows->deprecated());
	}//end testAPublishActivatesAndADeprecationRetires()
}//end class
