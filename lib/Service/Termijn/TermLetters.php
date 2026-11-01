<?php

/**
 * Dossiq term letters.
 *
 * The eight messages a term notification can be: the acknowledgement, the
 * extension, the ingebrekestelling receipt, the dwangsom payment, the request
 * for missing information and its reminder, the doorzending and the
 * niet-ontvankelijk. Each is rendered into a subject and a body over the term
 * instance and whatever context the caller resolved.
 *
 * 🔴 NOT RUN THROUGH `IL10N`. `IL10N` serves the interface language of the
 * signed-in reader, and the reader here is a citizen with no Nextcloud
 * session. The declared language on the context is the one that decides.
 *
 * Split out of {@see \OCA\Dossiq\Service\TermijnNotificationService}, which
 * was over its complexity ceiling. Writing the letter and dispatching it are
 * two jobs, and this class does the first. It has no collaborators, which is
 * what lets a test assert on the wording without wiring a job list and a
 * router to read a string.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use OCA\Dossiq\Service\Support\LetterDays;
use OCA\Dossiq\Service\Term\TermLetter;

/**
 * The wording of every term notification.
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
 */
class TermLetters {

	/**
	 * Render a template (nl) into a payload with subject + body.
	 *
	 * @param string $type Template type.
	 * @param array<string, mixed> $instance TermijnInstance (may be empty).
	 * @param array<string, mixed> $context Extra context.
	 *
	 * @return array{subject:string, body:string, locale:string}
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-a-requester-letter-names-the-case-by-its-number
	 */
	public function render(string $type, array $instance, array $context): array {
		$locale = (string)($context['locale'] ?? 'nl');
		// 🔴 THE TERM'S CASE LINK IS A UUID, NOT A KENMERK. A term instance points
		// at its case by id, so reading it first put "Ontvangstbevestiging zaak
		// cecc92cc-..." in a resident's inbox while the case carried 2026-0117.
		// The caller's `case` is the number read from the case itself; the term's
		// link is the fallback for a caller that knows nothing else. Written
		// without branches on purpose: this class sits at its complexity limit.
		$named = array_values(
			array_filter(
				[trim((string)($context['case'] ?? '')), trim((string)($instance['case'] ?? ''))],
				static fn (string $value): bool => ($value !== '')
			)
		);
		$case = ($named[0] ?? '–');
		$days = new LetterDays(language: $locale);
		$end = $days->shown(value: (string)($instance['endDateCurrent'] ?? ($context['endDate'] ?? '–')));

		$letter = new TermLetter(reference: $case, end: $end, locale: $locale, context: $context);
		$writers = $this->letterWriters();
		$rendered = ['subject' => '', 'body' => ''];
		if (isset($writers[$type]) === true) {
			$rendered = $writers[$type]($letter);
		}

		return ['subject' => $rendered['subject'], 'body' => $rendered['body'], 'locale' => $locale];
	}//end render()

	/**
	 * What writes each letter type; a type not listed renders empty.
	 *
	 * @return array<string, callable(TermLetter): array{subject: string, body: string}> The writers, by type.
	 */
	private function letterWriters(): array {
		return [
			'ontvangstbevestiging' => fn (TermLetter $letter): array => $this->acknowledgement(letter: $letter),
			'extension' => fn (TermLetter $letter): array => $this->extension(letter: $letter),
			'ingebrekestelling-receipt' => fn (TermLetter $letter): array => $this->noticeOfDefaultReceipt(letter: $letter),
			'hersteltermijn-request' => fn (TermLetter $letter): array => $this->informationRequest(letter: $letter),
			'hersteltermijn-reminder' => fn (TermLetter $letter): array => $this->informationReminder(letter: $letter),
			'doorzending' => fn (TermLetter $letter): array => $this->doorzending(letter: $letter),
			'niet-ontvankelijk' => fn (TermLetter $letter): array => $this->inadmissible(letter: $letter),
			'dwangsom-payment' => fn (TermLetter $letter): array => $this->penaltyPayment(letter: $letter),
		];
	}//end letterWriters()

