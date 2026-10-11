<?php

/**
 * Unit tests for IntakeTools: hermiq's intake tool delegates to the filing and
 * turns a refusal into a thrown error, never into a returned envelope.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-the-conversational-intake-files-through-dossiqs-own-create-only-path-req-aic-05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Mcp;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Intake\CaseIntakeFiling;
use OCA\Dossiq\Service\Mcp\IntakeTools;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for IntakeTools.
 */
class IntakeToolsTest extends TestCase {

	private CaseIntakeFiling&MockObject $filing;

	private IntakeTools $tools;

	protected function setUp(): void {
		$this->filing = $this->createMock(CaseIntakeFiling::class);
		$this->tools = new IntakeTools(filing: $this->filing);
	}//end setUp()

	public function testFileCaseHandsHermiqsArgumentsToTheFiling(): void {
		$messages = [['channel' => 'web', 'text' => 'De lantaarnpaal is kapot.', 'at' => '2026-10-10T10:00:00+02:00']];
		$this->filing->expects($this->once())
			->method('file')
			->with('melding-openbare-ruimte', 'Kapotte lantaarnpaal', 'jan@example.nl', $messages)
			->willReturn(['id' => 'case-1', 'identifier' => 'Z-2026-0001', 'title' => 'Kapotte lantaarnpaal', 'description' => 'x']);

		$result = $this->tools->fileCase(type: 'melding-openbare-ruimte', subject: 'Kapotte lantaarnpaal', person: 'jan@example.nl', messages: $messages);

		$this->assertSame(['id' => 'case-1', 'identifier' => 'Z-2026-0001', 'title' => 'Kapotte lantaarnpaal'], $result);
	}//end testFileCaseHandsHermiqsArgumentsToTheFiling()

	public function testARefusalIsThrownWithDossiqsSentence(): void {
		$this->filing->method('file')->willThrowException(
			new RefusedException(rule: 'intake-case-type-unknown', sentence: 'dossiq has no published case type "x", so it filed nothing.')
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('dossiq has no published case type "x", so it filed nothing.');
		$this->tools->fileCase(type: 'x', subject: 'iets');
	}//end testARefusalIsThrownWithDossiqsSentence()

	public function testTheToolCarriesTheMarkAndTheCreateTaxonomyHermiqReads(): void {
		$attributes = (new ReflectionMethod(IntakeTools::class, 'fileCase'))->getAttributes(McpTool::class);
		$this->assertCount(1, $attributes);
		$tool = $attributes[0]->newInstance();

		$this->assertSame('fileCase', $tool->name);
		$this->assertSame(['citizenIntake' => true], $tool->annotations);
		$this->assertSame('create', $tool->scope);
		$this->assertSame('create', $tool->action);
		$this->assertFalse($tool->readOnlyHint);
		$this->assertSame('instance', $tool->reach);

		// hermiq calls every intake tool with these four names.
		$params = array_map(static fn ($p) => $p->getName(), (new ReflectionMethod(IntakeTools::class, 'fileCase'))->getParameters());
		$this->assertSame(['type', 'subject', 'person', 'messages'], $params);
	}//end testTheToolCarriesTheMarkAndTheCreateTaxonomyHermiqReads()
}//end class
