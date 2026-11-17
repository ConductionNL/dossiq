<?php

/**
 * How filinq concluded one signing request a decision raised.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Signing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/signing-through-filinq/specs/libresign-besluit-signing/spec.md#requirement-a-signing-request-that-ends-unsigned-is-shown-as-not-concluded
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Signing;

/**
 * The conclusion SigningConclusionRecorder::record() writes onto a decision.
 *
 * @spec openspec/changes/signing-through-filinq/specs/libresign-besluit-signing/spec.md#requirement-a-signing-request-that-ends-unsigned-is-shown-as-not-concluded
 */
final class SigningConclusion {
	/**
	 * Constructor.
	 *
	 * @param string      $decisionId        The beschikking UUID (filinq's subject id).
	 * @param string      $requestId         Filinq's signing request id.
	 * @param string      $status            Filinq's terminal status: signed, declined, expired or cancelled.
	 * @param string|null $signedDocumentRef The signed document, when signed.
	 * @param string|null $signedAt          When it was signed.
	 *
	 * @spec openspec/changes/signing-through-filinq/specs/libresign-besluit-signing/spec.md#requirement-a-signing-request-that-ends-unsigned-is-shown-as-not-concluded
	 */
	public function __construct(
		public readonly string $decisionId,
		public readonly string $requestId,
		public readonly string $status,
		public readonly ?string $signedDocumentRef = null,
		public readonly ?string $signedAt = null,
	) {
	}//end __construct()
}//end class
