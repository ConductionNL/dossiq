<?php

/**
 * RequiredDocumentGuard Unit Tests
 *
 * Verifies the guard scans every documented attachment field (`documents`,
 * `files`, `caseDocuments`, `attachments`) and accepts both `documentType`
 * and `type` keys on each doc entry.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
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
 * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-19
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Transitions\RequiredDocumentGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\RequiredDocumentGuard
 *
 * @uses \OCA\Dossiq\Service\Transitions\GuardResult
 */
class RequiredDocumentGuardTest extends TestCase {
	/**
	 * @return void
	 */
	public function testFailsWhenDocumentTypeMissing(): void {
		$guard = new RequiredDocumentGuard();
		$result = $guard->evaluate(guardConfig: [], case: ['id' => 'c'], userId: 'u');

		self::assertFalse($result->passed);
		self::assertSame('Required-document guard missing documentType', $result->failureMessage);
	}//end testFailsWhenDocumentTypeMissing()

	/**
	 * @return void
	 */
	public function testFailsWhenNoMatchingDocument(): void {
		$guard = new RequiredDocumentGuard();
		$result = $guard->evaluate(
			guardConfig: ['documentType' => 'Besluit'],
			case: ['documents' => [['documentType' => 'Aanvraag']]],
			userId: 'u',
		);

		self::assertFalse($result->passed);
		self::assertSame('Besluit', $result->details['documentType']);
	}//end testFailsWhenNoMatchingDocument()

	/**
	 * @return void
	 */
	public function testPassesOnDocumentTypeField(): void {
		$guard = new RequiredDocumentGuard();
		$result = $guard->evaluate(
			guardConfig: ['documentType' => 'Besluit'],
			case: ['documents' => [['documentType' => 'Besluit']]],
			userId: 'u',
		);

		self::assertTrue($result->passed);
	}//end testPassesOnDocumentTypeField()

	/**
	 * @return void
	 */
	public function testPassesOnGenericTypeField(): void {
		// Some schemas use `type` rather than `documentType`.
		$guard = new RequiredDocumentGuard();
		$result = $guard->evaluate(
			guardConfig: ['documentType' => 'Besluit'],
			case: ['files' => [['type' => 'Besluit']]],
			userId: 'u',
		);

		self::assertTrue($result->passed);
	}//end testPassesOnGenericTypeField()

	/**
	 * @return void
	 */
	public function testScansAllAttachmentFields(): void {
		$guard = new RequiredDocumentGuard();
		$result = $guard->evaluate(
			guardConfig: ['documentType' => 'Bewijs'],
			case: [
				'documents' => [['type' => 'Anders']],
				'files' => [['type' => 'X']],
				'caseDocuments' => [['type' => 'Y']],
				'attachments' => [['documentType' => 'Bewijs']],
			],
			userId: 'u',
		);

		self::assertTrue($result->passed);
	}//end testScansAllAttachmentFields()

	/**
	 * @return void
	 */
	public function testHandlesMissingAttachmentFieldsGracefully(): void {
		$guard = new RequiredDocumentGuard();
		$result = $guard->evaluate(
			guardConfig: ['documentType' => 'Besluit'],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertFalse($result->passed);
	}//end testHandlesMissingAttachmentFieldsGracefully()
}//end class
