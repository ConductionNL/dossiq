<?php

/**
 * How often a term may be extended, and what an extension answers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use OCA\Dossiq\Service\Lifecycle\TermExtensionRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two rules, asked directly.
 */
class TermExtensionRuleTest extends TestCase {

	use TermExtensionRule;

	/**
	 * Cases against limits.
	 *
	 * @return array<string, array{0: mixed, 1: mixed, 2: bool}>
	 */
	public static function limits(): array {
		return [
			'none used of one' => [0, 1, false],
			'one used of one' => [1, 1, true],
			'two used of one, however that came about' => [2, 1, true],
			'one used of two' => [1, 2, false],
			'no limit stated' => [5, null, false],
			'a limit that is not a whole number is no limit' => [5, '1', false],
			'no count on the case reads as none used' => [null, 1, false],
		];
	}//end limits()

	/**
	 * A case is used up when it took as many extensions as its case type states.
	 *
	 * @param mixed $count    The case's `extensionCount`.
	 * @param mixed $limit    The case type's `maxExtensions`.
	 * @param bool  $expected Whether the extensions are used up.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md#requirement-a-case-type-states-how-often-its-term-may-be-extended-req-tel-001
	 */
	#[DataProvider('limits')]
	public function testACaseIsUsedUpAtItsCaseTypesLimit(mixed $count, mixed $limit, bool $expected): void {
		self::assertSame(
			$expected,
			$this->extensionsUsedUp(case: ['extensionCount' => $count], caseType: ['maxExtensions' => $limit])
		);
	}//end testACaseIsUsedUpAtItsCaseTypesLimit()

	/**
	 * The engine's notice answer is passed on, key for key, and nothing else.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md#requirement-an-extension-answers-whether-the-requester-was-told-req-tel-002
	 */
	public function testTheEnginesNoticeIsPassedOn(): void {
		$notice = $this->extensionNoticeOf(
			extended: ['id' => 'ti-1', 'endDateCurrent' => '2026-11-16', 'noticeStatus' => 'sent', 'noticeChannel' => 'email']
		);

		self::assertSame(
			['noticeStatus' => 'sent', 'noticeChannel' => 'email', 'noticeReasonCode' => '', 'noticeReason' => ''],
			$notice
		);
	}//end testTheEnginesNoticeIsPassedOn()

	/**
	 * A case without a statutory term says no notice was sent, and why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md#requirement-an-extension-answers-whether-the-requester-was-told-req-tel-002
	 */
	public function testNoStatutoryTermIsSaidNotLeftOut(): void {
		$notice = $this->extensionNoticeOf(extended: null);

		self::assertSame('not-sent', $notice['noticeStatus']);
		self::assertSame('no-statutory-term', $notice['noticeReasonCode']);
		self::assertSame('This case has no statutory term, so no notice was sent.', $notice['noticeReason']);
	}//end testNoStatutoryTermIsSaidNotLeftOut()

	/**
	 * An engine answer without notice keys means no sender is wired.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md#requirement-an-extension-answers-whether-the-requester-was-told-req-tel-002
	 */
	public function testAnAnswerWithoutNoticeKeysIsNotSent(): void {
		$notice = $this->extensionNoticeOf(extended: ['id' => 'ti-1']);

		self::assertSame('not-sent', $notice['noticeStatus']);
		self::assertSame('notice-not-wired', $notice['noticeReasonCode']);
	}//end testAnAnswerWithoutNoticeKeysIsNotSent()
}//end class
