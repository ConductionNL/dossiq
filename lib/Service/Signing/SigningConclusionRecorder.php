<?php

/**
 * Dossiq Signing Conclusion Recorder
 *
 * Records on a decision how filinq concluded the signing request the decision
 * raised (see DocumentSigningRequester and SigningConcludedListener). Signed
 * moves the decision to signed; declined, cancelled and expired leave its
 * status and mark the request `not-concluded`, so the decision shows it and
 * the signature can be asked again.
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

use DateTimeImmutable;
use OCA\Dossiq\Service\Beschikking\BeschikkingRepository;
use OCA\Dossiq\Service\BeschikkingService;
use OCA\Dossiq\Service\StateMachineService;
use OCA\Dossiq\Service\Zaakdossier\RestrictedDocumentReaders;

/**
 * Records filinq's signing conclusion on the decision that asked for it.
 *
 * @spec openspec/changes/signing-through-filinq/specs/libresign-besluit-signing/spec.md#requirement-a-signing-request-that-ends-unsigned-is-shown-as-not-concluded
 */
class SigningConclusionRecorder {
	/**
	 * A signing request filinq concluded as signed.
	 */
	public const SIGNING_CONCLUDED = 'concluded';

	/**
	 * A signing request filinq concluded unsigned: declined, expired or cancelled.
	 */
	public const SIGNING_NOT_CONCLUDED = 'not-concluded';

	/**
	 * The signature kind recorded for each level filinq signed at.
	 */
	private const SIGNATURE_KINDS = [
		'SES' => 'eenvoudige-elektronische-handtekening',
		'AdES' => 'geavanceerde-elektronische-handtekening',
		'QES' => 'gekwalificeerde-elektronische-handtekening',
	];

	/**
	 * Constructor.
	 *
	 * @param BeschikkingRepository $repository Decision persistence.
	 * @param StateMachineService $stateMachine The state-machine guard and its log.
	 * @param RestrictedDocumentReaders|null $readers Gives a signed version its original's access exception.
	 */
	public function __construct(
		private readonly BeschikkingRepository $repository,
		private readonly StateMachineService $stateMachine,
		private readonly ?RestrictedDocumentReaders $readers = null,
	) {
	}//end __construct()

	/**
	 * Record how filinq concluded a signing request this decision raised.
	 *
	 * Signed moves the decision to signed. Declined, expired and cancelled mark
	 * the request `not-concluded` with filinq's status, leave the decision's
	 * status as it was, and let it be asked for again. A conclusion for a request
	 * the decision is not waiting on changes nothing.
	 *
	 * @param SigningConclusion $conclusion The decision, filinq's request id and how it ended.
	 *
	 * @return bool Whether the decision took the conclusion.
	 *
	 * @spec openspec/changes/signing-through-filinq/specs/libresign-besluit-signing/spec.md#requirement-a-signing-request-that-ends-unsigned-is-shown-as-not-concluded
	 */
	public function record(SigningConclusion $conclusion): bool {
		$decisionId        = $conclusion->decisionId;
		$requestId         = $conclusion->requestId;
		$status            = $conclusion->status;
		$signedDocumentRef = $conclusion->signedDocumentRef;
		$signedAt          = $conclusion->signedAt;

		$decision = $this->repository->find(decisionId: $decisionId);
		$signing = (array)($decision['signing'] ?? []);
		if ($decision === null
			|| (string)($signing['state'] ?? '') !== BeschikkingService::SIGNING_REQUESTED
			|| (string)($signing['requestId'] ?? '') !== $requestId
		) {
			return false;
		}

		$signing['concludedAt'] = (new DateTimeImmutable())->format('c');
		$signing['outcome'] = $status;
		$current = (string)($decision['currentStatus'] ?? '');
		if ($status !== 'signed' || $this->stateMachine->validateTransition($current, 'signed') === false) {
			$signing['state'] = self::SIGNING_NOT_CONCLUDED;
			$decision['signing'] = $signing;
			$this->repository->save(decision: $decision);

			return true;
		}

		$signing['state'] = self::SIGNING_CONCLUDED;
		$signatory = (string)($signing['signatory'] ?? '');
		$fileId = (string)($decision['compositeContent']['fileId'] ?? '');
		$decision['signing'] = $signing;
		$decision['signature'] = [
			'tspProvider' => (string)($signing['tspProvider'] ?? ''),
			'signatory' => $signatory,
			'signingMoment' => (string)($signedAt ?? $signing['concludedAt']),
			'kind' => self::SIGNATURE_KINDS[(string)($signing['level'] ?? '')] ?? self::SIGNATURE_KINDS['SES'],
			'level' => (string)($signing['level'] ?? ''),
			'signingRequestId' => $requestId,
		];
		$decision['compositeContent']['fileId'] = (string)($signedDocumentRef ?? $fileId);
		$decision['currentStatus'] = 'signed';

		$this->repository->save(decision: $decision);
		$this->inheritAccessException(originalId: $fileId, signedDocumentRef: $signedDocumentRef, signatory: $signatory);
		$this->stateMachine->logTransition(
			$decisionId,
			$current,
			'signed',
			[
				'actor' => $signatory,
				'actorType' => 'employee',
				'trigger' => 'signing-concluded',
				'evidenceMaterial' => [
					'kind' => 'signing-request',
					'signingRequestId' => $requestId,
				],
			],
		);

		return true;
	}//end record()

	/**
	 * The signed version of a document only some people may see is seen by the same people.
	 *
	 * @param string      $originalId        The document that was signed.
	 * @param string|null $signedDocumentRef The signed version filinq wrote, as a file id.
	 * @param string      $signatory         Who signed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-copy-of-a-document-carries-its-exception
	 */
	private function inheritAccessException(string $originalId, ?string $signedDocumentRef, string $signatory): void {
		if ($this->readers === null
			|| $originalId === ''
			|| $signedDocumentRef === null
			|| $signedDocumentRef === $originalId
			|| ctype_digit($signedDocumentRef) === false
		) {
			return;
		}

		$this->readers->inheritFromId(originalId: $originalId, fileId: (int)$signedDocumentRef, madeBy: $signatory);
	}//end inheritAccessException()
}//end class
