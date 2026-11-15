<?php

/**
 * How often a term may be extended, and what an extension answers.
 *
 * Two rules every extension shares, whatever case type it is on. A case type
 * may state how often its term may be extended (`maxExtensions`), and a case
 * that used them all is refused by name. And the answer to an extension says
 * whether the requester was told, in the words the term engine gave, so a
 * notice that did not go out is never a missing key.
 *
 * Nothing here names a procedure: the limit is configuration on the case type.
 *
 * A trait, like `Support\SearchesObjects`: the lifecycle service applies the two
 * rules and takes no further collaborator for them.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Lifecycle;

/**
 * The extension limit of a case type and the notice answer of an extension.
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md
 */
trait TermExtensionRule {
	/**
	 * Whether the case used every extension its case type allows.
	 *
	 * @param array<string, mixed> $case     The case.
	 * @param array<string, mixed> $caseType What the case type states.
	 *
	 * @return bool False when the case type states no limit.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md#requirement-a-case-type-states-how-often-its-term-may-be-extended-req-tel-001
	 */
	private function extensionsUsedUp(array $case, array $caseType): bool {
		$limit = ($caseType['maxExtensions'] ?? null);
		if (is_int($limit) === false) {
			return false;
		}

		return (int)($case['extensionCount'] ?? 0) >= $limit;
	}//end extensionsUsedUp()

	/**
	 * Whether the requester was told about an extension, as the term engine answered.
	 *
	 * A case without a statutory term has no notice to send. That is said,
	 * with its own reason code, so a reader never has to guess what a missing
	 * key means.
	 *
	 * @param array<string, mixed>|null $extended What the term engine answered, or null without a term.
	 *
	 * @return array{noticeStatus: string, noticeChannel: string, noticeReasonCode: string, noticeReason: string}
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md#requirement-an-extension-answers-whether-the-requester-was-told-req-tel-002
	 */
	private function extensionNoticeOf(?array $extended): array {
		if ($extended === null) {
			return [
				'noticeStatus' => 'not-sent',
				'noticeChannel' => '',
				'noticeReasonCode' => 'no-statutory-term',
				'noticeReason' => 'This case has no statutory term, so no notice was sent.',
			];
		}

		if (isset($extended['noticeStatus']) === false) {
			return [
				'noticeStatus' => 'not-sent',
				'noticeChannel' => '',
				'noticeReasonCode' => 'notice-not-wired',
				'noticeReason' => 'No sender is wired, so the requester was not told.',
			];
		}

		return [
			'noticeStatus' => (string)$extended['noticeStatus'],
			'noticeChannel' => (string)($extended['noticeChannel'] ?? ''),
			'noticeReasonCode' => (string)($extended['noticeReasonCode'] ?? ''),
			'noticeReason' => (string)($extended['noticeReason'] ?? ''),
		];
	}//end extensionNoticeOf()
}//end trait
