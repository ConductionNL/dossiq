<?php

/**
 * Golden test for the refusals a declared form of fields gets: every refusal
 * whole, in order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\Task\TaskDeclarationValidator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class FieldFormRefusalsGoldenTest extends TestCase {

	private function refusals(array $form): array {
		$class     = new ReflectionClass(TaskDeclarationValidator::class);
		$validator = $class->newInstanceWithoutConstructor();
		return $class->getMethod('fieldFormRefusals')->invoke($validator, $form, 'Advies', 'steps[2].task.form');
	}//end refusals()

	public function testEveryRefusalWhole(): void {
		$refusal = static fn (string $path, string $message): array => ['path' => 'steps[2].task.form' . $path, 'code' => 'unresolvable_form', 'message' => $message];
		$noSchema = $refusal('.schema', 'Task "Advies" declares a form of fields without naming the schema they belong to.');
		$noFields = $refusal('.fields', 'Task "Advies" declares a form with no fields, which asks the handler for nothing.');
		$noName   = static fn (int $i): array => $refusal('.fields[' . $i . '].field', 'Task "Advies" declares a form field with no name.');

		$this->assertSame([], $this->refusals(['schema' => 'case', 'fields' => [['field' => 'verslag']]]));
		$this->assertSame([$noSchema, $noFields], $this->refusals([]));
		$this->assertSame([$noSchema, $noFields], $this->refusals(['schema' => ' ', 'fields' => 'verslag']));
		$this->assertSame([$noFields], $this->refusals(['schema' => 'case', 'fields' => []]));
		$this->assertSame(
			[$noSchema, $noName(1), $noName(2), $noName(0), $noName(7)],
			$this->refusals(['fields' => [0 => ['field' => 'ok'], 1 => ['field' => ' '], 2 => 'naam', 'x' => [], 7 => ['label' => 'Zonder naam']]])
		);
	}//end testEveryRefusalWhole()
}//end class
