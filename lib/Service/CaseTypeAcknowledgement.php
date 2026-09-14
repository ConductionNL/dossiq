<?php

/**
 * Dossiq case-type acknowledgement declaration.
 *
 * Reads the `acknowledgement` and `notificationMoments` declarations off a case
 * type and answers the four questions the Awb 4:3a trigger asks: does this case
 * owe an acknowledgement, through which channel, in which language, and with
 * its content withheld or not.
 *
 * It is a declaration reader and nothing else. It holds no OpenRegister handle,
 * makes no write and sends nothing, so the rule "which cases owe one" can be
 * tested without a store. The sending lives in
 * {@see \OCA\Dossiq\Service\AcknowledgementService}.
 *
 * WHY A DECLARATION AND NOT A WORKFLOW STEP. Request Tracker declares the
 * autoreply once, as a scrip on create, and Freescout passes the same
 * requirement with no phases at all. Both prove the acknowledgement is a
 * property of receiving something rather than a step in a process. Drawn into a
 * workflow, every case type has to remember it, and a case type that forgets
 * breaks a statutory duty with nothing to say so.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * What a case type says about confirming receipt.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 */
class CaseTypeAcknowledgement {

	/**
	 * The intake channels that count as an electronic submission.
	 *
	 * Awb 4:3a is about electronically submitted messages, so these are the
	 * values of `case.intakeChannel` that owe an acknowledgement by default:
	 * mail, the portal and an API intake. `manual`, `balie`, `phone`, `post`
	 * and `other` are not electronic submissions and owe nothing unless a case
	 * type names them, because mailing somebody about a conversation they just
	 * had at the desk is worse than not mailing them.
	 *
	 * @var array<int, string>
	 */
	public const ELECTRONIC_CHANNELS = ['email', 'website', 'zgw-api'];

	/**
	 * The moment the statutory acknowledgement is declared at.
	 *
	 * @var string
	 */
	public const MOMENT_RECEIVED = 'case-received';

	/**
	 * The template the acknowledgement is rendered from.
	 *
	 * @var string
	 */
	public const TEMPLATE = 'ontvangstbevestiging';

	/**
	 * The declaration a case type that declares nothing is read as.
	 *
	 * The default is ON. A case type that says nothing still owes the duty,
	 * because the law does not wait for a configuration.
	 *
	 * @var array<string, mixed>
	 */
	public const DEFAULTS = [
		'enabled' => true,
		'statutory' => true,
		'intakeChannels' => self::ELECTRONIC_CHANNELS,
		'defaultChannel' => 'email',
		'contentOnPlatform' => false,
		'language' => 'nl',
	];