	/**
	 * The receipt of a notice of default, Awb 4:17.
	 *
	 * This letter's wording exists in Dutch only, so its date is Dutch
	 * whatever the case type declared: an English date in a Dutch body
	 * reads as a mistake, not as a courtesy.
	 *
	 * @param TermLetter $letter The case and the render context: `graceEnd`.
	 *
	 * @return array{subject: string, body: string}
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
	 */
	private function noticeOfDefaultReceipt(TermLetter $letter): array {
		$graceEnd = (new LetterDays(language: 'nl'))->shown(value: (string)($letter->context['graceEnd'] ?? '–'));

		return [
			'subject' => 'Bevestiging ingebrekestelling zaak ' . $letter->reference,
			'body' => "Beste aanvrager,\n\n"
				. 'Wij hebben uw ingebrekestelling voor zaak ' . $letter->reference . " ontvangen.\n"
				. 'De wettelijke begunstigingstermijn (AWB 4:17) eindigt op ' . $graceEnd . ".\n"
				. 'Indien er voor dat moment een beschikking is afgegeven, vervalt de dwangsom.',
		];
	}//end noticeOfDefaultReceipt()

	/**
	 * The payment of a penalty for a late decision.
	 *
	 * @param TermLetter $letter The case and the render context: `bedragCents`, `betalingsreferentie`.
	 *
	 * @return array{subject: string, body: string}
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
	 */
	private function penaltyPayment(TermLetter $letter): array {
		$amountCents = (int)($letter->context['bedragCents'] ?? 0);
		$amountEur = number_format($amountCents / 100, 2, ',', '.');
		$ref = (string)($letter->context['betalingsreferentie'] ?? '–');

		return [
			'subject' => 'Uitbetaling dwangsom zaak ' . $letter->reference,
			'body' => "Beste aanvrager,\n\n"
				. 'De dwangsom van EUR ' . $amountEur . ' voor zaak ' . $letter->reference . " is overgemaakt.\n"
				. 'Onder betalingsreferentie ' . $ref . '.',
		];
	}//end penaltyPayment()

	/**
	 * The extension of the term, Woo art. 4.4 lid 2.
	 *
	 * It names the new end date and the reason, because the law asks that the
	 * requester is told why, not only that the term moved (REQ-WRN-005).
	 *
	 * @param TermLetter $letter The case, the end date, the language and the render context.
	 *
	 * @return array{subject: string, body: string}
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-an-extension-reaches-the-requester-with-its-reason-req-wrn-005
	 */
	private function extension(TermLetter $letter): array {
		// Dutch wording only, so a Dutch date, as in the ingebrekestelling receipt.
		$newEnd = (new LetterDays(language: 'nl'))->shown(value: (string)($letter->context['newEinddatum'] ?? $letter->end));
		$body = "Beste aanvrager,\n\n"
			. 'De termijn voor zaak ' . $letter->reference . ' is verlengd. De nieuwe deadline is ' . $newEnd . ".\n";

		$reason = rtrim(trim((string)($letter->context['reason'] ?? '')), '.');
		if ($reason !== '') {
			$body .= 'De reden: ' . $reason . ".\n";
		}

		$body .= 'U vindt de officiele verlengingsbrief in uw burgerportaal.';

		return ['subject' => 'Verlenging termijn zaak ' . $letter->reference, 'body' => $body];
	}//end extension()

