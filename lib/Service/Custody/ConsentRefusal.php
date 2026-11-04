<?php

/**
 * The refusal of a hand-off that no recorded consent covers, in the user's language.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Custody
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Custody;

use OCP\IL10N;

/**
 * Words the refusal of a hand-off: which rule refused it, and the sentence that says why.
 *
 * @phpstan-type Outcome array{allowed: bool, rule: string, sentence: string, consent: array<string, mixed>|null}
 * @psalm-type   Outcome = array{allowed: bool, rule: string, sentence: string, consent: array<string, mixed>|null}
 *
 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
 */
class ConsentRefusal {

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n The localisation the sentences are worded in.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The refusal when the consents for this receiver do not cover the moment.
	 *
	 * @param array{covering: array<string, mixed>|null, lapsedOn: string, withdrawn: bool, none: bool} $scan     What the consents amount to.
	 * @param string                                                                                     $receiver The receiving organisation.
	 *
	 * @return Outcome The outcome.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	public function uncovered(array $scan, string $receiver): array {
		if ($scan['none'] === true) {
			$sentence = $this->l10n->t('There is no recorded consent for %1$s, so the case was not handed on.', [$receiver]);
			return $this->outcome(rule: CaseTransferConsentGate::NO_CONSENT, sentence: $sentence);
		}

		if ($scan['lapsedOn'] !== '' || $scan['withdrawn'] === true) {
			return $this->ended(scan: $scan, receiver: $receiver);
		}

		$sentence = $this->l10n->t('There is no recorded consent for %1$s covering today, so the case was not handed on.', [$receiver]);
		return $this->outcome(rule: CaseTransferConsentGate::NO_CONSENT, sentence: $sentence);
	}//end uncovered()

	/**
	 * The refusal when the consent for this receiver ran out or was withdrawn.
	 *
	 * @param array{lapsedOn: string, withdrawn: bool} $scan     What the consents amount to.
	 * @param string                                   $receiver The receiving organisation.
	 *
	 * @return Outcome The outcome.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	private function ended(array $scan, string $receiver): array {
		if ($scan['lapsedOn'] !== '') {
			$sentence = $this->l10n->t(
				'The consent for %1$s ran to %2$s and has ended, so the case was not handed on.',
				[$receiver, $scan['lapsedOn']]
			);
			return $this->outcome(rule: CaseTransferConsentGate::CONSENT_LAPSED, sentence: $sentence);
		}

		$sentence = $this->l10n->t('The consent for %1$s was withdrawn, so the case was not handed on.', [$receiver]);
		return $this->outcome(rule: CaseTransferConsentGate::CONSENT_WITHDRAWN, sentence: $sentence);
	}//end ended()

	/**
	 * The refusal when the consent rule of the case could not be read.
	 *
	 * @return Outcome The outcome.
	 *
	 * @spec openspec/changes/an-internal-move-whose-case-cannot-be-read-is-refused/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	public function unchecked(): array {
		$sentence = $this->l10n->t('Consent could not be checked, so the case was not handed on.');
		return $this->outcome(rule: CaseTransferConsentGate::CONSENT_UNCHECKED, sentence: $sentence);
	}//end unchecked()

	/**
	 * Shape one refusal.
	 *
	 * @param string $rule     The rule that refused it.
	 * @param string $sentence The sentence that says why.
	 *
	 * @return Outcome The outcome.
	 */
	private function outcome(string $rule, string $sentence): array {
		return ['allowed' => false, 'rule' => $rule, 'sentence' => $sentence, 'consent' => null];
	}//end outcome()
}//end class
