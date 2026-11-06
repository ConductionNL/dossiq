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
	 * The words a resident reads for each requester kind, lowercased, to the
	 * value the case keeps. The portal shows these as the labels of the
	 * choices (CitizenManifest::WOO_OPTIONS).
	 */
	private const REQUESTER_WORDS = [
		'inwoner' => 'burger',
		'journalist' => 'journalist',
		'organisatie' => 'organisatie',
	];

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
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-dossiq-receives-a-woo-request-in-opencatalogis-shape-req-wto-001
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
				'verzoekerTelefoon',
				'verzoekerAdres',
			] as $key
		) {
			$fields[$key] = $this->text(value: ($request[$key] ?? null));
		}

		$origin = $fields['origin'];
		if (in_array($origin, WooRequestIntake::ORIGINS, true) === false) {
			throw new WooRequestRefused(WooRequestRefused::INVALID, 'The origin must be one of: ' . implode(', ', WooRequestIntake::ORIGINS) . '.');
		}

		// A PORTAL DOSSIER AND A PIPELINQ TICKET ALWAYS HAVE A RESIDENT; an
		// anonymous portal form and a request opencatalogi took in need not
		// (REQ-WTO-001). Those two are accepted on the requester's own name and
		// contact details, and the dossier rules of start() never see them.
		if ($fields['subjectRef'] === '' && in_array($origin, WooRequestIntake::SUBJECTLESS_ORIGINS, true) === false) {
			throw new WooRequestRefused(WooRequestRefused::INVALID, 'The request names no resident.');
		}

		if ($fields['onderwerp'] === '') {
			throw new WooRequestRefused(WooRequestRefused::INVALID, 'Say what the request is about.', 'onderwerp');
		}

		$from = $this->date(value: $fields['periodeVan'], field: 'periodeVan');
		$to = $this->date(value: $fields['periodeTot'], field: 'periodeTot');
		if ($from !== '' && $to !== '' && $from > $to) {
			throw new WooRequestRefused(WooRequestRefused::INVALID, 'The end date lies before the start date.', 'periodeTot');
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
			'verzoekerType' => $this->requesterKind(value: $fields['verzoekerType']),
			'verzoekerTelefoon' => $fields['verzoekerTelefoon'],
			'verzoekerAdres' => $fields['verzoekerAdres'],
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
					'Choose the kinds of document from the list.',
					'documentSoorten'
				);
			}

			if (in_array($kind, $kinds, true) === false) {
				$kinds[] = $kind;
			}
		}

		return $kinds;
	}//end kinds()

	/**
	 * Who the resident asks as, as the value the case keeps, or '' when none
	 * was given.
	 *
	 * The form offers the three as choices, so the stored value is what
	 * arrives. A caller that sends the word a resident reads ("Inwoner",
	 * "Journalist", "Organisatie", in any case) is read as that value too,
	 * because the resident meant the same thing.
	 *
	 * @param string $value The value.
	 *
	 * @return string The stored value.
	 *
	 * @throws WooRequestRefused When it is given and is none of the three.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-woo-request-reads-the-answers-a-resident-gives-req-wdi-001
	 */
	private function requesterKind(string $value): string {
		$kind = (self::REQUESTER_WORDS[mb_strtolower($value)] ?? mb_strtolower($value));
		if ($value === '' || in_array($kind, self::REQUESTER_KINDS, true) === true) {
			return $kind;
		}

		throw new WooRequestRefused(
			WooRequestRefused::INVALID,
			'Choose whether you ask as a resident, a journalist or an organisation.',
			'verzoekerType'
		);
	}//end requesterKind()

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

		throw new WooRequestRefused(WooRequestRefused::INVALID, 'Fill in an e-mail address, for example naam@voorbeeld.nl.', 'verzoekerEmail');
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
		foreach (['toelichting', 'verzoekerNaam', 'verzoekerEmail', 'verzoekerType', 'verzoekerTelefoon', 'verzoekerAdres'] as $key) {
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
	 * Read as the stored `jjjj-mm-dd` or as a resident writes it, day first:
	 * `14-10-2026`, `1-3-2026`, `1/3/2026` or `1.3.2026`. Whatever came in,
	 * the case keeps `jjjj-mm-dd`, the format its schema declares.
	 *
	 * @param string $value The value.
	 * @param string $field The field it was given in, for the refusal.
	 *
	 * @return string
	 *
	 * @throws WooRequestRefused When it is given and is not a real date.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-woo-request-reads-the-answers-a-resident-gives-req-wdi-001
	 */
	private function date(string $value, string $field): string {
		if ($value === '') {
			return '';
		}

		$parts = [];
		$year = 0;
		$month = 0;
		$day = 0;
		if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $parts) === 1) {
			[$year, $month, $day] = [(int)$parts[1], (int)$parts[2], (int)$parts[3]];
		} else if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $value, $parts) === 1) {
			[$day, $month, $year] = [(int)$parts[1], (int)$parts[2], (int)$parts[3]];
		}

		if ($year === 0 || checkdate($month, $day, $year) === false) {
			throw new WooRequestRefused(
				WooRequestRefused::INVALID,
				'Write the date as day-month-year, for example 1-3-2026.',
				$field
			);
		}

		return sprintf('%04d-%02d-%02d', $year, $month, $day);
	}//end date()
}//end class