	/**
	 * The request for missing information, Awb 4:5.
	 *
	 * It names what is missing and the date by which it has to arrive, because
	 * a request that says only "your application is incomplete" makes the
	 * applicant guess, and a guess is a second incomplete application.
	 *
	 * 🔴 NOT RUN THROUGH `IL10N`, for the same reason the acknowledgement is
	 * not: `IL10N` serves the interface language of the signed-in reader, and
	 * the reader here is a citizen with no Nextcloud session.
	 *
	 * @param TermLetter $letter The case, the end date, the language and the render context.
	 *
	 * @return array{subject:string, body:string} The rendered message.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-pause-extension/spec.md
	 */
	private function informationRequest(TermLetter $letter): array {
		$items = array_values(array_filter(array_map('strval', (array)($letter->context['items'] ?? []))));
		$english = ($letter->locale === 'en');
		$due = (new LetterDays(language: $letter->locale))->shown(value: (string)($letter->context['pauseDeadline'] ?? '-'));

		$list = '';
		foreach ($items as $item) {
			$list .= '- ' . $item . "\n";
		}//end foreach

		if ($english === true) {
			return [
				'subject' => 'We need something more for case ' . $letter->reference,
				'body' => "Dear applicant,\n\n"
					. 'We cannot decide on case ' . $letter->reference . " yet. Please send us:\n"
					. $list
					. "\nWe need this by " . $due . ".\n"
					. 'The term for your case is paused until your answer arrives (Awb 4:5).',
			];
		}

		return [
			'subject' => 'Wij hebben nog iets nodig voor zaak ' . $letter->reference,
			'body' => "Beste aanvrager,\n\n"
				. 'Wij kunnen nog niet beslissen over zaak ' . $letter->reference . ". Stuur ons:\n"
				. $list
				. "\nWij ontvangen dit graag voor " . $due . ".\n"
				. 'De termijn van uw zaak staat stil tot uw antwoord binnen is (Awb 4:5).',
		];
	}//end informationRequest()

