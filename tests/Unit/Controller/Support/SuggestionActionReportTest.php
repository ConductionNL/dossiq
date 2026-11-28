<?php

/**
 * SuggestionActionReport unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
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

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\SuggestionActionReport;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * What a reported action on a suggestion says: its refusal, and the parts the entry stores.
 *
 * @covers \OCA\Dossiq\Controller\Support\SuggestionActionReport
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class SuggestionActionReportTest extends TestCase {

	/**
	 * The report over a request carrying these parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return SuggestionActionReport
	 */
	private function report(array $params): SuggestionActionReport {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				if (array_key_exists($key, $params) === true) {
					return $params[$key];
				}

				return $default;
			}
		);

		return new SuggestionActionReport(request: $request);
	}//end report()

	/**
	 * A missing field, an unknown action and an ignored suggestion without a known cause are refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testTheReportIsRefusedWithItsReason(): void {
		$complete = ['caseId' => 'case-1', 'type' => 'summary', 'userAction' => 'accepted'];

		$this->assertSame(
			expected: 'caseId, type, and userAction are required',
			actual: $this->report(params: ['caseId' => 'case-1', 'type' => 'summary'])->refusal()
		);
		$this->assertSame(
			expected: 'userAction must be one of: accepted, rejected, modified, ignored',
			actual: $this->report(params: ['userAction' => 'liked'] + $complete)->refusal()
		);
		$this->assertSame(
			expected: 'unusedBecause must be one of: closed, expired, superseded',
			actual: $this->report(params: ['userAction' => 'ignored', 'unusedBecause' => 'bored'] + $complete)->refusal()
		);
		$this->assertNull(actual: $this->report(params: $complete)->refusal());
		$this->assertNull(
			actual: $this->report(params: ['userAction' => 'ignored', 'unusedBecause' => 'expired'] + $complete)->refusal()
		);
	}//end testTheReportIsRefusedWithItsReason()

	/**
	 * Text is kept under `text`, an array as it is, and an absent value is empty or null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testTheStoredPartsAreReadAsTheEntryHoldsThem(): void {
		$report = $this->report(
			params: [
				'suggestion' => 'Route to team Woo',
				'actualValue' => ['team' => 'woo'],
				'reason' => 'checked',
				'model' => 'local-1',
			]
		);
		$this->assertSame(expected: ['text' => 'Route to team Woo'], actual: $report->suggestion());
		$this->assertSame(expected: ['team' => 'woo'], actual: $report->actualValue());
		$this->assertSame(expected: 'checked', actual: $report->reason());
		$this->assertSame(
			expected: [
				'unusedBecause' => '',
				'prompt' => '',
				'model' => 'local-1',
				'suggestionId' => '',
				'documentId' => '',
			],
			actual: $report->context()
		);

		$empty = $this->report(params: ['suggestion' => '', 'reason' => 7]);
		$this->assertSame(expected: [], actual: $empty->suggestion());
		$this->assertNull(actual: $empty->actualValue());
		$this->assertNull(actual: $empty->reason());
	}//end testTheStoredPartsAreReadAsTheEntryHoldsThem()
}//end class
