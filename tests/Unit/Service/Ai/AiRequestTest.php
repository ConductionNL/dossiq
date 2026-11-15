<?php

/**
 * Unit tests for AiRequest: an AI request's audit head is its type, then its
 * subject fields, then the model and prompt, which win over subject fields of
 * the same name only where the subject does not set them first.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Ai;

use OCA\Dossiq\Service\Ai\AiRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Ai\AiRequest
 */
class AiRequestTest extends TestCase {

	public function testTheHeadIsTypeSubjectModelAndPromptInThatOrder(): void {
		$request = new AiRequest(type: 'summary', subject: ['caseId' => 'Z-1', 'documentId' => 'D-9'], userId: 'alice', prompt: static fn (): string => 'p');

		$this->assertSame(
			['type' => 'summary', 'caseId' => 'Z-1', 'documentId' => 'D-9', 'model' => 'llama3', 'prompt' => 'Vat samen'],
			$request->head('llama3', 'Vat samen')
		);
	}//end testTheHeadIsTypeSubjectModelAndPromptInThatOrder()

	public function testTheTypeCannotBeOverriddenByTheSubjectButEarlierKeysWin(): void {
		$request = new AiRequest(type: 'question', subject: ['type' => 'other', 'model' => 'from-subject'], userId: 'alice', prompt: static fn (): string => 'p');

		$this->assertSame(['type' => 'question', 'model' => 'from-subject', 'prompt' => 'x'], $request->head('llama3', 'x'));
	}//end testTheTypeCannotBeOverriddenByTheSubjectButEarlierKeysWin()
}//end class
