<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Pipelinq;

/**
 * Which language to write to a party in, and why that one.
 *
 * 🔴 THE RESOLVER ANSWERS, NOT THE PROPERTY. A caller that reads
 * `correspondenceLanguage` off a record has to reimplement three rules, and it
 * will get the unset case wrong: a party who chose Dutch and a party who never
 * said anything both end up as `nl`, and only one of those is a choice. The
 * resolver answers the tag AND the rule that produced it, and this is the only
 * class in dossiq that asks.
 *
 * 🔴 UNSET IS RENDERED AS UNSET. "No preference recorded, nl would be used" is
 * a different sentence from "this party asked for Dutch", and on a letter it is
 * the difference between a decision and an assumption.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-the-language-to-write-to-a-party-in-comes-from-the-resolver-with-its-reason-req-plq-06
 */
class CorrespondenceLanguageConsumer {

	/**
	 * What dossiq writes in when nobody can say otherwise.
	 *
	 * The instance's own locale is not consulted here: pipelinq's resolver
	 * already falls back to it, and a second fallback in dossiq would answer
	 * differently from the resolver on the same party.
	 */
	public const FALLBACK = 'nl';

	/**
	 * @param PipelinqGateway $gateway The only seam that names pipelinq.
	 */
	public function __construct(
		private readonly PipelinqGateway $gateway,
	) {
	}//end __construct()

	/**
	 * The language to write to one party in.
	 *
	 * @param string $partyId The party.
	 *
	 * @return array{available: bool, language: string, rule: string, stated: bool, sentence: string}
	 *   The tag, the rule that produced it, whether the party actually stated
	 *   one, and the sentence a surface renders.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-the-language-to-write-to-a-party-in-comes-from-the-resolver-with-its-reason-req-plq-06
	 */
	public function forParty(string $partyId): array {
		$answer = $this->gateway->ask(
			class: PipelinqGateway::CORRESPONDENCE_LANGUAGE,
			method: 'resolve',
			arguments: ['partyId' => trim($partyId)],
			fallback: null,
		);

		if ($answer['answered'] === false || is_array($answer['value']) === false) {
			return [
				'available' => false,
				'language' => self::FALLBACK,
				'rule' => 'unavailable',
				'stated' => false,
				'sentence' => 'No preference can be read on this instance, so ' . self::FALLBACK . ' is used.',
			];
		}

		$resolved = $answer['value'];
		if ((int)($resolved['status'] ?? 200) !== 200) {
			return [
				'available' => false,
				'language' => self::FALLBACK,
				'rule' => 'unavailable',
				'stated' => false,
				'sentence' => 'This party\'s language could not be read.',
			];
		}

		$language = trim((string)($resolved['language'] ?? self::FALLBACK));
		$rule = trim((string)($resolved['rule'] ?? ''));
		$stated = (trim((string)($resolved['partyPreference'] ?? '')) !== '');

		return [
			'available' => true,
			'language' => $language,
			'rule' => $rule,
			'stated' => $stated,
			'sentence' => $this->sentenceFor(language: $language, rule: $rule, stated: $stated),
		];
	}//end forParty()

	/**
	 * What a surface says about the language it is going to write in.
	 *
	 * @param string $language The tag.
	 * @param string $rule The rule that produced it.
	 * @param bool $stated Whether the party stated a preference.
	 *
	 * @return string The sentence.
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-the-language-to-write-to-a-party-in-comes-from-the-resolver-with-its-reason-req-plq-06
	 */
	public function sentenceFor(string $language, string $rule, bool $stated): string {
		if ($stated === true) {
			return "This party asked to be written to in {$language}.";
		}

		if ($rule === 'instanceDefault') {
			return "No preference is recorded, so {$language} is used: it is this instance's default.";
		}

		if ($rule === 'fallback') {
			return "No preference is recorded and this instance sets no default, so {$language} is used.";
		}

		return "No preference is recorded, so {$language} is used.";
	}//end sentenceFor()
}//end class
