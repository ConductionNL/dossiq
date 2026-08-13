<?php

/**
 * AiAuditService recording Unit Tests
 *
 * Guards the two write paths onto the Algoritmeregister oversight trail:
 * `recordUserAction()` (builds the entry, stamping the configured model) and
 * `recordAssistantAuditEntry()` (forwards an already-built entry verbatim).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Ai
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-oversight-log/tasks.md#1.1
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Ai;

use OCA\Procest\Service\Ai\AiAuditLog;
use OCA\Procest\Service\Ai\AiAuditService;
use OCA\Procest\Service\Ai\AiModelIdentity;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AiAuditService's write paths.
 *
 * @covers \OCA\Procest\Service\Ai\AiAuditService
 *
 * @uses \OCA\Procest\Service\Ai\AiModelIdentity
 */
class AiAuditServiceRecordTest extends TestCase {

	/**
	 * Build a service whose audit sink is the supplied mock and whose model
	 * identity resolves to `openai/gpt-4o`.
	 *
	 * @param AiAuditLog $audit The audit sink mock.
	 *
	 * @return AiAuditService
	 */
	private function service(AiAuditLog $audit): AiAuditService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static fn (string $app, string $key, string $default): string => match ($key) {
					'ai_model_type' => 'openai',
					'ai_model_name' => 'gpt-4o',
					default => $default,
				}
			);

		return new AiAuditService(
			audit: $audit,
			modelIdentity: new AiModelIdentity($appConfig),
		);
	}//end service()

	/**
	 * recordUserAction() writes one entry carrying the case, the human
	 * decision, the applied value and the configured model identifier.
	 *
	 * @return void
	 */
	public function testRecordUserActionWritesEntryWithModelAndDecision(): void {
		$captured = null;

		$audit = $this->createMock(AiAuditLog::class);
		$audit->expects($this->once())
			->method('record')
			->willReturnCallback(
				function (array $entry) use (&$captured): void {
					$captured = $entry;
				}
			);

		$result = $this->service($audit)->recordUserAction(
			caseId: 'case-1',
			type: 'classification',
			userAction: 'modified',
			suggestion: ['documentType' => 'aanvraag'],
			actualValue: ['documentType' => 'objection'],
			reason: 'misread the header',
			userId: 'alice',
		);

		$this->assertSame(['success' => true], $result);
		$this->assertSame('classification', $captured['type']);
		$this->assertSame('modified', $captured['action']);
		$this->assertSame('modified', $captured['userAction']);
		$this->assertSame('case-1', $captured['caseId']);
		$this->assertSame('openai/gpt-4o', $captured['model']);
		$this->assertSame(['documentType' => 'aanvraag'], $captured['suggestion']);
		$this->assertSame(['documentType' => 'objection'], $captured['actualValue']);
		$this->assertSame('misread the header', $captured['reason']);
		$this->assertSame('alice', $captured['userId']);
		$this->assertNotEmpty($captured['timestamp']);
	}//end testRecordUserActionWritesEntryWithModelAndDecision()

	/**
	 * A null actualValue/reason is normalised rather than written as null,
	 * so the schema never receives a null where it expects array/string.
	 *
	 * @return void
	 */
	public function testRecordUserActionNormalisesNullActualValueAndReason(): void {
		$captured = null;

		$audit = $this->createMock(AiAuditLog::class);
		$audit->method('record')->willReturnCallback(
			function (array $entry) use (&$captured): void {
				$captured = $entry;
			}
		);

		$this->service($audit)->recordUserAction(
			caseId: 'case-2',
			type: 'routing',
			userAction: 'accepted',
			suggestion: [],
			actualValue: null,
			reason: null,
			userId: 'bob',
		);

		$this->assertSame([], $captured['actualValue']);
		$this->assertSame('', $captured['reason']);
	}//end testRecordUserActionNormalisesNullActualValueAndReason()

	/**
	 * recordAssistantAuditEntry() forwards a pre-built entry to the same sink
	 * without rewriting it — the conversational surface owns its own shape.
	 *
	 * @return void
	 */
	public function testRecordAssistantAuditEntryForwardsEntryVerbatim(): void {
		$entry = [
			'type' => 'assistant',
			'caseId' => 'case-3',
			'model' => 'hermiq',
			'userId' => 'carol',
		];

		$audit = $this->createMock(AiAuditLog::class);
		$audit->expects($this->once())->method('record')->with($entry);

		$this->service($audit)->recordAssistantAuditEntry($entry);
	}//end testRecordAssistantAuditEntryForwardsEntryVerbatim()
}//end class
