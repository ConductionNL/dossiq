<?php

/**
 * Dossiq Woo request form
 *
 * What a Woo request must say before a case is opened for it, and the shape
 * the case keeps it in (`case.wooRequest`). Split from WooRequestIntake so the
 * checks on the form read on their own.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

/**
 * Validates a Woo request and reduces it to what the case keeps.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
 */
class WooRequestForm {

	/**
	 * The kinds of document a resident may ask for
	 * (site-woo-request-in-steps D1). The words they read are the action's
	 * field config; these are the values the case keeps.
	 */
	public const DOCUMENT_KINDS = ['besluiten', 'rapporten', 'correspondentie', 'alles'];

	/**
	 * Who a resident may say they are asking as. It is their own answer, not
	 * a judgement we make, and it changes nothing about how the request is
	 * handled.
	 */
	public const REQUESTER_KINDS = ['burger', 'journalist', 'organisatie'];

	/**
	 * The requester details, kept as given so a later change to the
	 * resident's profile does not rewrite the file.
	 */
	public const REQUESTER_FIELDS = ['verzoekerNaam', 'verzoekerEmail', 'verzoekerType'];

	/**
	 * Check the request and reduce it to what the case keeps.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array<string, string|array<int, string>> The `wooRequest` the case carries.
	 *
	 * @throws WooRequestRefused When it cannot be used.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
	 */
	public function normalise(array $request): array {
		$fields = [];
		foreach (
			[
				'subjectRef',
				'onderwerp',
				'omschrijving',
				'origin',
				'periodeVan',
				'periodeTot',
				'originReference',
				'collectionId',
				'toelichting',
				'verzoekerNaam',
				'verzoekerEmail',
				'verzoekerType',
			] as $key
		) {
			$fields[$key] = $this->text(value: ($request[$key] ?? null));
		}

		if ($fields['subjectRef'] === '') {
			throw new WooRequestRefused(WooRequestRefused::INVALID, 'The request names no resident.');
		}

		if ($fields['onderwerp'] === '') {
			throw new WooRequestRefused(WooRequestRefused::INVALID, 'Say what the request is about.');
		}

		$origin = $fields['origin'];
		if (in_array($origin, WooRequestIntake::ORIGINS, true) === false) {
			throw new WooRequestRefused(WooRequestRefused::INVALID, 'The origin must be portal or pipelinq.');
		}

		$from = $this->date(value: $fields['periodeVan']);
		$to = $this->date(value: $fields['periodeTot']);
		if ($from !== '' && $to !== '' && $from > $to) {
			throw new WooRequestRefused(WooRequestRefused::INVALID, 'The period starts after it ends.');
		}

		$request = [
			'onderwerp' => $fields['onderwerp'],
			'omschrijving' => $fields['omschrijving'],
			'periodeVan' => $from,
			'periodeTot' => $to,
			'origin' => $origin,
			'originReference' => $fields['originReference'],
			'collectionId' => $fields['collectionId'],
			'toelichting' => $fields['toelichting'],
			'verzoekerNaam' => $fields['verzoekerNaam'],
			'verzoekerEmail' => $this->email(value: $fields['verzoekerEmail']),
			'verzoekerType' => $this->oneOf(
				value: $fields['verzoekerType'],
				allowed: self::REQUESTER_KINDS,
				refusal: 'Say whether you are asking as a burger, journalist or organisatie.'
			),
			'documentSoorten' => $this->kinds(value: ($request['documentSoorten'] ?? null)),
		];

		// A date that was not given is left out, not written as '': the case
		// schema declares both periods with `format: date`, which refuses ''.
		return $this->withoutEmptyPeriods(request: $request);
	}//end normalise()

	/**
	 * The document kinds asked for, each one from the declared list.
	 *
	 * An answer that is not a list, or a list with nothing in it, is no
	 * answer: the key is left out rather than written as an empty array, the
	 * way the periods are.
	 *
	 * @param mixed $value What was sent.
	 *
	 * @return array<int, string> The kinds.
	 *
	 * @throws WooRequestRefused When a value is not one of the kinds.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-woo-request-keeps-what-kind-of-documents-and-which-requester-req-sws-010
	 */
	private function kinds(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$kinds = [];
		foreach ($value as $item) {
			$kind = $this->text(value: $item);
			if ($kind === '') {
				continue;
			}

			if (in_array($kind, self::DOCUMENT_KINDS, true) === false) {
				throw new WooRequestRefused(
					WooRequestRefused::INVALID,
					'A kind of document must be one of: ' . implode(', ', self::DOCUMENT_KINDS) . '.'
				);
			}

			if (in_array($kind, $kinds, true) === false) {
				$kinds[] = $kind;
			}
		}

		return $kinds;
	}//end kinds()

	/**
	 * A value from a declared list, or '' when none was given.
	 *
	 * @param string            $value   The value.
	 * @param array<int,string> $allowed The list.
	 * @param string            $refusal What to say when it is not in it.
	 *
	 * @return string The value.
	 *
	 * @throws WooRequestRefused When it is given and is not in the list.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-woo-request-keeps-what-kind-of-documents-and-which-requester-req-sws-010
	 */
	private function oneOf(string $value, array $allowed, string $refusal): string {
		if ($value === '' || in_array($value, $allowed, true) === true) {
			return $value;
		}

		throw new WooRequestRefused(WooRequestRefused::INVALID, $refusal);
	}//end oneOf()

	/**
	 * An e-mail address, or '' when none was given.
	 *
	 * @param string $value The value.
	 *
	 * @return string The address.
	 *
	 * @throws WooRequestRefused When it is given and is not an address.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-woo-request-keeps-what-kind-of-documents-and-which-requester-req-sws-010
	 */
	private function email(string $value): string {
		if ($value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
			return $value;
		}

		throw new WooRequestRefused(WooRequestRefused::INVALID, 'That is not an e-mail address.');
	}//end email()

	/**
	 * The request without the period dates that were not given.
	 *
	 * @param array<string, string|array<int, string>> $request The request.
	 *
	 * @return array<string, string|array<int, string>>
	 */
	private function withoutEmptyPeriods(array $request): array {
		foreach (['periodeVan', 'periodeTot'] as $key) {
			if ($request[$key] === '') {
				unset($request[$key]);
			}
		}

		// The same for every answer that was not given: pipelinq converts a
		// phone call with `onderwerp` alone, and a case should carry what was
		// said rather than a row of empty strings. `documentSoorten` goes too
		// when nothing was chosen, so "no answer" and "nothing selected" do
		// not become two states a reader has to tell apart.
		foreach (['toelichting', 'verzoekerNaam', 'verzoekerEmail', 'verzoekerType'] as $key) {
			if ($request[$key] === '') {
				unset($request[$key]);
			}
		}

		if ($request['documentSoorten'] === []) {
			unset($request['documentSoorten']);
		}

		return $request;
	}//end withoutEmptyPeriods()

	/**
	 * A scalar as trimmed text, anything else as ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()

	/**
	 * A date as `Y-m-d`, or '' when none was given.
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 *
	 * @throws WooRequestRefused When it is given and is not a date.
	 */
	private function date(string $value): string {
		if ($value === '') {
			return '';
		}

		$parts = [];
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1
			|| checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) === false
		) {
			throw new WooRequestRefused(WooRequestRefused::INVALID, 'A period date must be written as YYYY-MM-DD.');
		}

		return $value;
	}//end date()
}//end class
