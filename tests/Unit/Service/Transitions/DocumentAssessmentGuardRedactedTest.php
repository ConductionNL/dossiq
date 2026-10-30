<?php

/**
 * A move that requires every partly public document redacted waits for every redacted copy.
 *
 * Through the real guard and the real assessment service over an in-memory
 * register: the control (one copy missing, refused) and the case (every copy
 * there, passed) differ only in the one `redactedDocumentRef`.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/status-transition-engine/spec.md#requirement-a-move-may-require-every-partly-public-document-redacted
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\DocumentAssessmentGuard;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Transitions\DocumentAssessmentGuard
 * @covers \OCA\Dossiq\Service\WOODocumentAssessmentService
 */
class DocumentAssessmentGuardRedactedTest extends TestCase {

	/**
	 * The guard over the register, or over no OpenRegister.
	 *
	 * @param InMemoryRegister|null $register The register.
	 *
	 * @return DocumentAssessmentGuard
	 */
	private function guard(?InMemoryRegister $register): DocumentAssessmentGuard {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'woo_assessment_schema' => 'wooDocumentAssessment',
			][$key] ?? $default
		);

		return new DocumentAssessmentGuard(
			assessments: new WOODocumentAssessmentService(
				settingsService: $settings,
				userSession: $this->createMock(IUserSession::class),
				logger: new NullLogger()
			),
			logger: new NullLogger(),
			l10n: new SourceL10n(),
		);
	}//end guard()

	/**
	 * A case with two partly public documents and one public one.
	 *
	 * @param string $secondCopy The second partly public document's copy, or ''.
	 *
	 * @return InMemoryRegister
	 */
	private function register(string $secondCopy): InMemoryRegister {
		$register = new InMemoryRegister();
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a-1', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-1', 'classification' => 'deels_openbaar', 'redactedDocumentRef' => 'copy-1']);
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a-2', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-2', 'classification' => 'deels_openbaar', 'redactedDocumentRef' => $secondCopy]);
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a-3', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-3', 'classification' => 'openbaar']);
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a-4', row: ['caseRef' => 'case-2', 'documentRef' => 'doc-4', 'classification' => 'deels_openbaar']);

		return $register;
	}//end register()

	/**
	 * CONTROL: one partly public document without a copy holds the move.
	 *
	 * @return void
	 */
	public function testOneMissingCopyHoldsTheMove(): void {
		$result = $this->guard(register: $this->register(secondCopy: ''))->evaluate(
			guardConfig: ['type' => 'documentAssessment', 'require' => 'redacted'],
			case: ['id' => 'case-1'],
			userId: 'pjansen'
		);

		self::assertFalse($result->passed);
		self::assertSame('1 of 2 partly public documents are still waiting for a redacted version', $result->failureMessage);
	}//end testOneMissingCopyHoldsTheMove()

	/**
	 * Every copy there, the move passes.
	 *
	 * @return void
	 */
	public function testEveryCopyLetsTheMovePass(): void {
		$result = $this->guard(register: $this->register(secondCopy: 'copy-2'))->evaluate(
			guardConfig: ['type' => 'documentAssessment', 'require' => 'redacted'],
			case: ['id' => 'case-1'],
			userId: 'pjansen'
		);

		self::assertTrue($result->passed);
		self::assertSame(2, $result->details['partlyPublic']);
	}//end testEveryCopyLetsTheMovePass()

	/**
	 * An assessment the caller cannot read still holds the move.
	 *
	 * @return void
	 */
	public function testAnAssessmentOutOfTheCallersSightStillHoldsTheMove(): void {
		$register = $this->register(secondCopy: '');
		$register->hiddenFromCaller = ['wooDocumentAssessment'];

		$result = $this->guard(register: $register)->evaluate(
			guardConfig: ['type' => 'documentAssessment', 'require' => 'redacted'],
			case: ['id' => 'case-1'],
			userId: 'pjansen'
		);

		self::assertFalse($result->passed);
	}//end testAnAssessmentOutOfTheCallersSightStillHoldsTheMove()

	/**
	 * A case with nothing partly public has nothing to wait for.
	 *
	 * @return void
	 */
	public function testNothingPartlyPublicPasses(): void {
		$result = $this->guard(register: $this->register(secondCopy: ''))->evaluate(
			guardConfig: ['type' => 'documentAssessment', 'require' => 'redacted'],
			case: ['id' => 'case-3'],
			userId: 'pjansen'
		);

		self::assertTrue($result->passed);
	}//end testNothingPartlyPublicPasses()

	/**
	 * A register that cannot be read refuses the move and says so.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterRefuses(): void {
		$result = $this->guard(register: null)->evaluate(
			guardConfig: ['type' => 'documentAssessment', 'require' => 'redacted'],
			case: ['id' => 'case-1'],
			userId: 'pjansen'
		);

		self::assertFalse($result->passed);
		self::assertTrue($result->details['unreadable']);
	}//end testAnUnreadableRegisterRefuses()
}//end class
