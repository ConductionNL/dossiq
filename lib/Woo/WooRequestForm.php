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
 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

/**
 * Validates a Woo request and reduces it to what the case keeps.
 *
 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
 */
class WooRequestForm {

	/**
	 * Check the request and reduce it to what the case keeps.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array<string, string> The `wooRequest` the case carries.
	 *
	 * @throws WooRequestRefused When it cannot be used.
	 *
	 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
	 */
	public function normalise(array $request): array {
		$fields = [];
		foreach (['subjectRef', 'onderwerp', 'omschrijving', 'origin', 'periodeVan', 'periodeTot', 'originReference', 'collectionId'] as $key) {
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

		return [
			'onderwerp' => $fields['onderwerp'],
			'omschrijving' => $fields['omschrijving'],
			'periodeVan' => $from,
			'periodeTot' => $to,
			'origin' => $origin,
			'originReference' => $fields['originReference'],
			'collectionId' => $fields['collectionId'],
		];
	}//end normalise()

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