	/**
	 * The reminder that the request is still open, Awb 4:5.
	 *
	 * IT IS A REMINDER, NOT A SECOND REQUEST. The applicant already got the
	 * list and the date; repeating the whole letter reads as a new demand and
	 * makes people send everything twice. So it names the case, the day, and
	 * the words the case type declared for this reason, and nothing else.
	 *
	 * 🔴 NOT RUN THROUGH `IL10N`, for the reason the request above is not: the
	 * reader is a citizen with no Nextcloud session, and the language is the
	 * one the case type declares.
	 *
	 * @param TermLetter $letter The case, the end date, the language and the render context.
	 *
	 * @return array{subject:string, body:string} The rendered message.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	private function informationReminder(TermLetter $letter): array {
		$english = ($letter->locale === 'en');
		$due = (new LetterDays(language: $letter->locale))->shown(value: (string)($letter->context['pauseDeadline'] ?? '-'));
		$declared = trim((string)($letter->context['chaseText'] ?? ''));

		if ($english === true) {
			$body = "Dear applicant,\n\n"
				. 'We are still waiting for what we asked you for on case ' . $letter->reference . ".\n";
			if ($declared !== '') {
				$body .= $declared . "\n";
			}

			return [
				'subject' => 'A reminder about case ' . $letter->reference,
				'body' => $body
					. "\nWe need this by " . $due . ".\n"
					. 'The term for your case stays paused until your answer arrives (Awb 4:5).',
			];
		}

		$body = "Beste aanvrager,\n\n"
			. 'Wij wachten nog op wat wij u gevraagd hebben voor zaak ' . $letter->reference . ".\n";
		if ($declared !== '') {
			$body .= $declared . "\n";
		}

		return [
			'subject' => 'Herinnering over zaak ' . $letter->reference,
			'body' => $body
				. "\nWij ontvangen dit graag voor " . $due . ".\n"
				. 'De termijn van uw zaak staat stil tot uw antwoord binnen is (Awb 4:5).',
		];
	}//end informationReminder()

	/**
	 * The acknowledgement of receipt, Awb 4:3a.
	 *
	 * It names the kenmerk, what was received, the statutory term and the date
	 * it ends, how to follow the case and who to contact. A case type with no
	 * beslistermijn still confirms receipt and says that no statutory term
	 * applies, because "we have it" is the duty and the deadline is not.
	 *
	 * 🔴 THE TEXT IS NOT RUN THROUGH `IL10N`, AND THAT IS DELIBERATE. `IL10N`
	 * serves the INTERFACE language of the signed-in reader. The reader here is
	 * a citizen with no Nextcloud session, and the language is the one the case
	 * type declares. Translating against the handler's interface would mail a
	 * Dutch citizen in English because an administrator's browser was English.
	 * So the two languages the spec asks for are rendered from the declared
	 * locale, and the l10n files carry the interface strings this change adds.
	 *
	 * @param TermLetter $letter The case, the end date, the language and the render context.
	 *
	 * @return array{subject:string, body:string} The rendered message.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	private function acknowledgement(TermLetter $letter): array {
		if (($letter->context['contentWithheld'] ?? false) === true) {
			return $this->acknowledgementWaiting(english: $letter->isEnglish());
		}

		if ($letter->isEnglish() === true) {
			return $this->acknowledgementInEnglish(letter: $letter);
		}

		$termijn = "Op deze aanvraag geldt geen wettelijke beslistermijn.\n";
		if ($this->hasTerm(letter: $letter) === true) {
			$termijn = 'Wij nemen uiterlijk op ' . $letter->end . ' een besluit op uw aanvraag. '
				. "Hebben wij meer tijd nodig, dan laten wij u dat voor die datum weten.\n";
		}

		return [
			'subject' => 'Ontvangstbevestiging zaak ' . $letter->reference,
			'body' => "Beste aanvrager,\n\n"
				. 'Wij hebben uw aanvraag' . $this->phrase(prefix: ' over ', value: $letter->text(key: 'subject'))
				. ' ontvangen en geregistreerd onder kenmerk ' . $letter->reference . ".\n"
				. $this->termStartLines(context: $letter->context, locale: 'nl')
				. $termijn
				. "U volgt deze zaak via het burgerportaal.\n"
				. $this->sentence(prefix: 'Met vragen kunt u terecht bij ', value: $letter->text(key: 'contact'))
				. "\nMet vriendelijke groet",
		];
	}//end acknowledgement()

	/**
	 * Whether a statutory term applies.
	 *
	 * The end date IS the test for a statutory term. Reading a separate
	 * `hasTerm` flag would let a caller that knows the date but not the
	 * flag render "no statutory period applies" beside the date itself.
	 *
	 * @param TermLetter $letter The letter, for its end date.
	 *
	 * @return bool True when the letter has an end date.
	 */
	private function hasTerm(TermLetter $letter): bool {
		return $letter->end !== '' && $letter->end !== '–';
	}//end hasTerm()

	/**
	 * A value with the words that lead into it, or '' when there is no value.
	 *
	 * @param string $prefix The leading words.
	 * @param string $value  The value.
	 *
	 * @return string The phrase.
	 */
	private function phrase(string $prefix, string $value): string {
		if ($value === '') {
			return '';
		}

		return $prefix . $value;
	}//end phrase()

	/**
	 * A value as a sentence of its own, or '' when there is no value.
	 *
	 * @param string $prefix The words before the value.
	 * @param string $value  The value.
	 *
	 * @return string The sentence, ending in a newline.
	 */
	private function sentence(string $prefix, string $value): string {
		if ($value === '') {
			return '';
		}

		return $prefix . $value . ".\n";
	}//end sentence()