	/**
	 * The declaration this case type carries, with the defaults filled in.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<string, mixed> The declaration, never empty.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function declarationFor(array $caseType): array {
		$declared = ($caseType['acknowledgement'] ?? null);
		if (is_array($declared) === false) {
			$declared = [];
		}

		$declaration = self::DEFAULTS;

		foreach (array_keys(self::DEFAULTS) as $key) {
			if (array_key_exists($key, $declared) === false || $declared[$key] === null) {
				continue;
			}

			$declaration[$key] = $declared[$key];
		}

		$declaration['enabled'] = ($declaration['enabled'] !== false);
		$declaration['statutory'] = ($declaration['statutory'] !== false);
		$declaration['contentOnPlatform'] = ($declaration['contentOnPlatform'] === true);
		$declaration['intakeChannels'] = $this->channelList(value: $declaration['intakeChannels']);
		$declaration['defaultChannel'] = trim((string)$declaration['defaultChannel']);
		$declaration['language'] = (trim((string)$declaration['language']) ?: 'nl');

		if ($declaration['defaultChannel'] === '') {
			$declaration['defaultChannel'] = (string)self::DEFAULTS['defaultChannel'];
		}

		return $declaration;
	}//end declarationFor()

	/**
	 * Does this case owe its sender an acknowledgement of receipt.
	 *
	 * @param array<string, mixed> $case     The created case.
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return boolean TRUE when the duty applies to this case.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function owesAcknowledgement(array $case, array $caseType): bool {
		$declaration = $this->declarationFor(caseType: $caseType);
		if ($declaration['enabled'] === false) {
			return false;
		}

		$channel = trim((string)($case['intakeChannel'] ?? ''));
		if ($channel === '') {
			// An unnamed intake channel is not evidence of an electronic
			// submission. Sending anyway would mail every hand-typed case, and
			// the schema's own default for this field is `manual`.
			return false;
		}

		return in_array($channel, $declaration['intakeChannels'], true);
	}//end owesAcknowledgement()

	/**
	 * Whether the content stays on the platform for this case type.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return boolean TRUE when the message says one is waiting and carries no case content.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function contentStaysOnPlatform(array $caseType): bool {
		return ($this->declarationFor(caseType: $caseType)['contentOnPlatform'] === true);
	}//end contentStaysOnPlatform()

	/**
	 * The channel this acknowledgement goes out through.
	 *
	 * The citizen's own recorded choice wins where there is one; the case
	 * type's default applies otherwise. dossiq holds neither the preference
	 * store nor the routing, so `case.communicationChannel` is read as the
	 * recorded choice and nothing here invents a second one.
	 *
	 * @param array<string, mixed> $case     The created case.
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return string The channel slug.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function channelFor(array $case, array $caseType): string {
		$chosen = trim((string)($case['communicationChannel'] ?? ''));
		if ($chosen !== '') {
			return $chosen;
		}

		return (string)$this->declarationFor(caseType: $caseType)['defaultChannel'];
	}//end channelFor()

	/**
	 * The language the acknowledgement is written in.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return string A BCP 47 language tag; `nl` unless the case type declares another.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function languageFor(array $caseType): string {
		return (string)$this->declarationFor(caseType: $caseType)['language'];
	}//end languageFor()

	/**
	 * The moments at which this case type tells the applicant something.
	 *
	 * The statutory acknowledgement is the first entry and carries the flag, so
	 * it is one mechanism with one place to look rather than a special case
	 * beside a list. A case type that declares its own moments keeps them; the
	 * acknowledgement is prepended when its own entry is missing, because the
	 * duty does not depend on somebody having typed it.
	 *
	 * "We need something from you" stays a distinct moment from "your case
	 * moved": the first asks the applicant to act and the second does not, and
	 * one message for both is chasing rather than informing.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<int, array<string, mixed>> The moments, statutory entry first.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function momentsFor(array $caseType): array {
		$declaration = $this->declarationFor(caseType: $caseType);

		$moments = [];
		$declared = ($caseType['notificationMoments'] ?? null);
		if (is_array($declared) === true) {
			foreach ($declared as $entry) {
				if (is_array($entry) === false) {
					continue;
				}

				$moment = trim((string)($entry['moment'] ?? ''));
				if ($moment === '') {
					continue;
				}

				$moments[] = [
					'moment' => $moment,
					'template' => trim((string)($entry['template'] ?? '')),
					'statutory' => (($entry['statutory'] ?? false) === true),
					'enabled' => (($entry['enabled'] ?? true) !== false),
				];
			}
		}

		if ($this->namesTheStatutoryMoment(moments: $moments) === true) {
			return $moments;
		}

		array_unshift(
			$moments,
			[
				'moment' => self::MOMENT_RECEIVED,
				'template' => self::TEMPLATE,
				'statutory' => ($declaration['statutory'] === true),
				'enabled' => ($declaration['enabled'] === true),
			]
		);

		return $moments;
	}//end momentsFor()

	/**
	 * What publishing this case type should warn about, in sentences.
	 *
	 * A warning and not a refusal. A bestuursorgaan may have a case type that
	 * genuinely owes no acknowledgement, and refusing would make the duty
	 * unconfigurable; but switching it off silently is how a statutory duty
	 * disappears from an app that still reads green. So publication says so,
	 * out loud, and names the article.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<int, string> The warnings, empty when nothing is off.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function publicationWarnings(array $caseType): array {
		$declaration = $this->declarationFor(caseType: $caseType);
		$warnings = [];

		if ($declaration['enabled'] === false) {
			$warnings[] = 'This case type sends no acknowledgement of receipt. '
				. 'Awb 4:3a asks for one on every electronic submission.';
		}

		if ($declaration['enabled'] === true && $declaration['intakeChannels'] === []) {
			$warnings[] = 'This case type names no intake channel that owes an acknowledgement, '
				. 'so none is ever sent. Awb 4:3a asks for one on every electronic submission.';
		}

		if ($this->statutoryMomentWasRemoved(caseType: $caseType) === true) {
			$warnings[] = 'The statutory acknowledgement of receipt was removed from this case '
				. 'type\'s automatic messages. Awb 4:3a asks for one on every electronic submission.';
		}

		return $warnings;
	}//end publicationWarnings()

	/**
	 * Whether the declared moments already name the statutory one.
	 *
	 * @param array<int, array<string, mixed>> $moments The declared moments.
	 *
	 * @return boolean TRUE when an entry names the received moment.
	 */
	private function namesTheStatutoryMoment(array $moments): bool {
		foreach ($moments as $moment) {
			if (($moment['moment'] ?? '') === self::MOMENT_RECEIVED) {
				return true;
			}
		}

		return false;
	}//end namesTheStatutoryMoment()

	/**
	 * Whether a case type that declares moments left the statutory one out.
	 *
	 * A case type with no `notificationMoments` at all has not removed
	 * anything: it never declared a list, and `momentsFor()` gives it the
	 * acknowledgement. A case type that declares a list and omits the
	 * acknowledgement from it is the case this warns about.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return boolean TRUE when a declared list is missing the statutory entry.
	 */
	private function statutoryMomentWasRemoved(array $caseType): bool {
		$declared = ($caseType['notificationMoments'] ?? null);
		if (is_array($declared) === false || $declared === []) {
			return false;
		}

		foreach ($declared as $entry) {
			if (is_array($entry) === true && ($entry['moment'] ?? '') === self::MOMENT_RECEIVED) {
				return false;
			}
		}

		return true;
	}//end statutoryMomentWasRemoved()

	/**
	 * A declared channel list, normalised to strings.
	 *
	 * @param mixed $value Whatever the case type carried.
	 *
	 * @return array<int, string> The channels.
	 */
	private function channelList(mixed $value): array {
		if (is_array($value) === false) {
			return self::ELECTRONIC_CHANNELS;
		}

		$channels = [];
		foreach ($value as $channel) {
			$channel = trim((string)$channel);
			if ($channel !== '') {
				$channels[] = $channel;
			}
		}

		return $channels;
	}//end channelList()
}//end class
