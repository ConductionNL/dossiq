<?php

/**
 * Dossiq submission made once.
 *
 * Opens the case of a portal submission through the one intake path, and
 * makes it once: a resend with the key of a send that made a case gets that
 * case's answer back, one that comes while the first is still being made
 * hears so, and a send that made no case lets its key go so a corrected
 * resend is a first send (REQ-PSM-001). Split out of
 * {@see \OCA\Dossiq\Controller\PortalWooRequestController}, which keeps the
 * assertion check, the fields it forwards and the wording of a refusal.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\Contracts\PortalSubmissionLedgerInterface;
use OCA\Dossiq\Kernel\Contracts\WooRequestIntakeInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * One case per portal submission.
 *
 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class SubmissionMadeOnce {

	/**
	 * The ledger scope of the submission keys.
	 */
	private const SUBMISSION_SCOPE = 'woo-verzoek';

	/**
	 * Constructor.
	 *
	 * @param WooRequestIntakeInterface            $intake The one creation path.
	 * @param PortalSubmissionLedgerInterface|null $ledger Makes a submission once; none when null.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly WooRequestIntakeInterface $intake,
		private readonly ?PortalSubmissionLedgerInterface $ledger = null,
	) {
	}//end __construct()

	/**
	 * The submission key portaliq relayed, or '' when there is none or no ledger.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function key(IRequest $request): string {
		if ($this->ledger === null) {
			return '';
		}

		return $this->ledger->keyOf((string)$request->getHeader(PortalSubmissionLedgerInterface::HEADER));
	}//end key()

	/**
	 * The answer to a resend: the first send's case, or 409 while it is still
	 * being made; null when this send is the first with its key, or has none.
	 *
	 * @param string $subjectRef The resident.
	 * @param string $key        The key, or ''.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function replay(string $subjectRef, string $key): ?JSONResponse {
		if ($key === '' || $this->ledger === null) {
			return null;
		}

		$claim = $this->ledger->claim(scope: self::SUBMISSION_SCOPE, subjectRef: $subjectRef, key: $key);
		if ($claim['state'] === 'answered') {
			return new JSONResponse(($claim['answer'] ?? []), Http::STATUS_CREATED);
		}

		if ($claim['state'] === 'processing') {
			return new JSONResponse(['error' => 'submission_processing'], Http::STATUS_CONFLICT);
		}

		return null;
	}//end replay()

	/**
	 * Open the case and keep its answer for a resend; let the key go when nothing was made.
	 *
	 * Whatever the intake throws, a refusal included, is thrown on after the
	 * key is let go.
	 *
	 * @param array<string, mixed> $request What the intake reads.
	 * @param string               $key     The key, or ''.
	 *
	 * @return array<string, mixed> The intake's answer.
	 *
	 * @throws Throwable What the intake threw.
	 *
	 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function open(array $request, string $key): array {
		try {
			$answer = $this->intake->start($request);
		} catch (Throwable $failure) {
			$this->releaseKey(subjectRef: $request['subjectRef'], key: $key);
			throw $failure;
		}

		if ($key !== '' && $this->ledger !== null) {
			$this->ledger->remember(scope: self::SUBMISSION_SCOPE, subjectRef: $request['subjectRef'], key: $key, answer: $answer);
		}

		return $answer;
	}//end open()

	/**
	 * Let a key go after a send that made no case, so a corrected resend is a first send.
	 *
	 * @param string $subjectRef The resident.
	 * @param string $key        The key, or ''.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function releaseKey(string $subjectRef, string $key): void {
		if ($key !== '' && $this->ledger !== null) {
			$this->ledger->release(scope: self::SUBMISSION_SCOPE, subjectRef: $subjectRef, key: $key);
		}
	}//end releaseKey()
}//end class
