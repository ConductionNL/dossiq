<?php

/**
 * Procest Subsidieregister Exporter.
 *
 * Builds the Wet open overheid (art. 3.3 lid 2 onder f) subsidieregister
 * feed (REQ-SUB-006): a structured, JSON-LD-annotated list of granted and
 * settled subsidies for publication on the gemeentewebsite. Individual
 * applicants (natuurlijke personen) are anonymised per the VNG AVG-richtlijn;
 * legal persons are listed by name. The feed-shaping logic is pure and
 * unit-tested; the data is supplied by the calling controller/service.
 *
 * @category Service
 * @package  OCA\Procest\Service\Subsidie
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Subsidie;

/**
 * Wet open overheid subsidieregister feed builder.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/subsidieverlening-keten/specs.md
 */
class SubsidieRegisterExporter {
	/**
	 * JSON-LD context for linked-data consumers.
	 */
	public const JSON_LD_CONTEXT = 'https://standaarden.overheid.nl/owms/terms/';

	/**
	 * Anonymise an applicant for the public feed (REQ-SUB-006). Legal
	 * persons (with a KvK reference) keep their name; natuurlijke personen
	 * are reduced to "Particulier".
	 *
	 * @param array<string, mixed> $aanvraag The application record.
	 *
	 * @return string The display name for the public register.
	 */
	public function publicOntvanger(array $aanvraag): string {
		$kvk = (string)($aanvraag['aanvragerKvkRef'] ?? '');
		if ($kvk !== '') {
			return (string)($aanvraag['aanvragerNaam'] ?? ('KvK ' . $kvk));
		}

		// No KvK -> treated as a natural person and anonymised.
		return 'Particulier';
	}//end publicOntvanger()

	/**
	 * Map one subsidy dossier into a feed entry (REQ-SUB-006).
	 *
	 * @param array<string, mixed> $aanvraag The application record.
	 * @param array<string, mixed> $regeling The regeling record.
	 * @param array<string, mixed> $beschikking The (latest) decision record.
	 *
	 * @return array<string, mixed> The feed entry.
	 */
	public function toFeedEntry(array $aanvraag, array $regeling, array $beschikking): array {
		$vastgesteld = (string)($beschikking['beschikkingtype'] ?? '') === 'vaststellingsbeschikking';
		$status = 'verleend';
		if ($vastgesteld === true) {
			$status = 'vastgesteld';
		}

		return [
			'@type' => 'Subsidie',
			'regeling' => (string)($regeling['regelingNaam'] ?? ''),
			'ontvanger' => $this->publicOntvanger(aanvraag: $aanvraag),
			'bedrag' => (float)($beschikking['verleendBedrag'] ?? 0),
			'looptijd' => [
				'start' => (string)($beschikking['looptijdStart'] ?? ''),
				'eind' => (string)($beschikking['looptijdEind'] ?? ''),
			],
			'doel' => (string)($regeling['doelgroep'] ?? ''),
			'status' => $status,
			'grondslag' => (string)($beschikking['legalBasis'] ?? ''),
		];
	}//end toFeedEntry()

	/**
	 * Build a complete, paginated JSON-LD feed document (REQ-SUB-006).
	 *
	 * @param array<int, array<string, mixed>> $entries The pre-built feed entries.
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 *
	 * @return array<string, mixed> The feed document.
	 */
	public function buildFeed(array $entries, int $limit = 100, int $offset = 0): array {
		$limit = max(1, $limit);
		$offset = max(0, $offset);
		$total = count($entries);
		$page = array_slice($entries, $offset, $limit);

		return [
			'@context' => self::JSON_LD_CONTEXT,
			'@type' => 'Subsidieregister',
			'total' => $total,
			'limit' => $limit,
			'offset' => $offset,
			'results' => array_values($page),
		];
	}//end buildFeed()
}//end class