	/**
	 * When the request arrived, and when its clock started.
	 *
	 * 🔴 THE EXPLANATION APPEARS ONLY WHEN IT IS NEEDED (D-4). A request filed
	 * on a Tuesday morning gets three dates and no sentence, because there is
	 * nothing to explain: the clock started when they pressed send. A request
	 * filed on a Sunday gets the same three plus one sentence. An explanation
	 * on every confirmation teaches people to stop reading them, and then the
	 * one that mattered goes unread too.
	 *
	 * 🔴 AN UNSTAMPED CASE GETS NO LINES AT ALL, rather than a line saying the
	 * term starts today. The stamp is absent exactly when the working calendar
	 * could not be reached, and inventing a start there would put a date in
	 * front of a citizen that the term does not count from.
	 *
	 * @param array<string, mixed> $context The render context.
	 * @param string               $locale  The language these lines are written in.
	 *
	 * @return string The lines, ending in a newline, or an empty string.
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-the-intake-confirmation-says-when-the-clock-starts-req-term-041
	 */
	private function termStartLines(array $context, string $locale): string {
		$english = ($locale === 'en');
		$days = new LetterDays(language: $locale);
		$received = $days->day(value: (string)($context['receivedAt'] ?? ''));
		$starts = $days->day(value: (string)($context['termStartsAt'] ?? ''));
		if ($received === '' || $starts === '') {
			return '';
		}

		$outside = (($context['receivedOutsideWorkingHours'] ?? false) === true);

		if ($english === true) {
			$lines = 'We received it on ' . $received . ' and the decision period starts on ' . $starts . ".\n";
			if ($outside === true) {
				$lines .= "Your request arrived when we were closed, so the period starts on the first working day after it.\n";
			}

			return $lines;
		}

		$lines = 'Wij hebben uw aanvraag ontvangen op ' . $received . ' en de beslistermijn start op ' . $starts . ".\n";
		if ($outside === true) {
			$lines .= "Uw aanvraag kwam binnen buiten onze openingstijden, daarom start de termijn op de eerstvolgende werkdag.\n";
		}

		return $lines;
	}//end termStartLines()

	/**
	 * The doorzending, Awb 2:3.
	 *
	 * The applicant is told two things and no more: their case moved, and who
	 * has it now. What they must NOT be told is that they have to do anything,
	 * because under Awb 2:3 they do not: sending it on is our duty, not theirs.
	 * A message that reads like a rejection sends people to the counter with a
	 * complaint about a case that is being handled.
	 *
	 * It carries no deadline. The receiving party owns the term from here, and
	 * quoting the term we were counting would be a promise made on somebody
	 * else's behalf.
	 *
	 * @param TermLetter $letter The case, the end date, the language and the render context.
	 *
	 * @return array{subject:string, body:string} The rendered message.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-doorzending-tells-the-applicant-where-the-case-went-req-hand-03
	 */
	private function doorzending(TermLetter $letter): array {
		$destination = trim((string)($letter->context['destination'] ?? ''));
		if ($destination === '') {
			$destination = 'de behandelende afdeling';
		}

		if ($letter->locale === 'en') {
			return [
				'subject' => 'Your case ' . $letter->reference . ' has moved',
				'body' => "Dear applicant,\n\n"
					. 'We passed your case ' . $letter->reference . ' on to ' . $destination . ".\n"
					. "They handle it from here, and you do not need to send anything again.\n"
					. "You follow the case in the citizen portal.\n"
					. "\nKind regards",
			];
		}

		return [
			'subject' => 'Uw zaak ' . $letter->reference . ' is doorgestuurd',
			'body' => "Beste aanvrager,\n\n"
				. 'Wij hebben uw zaak ' . $letter->reference . ' doorgestuurd naar ' . $destination . ".\n"
				. "Zij behandelen de zaak verder. U hoeft niets opnieuw op te sturen.\n"
				. "U volgt de zaak via het burgerportaal.\n"
				. "\nMet vriendelijke groet",
		];
	}//end doorzending()

