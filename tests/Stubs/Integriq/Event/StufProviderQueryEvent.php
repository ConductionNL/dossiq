<?php

/**
 * Integriq StufProviderQueryEvent test stub: a verbatim copy of integriq's lib/Event/StufProviderQueryEvent.php
 * (change stuf-provider-receiver, decision 199), loaded by tests/bootstrap.php only
 * when the real class is absent.
 *
 * Integriq StUF Provider Query Event.
 *
 * The base of the four typed events integriq dispatches when a case system asks a
 * provider question (decision 199). The owning app answers in the synchronous slot;
 * the first answer wins. Silence is not an answer: the receiver turns it into a
 * StUF055 fault, never into an empty La01.
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/stuf-provider-through-integriq/specs/stuf-integration/spec.md#requirement-dossiq-answers-stuf-provider-questions-through-integriq
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * A provider question with a synchronous answer slot.
 *
 * @spec openspec/changes/stuf-provider-through-integriq/specs/stuf-integration/spec.md#requirement-dossiq-answers-stuf-provider-questions-through-integriq
 */
abstract class StufProviderQueryEvent extends Event {

	/**
	 * The app that answered, null while unanswered.
	 *
	 * @var string|null
	 */
	private ?string $answeredBy = null;

	/**
	 * The answer payload.
	 *
	 * @var array
	 */
	private array $answer = [];

	/**
	 * The refusal reason, null when not refused.
	 *
	 * @var string|null
	 */
	private ?string $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string $sourceId         The integriq StUF-ZKN source the request came in on.
	 * @param string $referentienummer The request's stuurgegevens referentienummer.
	 */
	public function __construct(
		private readonly string $sourceId,
		private readonly string $referentienummer,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The source the request came in on: an app answers only for sources it is configured with.
	 *
	 * @return string The source id.
	 *
	 * @spec openspec/changes/stuf-provider-through-integriq/specs/stuf-integration/spec.md#requirement-dossiq-answers-stuf-provider-questions-through-integriq
	 */
	public function getSourceId(): string {
		return $this->sourceId;

	}//end getSourceId()

	/**
	 * The request's referentienummer.
	 *
	 * @return string The referentienummer.
	 *
	 * @spec openspec/changes/stuf-provider-through-integriq/specs/stuf-integration/spec.md#requirement-dossiq-answers-stuf-provider-questions-through-integriq
	 */
	public function getReferentienummer(): string {
		return $this->referentienummer;

	}//end getReferentienummer()

	/**
	 * Refuse the request: the owning app looked and will not or cannot do it.
	 *
	 * @param string $appId  The answering app.
	 * @param string $reason A short reason, safe to return to the case system.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-provider-through-integriq/specs/stuf-integration/spec.md#requirement-dossiq-answers-stuf-provider-questions-through-integriq
	 */
	public function refuse(string $appId, string $reason): void {
		if ($this->answeredBy !== null) {
			return;
		}

		$this->answeredBy = $appId;
		$this->refusal    = $reason;
		$this->stopPropagation();

	}//end refuse()

	/**
	 * Whether an app answered or refused.
	 *
	 * @return bool True once answered.
	 *
	 * @spec openspec/changes/stuf-provider-through-integriq/specs/stuf-integration/spec.md#requirement-dossiq-answers-stuf-provider-questions-through-integriq
	 */
	public function isAnswered(): bool {
		return $this->answeredBy !== null;

	}//end isAnswered()

	/**
	 * The app that answered.
	 *
	 * @return string|null The app id, null while unanswered.
	 *
	 * @spec openspec/changes/stuf-provider-through-integriq/specs/stuf-integration/spec.md#requirement-dossiq-answers-stuf-provider-questions-through-integriq
	 */
	public function getAnsweredBy(): ?string {
		return $this->answeredBy;

	}//end getAnsweredBy()

	/**
	 * The refusal reason.
	 *
	 * @return string|null The reason, null when not refused.
	 *
	 * @spec openspec/changes/stuf-provider-through-integriq/specs/stuf-integration/spec.md#requirement-dossiq-answers-stuf-provider-questions-through-integriq
	 */
	public function getRefusal(): ?string {
		return $this->refusal;

	}//end getRefusal()

	/**
	 * The answer payload.
	 *
	 * @return array The payload, [] while unanswered or refused.
	 *
	 * @spec openspec/changes/stuf-provider-through-integriq/specs/stuf-integration/spec.md#requirement-dossiq-answers-stuf-provider-questions-through-integriq
	 */
	public function getAnswer(): array {
		return $this->answer;

	}//end getAnswer()

	/**
	 * Store an answer; the first answer wins.
	 *
	 * @param string $appId   The answering app.
	 * @param array  $payload The answer.
	 *
	 * @return void
	 */
	protected function setAnswer(string $appId, array $payload): void {
		if ($this->answeredBy !== null) {
			return;
		}

		$this->answeredBy = $appId;
		$this->answer     = $payload;
		$this->stopPropagation();

	}//end setAnswer()
}//end class