	/**
	 * The letter saying the aanvraag was not taken into consideration.
	 *
	 * 🔴 IT SAYS WHY AND IT SAYS WHAT NOW. A niet-ontvankelijkverklaring is a
	 * besluit, so the applicant may object to it. A letter that only announces
	 * the verdict leaves them with no idea that they can, which is how a
	 * bezwaartermijn runs out on somebody who would have used it.
	 *
	 * NOT RUN THROUGH `IL10N`, for the reason the acknowledgement records:
	 * `IL10N` serves the interface language of the signed-in reader, and the
	 * reader here is a citizen with no Nextcloud session.
	 *
	 * @param TermLetter $letter The case, the end date, the language and the render context.
	 *
	 * @return array{subject:string, body:string} The rendered message.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
	 */
	private function inadmissible(TermLetter $letter): array {
		$reason = rtrim(trim((string)($letter->context['reason'] ?? '')), '.');
		if ($reason === '') {
			$reason = 'Uw aanvraag voldoet niet aan de eisen om in behandeling te worden genomen.';
			if ($letter->locale === 'en') {
				$reason = 'Your application does not meet the conditions for us to consider it.';
			}
		}

		if ($letter->locale === 'en') {
			return [
				'subject' => 'We are not considering your application ' . $letter->reference,
				'body' => "Dear applicant,\n\n"
					. 'We are not taking your application ' . $letter->reference . " into consideration.\n"
					. $reason . "\n"
					. "You can object to this decision. The letter says where and within how long.\n"
					. "\nKind regards",
			];
		}

		return [
			'subject' => 'Uw aanvraag ' . $letter->reference . ' is niet-ontvankelijk',
			'body' => "Beste aanvrager,\n\n"
				. 'Wij nemen uw aanvraag ' . $letter->reference . " niet in behandeling.\n"
				. $reason . "\n"
				. "U kunt bezwaar maken tegen dit besluit. In de brief staat waar en binnen welke termijn.\n"
				. "\nMet vriendelijke groet",
		];
	}//end inadmissible()

	/**
	 * The acknowledgement in English.
	 *
	 * Split from its Dutch twin so neither has to be read through the other.
	 * They are two letters, not one letter with branches.
	 *
	 * @param TermLetter $letter The case, the end date, the language and the render context.
	 *
	 * @return array{subject:string, body:string} The rendered message.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	private function acknowledgementInEnglish(TermLetter $letter): array {
		$term = "No statutory decision period applies to this application.\n";
		if ($this->hasTerm(letter: $letter) === true) {
			$term = 'We decide on your application by ' . $letter->end . ' at the latest. '
				. "If we need more time, we tell you before that date.\n";
		}

		return [
			'subject' => 'Acknowledgement of receipt for case ' . $letter->reference,
			'body' => "Dear applicant,\n\n"
				. 'We have received your application' . $this->phrase(prefix: ' about ', value: $letter->text(key: 'subject'))
				. ' and registered it under reference ' . $letter->reference . ".\n"
				. $this->termStartLines(context: $letter->context, locale: $letter->locale)
				. $term
				. "You can follow this case in the citizen portal.\n"
				. $this->sentence(prefix: 'Questions go to ', value: $letter->text(key: 'contact'))
				. "\nKind regards",
		];
	}//end acknowledgementInEnglish()

	/**
	 * The acknowledgement that carries no case content.
	 *
	 * The Berichtenbox pattern: say a message is waiting and keep the content
	 * where it is. This is not a second template. It is the same message with
	 * its content withheld, decided once per case type rather than per message,
	 * because a municipality that rules personal data out of e-mail rules it out
	 * everywhere.
	 *
	 * @param boolean $english Whether the case type declares English.
	 *
	 * @return array{subject:string, body:string} The rendered message.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	private function acknowledgementWaiting(bool $english): array {
		if ($english === true) {
			return [
				'subject' => 'A message about your application is waiting for you',
				'body' => "Dear applicant,\n\n"
					. "We have received your application and a message about it is waiting for you.\n"
					. "Sign in to the citizen portal to read it.\n\n"
					. 'Kind regards',
			];
		}

		return [
			'subject' => 'Er staat een bericht over uw aanvraag voor u klaar',
			'body' => "Beste aanvrager,\n\n"
				. "Wij hebben uw aanvraag ontvangen en er staat een bericht voor u klaar.\n"
				. "Log in op het burgerportaal om het te lezen.\n\n"
				. 'Met vriendelijke groet',
		];
	}//end acknowledgementWaiting()
}//end class
