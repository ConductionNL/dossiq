<?php

/**
 * The colours the boards draw a status in, by status name.
 *
 * Every status dossiq ships names one of these; the boards draw received
 * blue, work in progress purple, waiting orange, a decision teal, done or
 * published green, refused or stranded red, and withdrawn or archived grey.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/case-types/spec.md#requirement-a-shipped-status-takes-the-colour-its-boards-draw
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The board colour of each shipped status name, and what each shipped row held before.
 *
 * @spec openspec/specs/case-types/spec.md#requirement-a-shipped-status-takes-the-colour-its-boards-draw
 */
final class StatusTypePalette {

	/**
	 * The colour per shipped status name.
	 *
	 * @var array<string, string>
	 */
	public const COLOURS = [
		'Aangemeld' => 'blue',
		'Aanvraag ontvangen' => 'blue',
		'Beroep ontvangen' => 'blue',
		'Melding ontvangen' => 'blue',
		'Ontvangen' => 'blue',
		'Ontvangst' => 'blue',
		'Received' => 'blue',
		'Voorbereiding' => 'blue',
		'Advies' => 'purple',
		'Ambtelijk advies' => 'purple',
		'Assessment' => 'purple',
		'Beoordelen documenten' => 'purple',
		'Beoordeling' => 'purple',
		'Beoordeling ontvankelijkheid' => 'purple',
		'Besluit in voorbereiding' => 'purple',
		'Bijstand loopt' => 'purple',
		'Constatering' => 'purple',
		'Evaluatie' => 'purple',
		'Hercontrole' => 'purple',
		'Hoorzitting afgerond' => 'purple',
		'Identiteit vastgesteld' => 'purple',
		'In behandeling' => 'purple',
		'In progress' => 'purple',
		'In uitvoering' => 'purple',
		'Indicatie' => 'purple',
		'Inhoudelijke toets' => 'purple',
		'Inschrijving' => 'purple',
		'Inspectie' => 'purple',
		'Inspectie fase 1' => 'purple',
		'Inspectie fase 2' => 'purple',
		'Inspectie fase 3' => 'purple',
		'Investigation' => 'purple',
		'Jeugdhulp loopt' => 'purple',
		'Onderzoek' => 'purple',
		'Ontvankelijkheidstoets' => 'purple',
		'Opvolging' => 'purple',
		'Overleg' => 'purple',
		'Parafering' => 'purple',
		'Plan van aanpak' => 'purple',
		'Rapport' => 'purple',
		'Re-integratie loopt' => 'purple',
		'Toetsing' => 'purple',
		'Toetsing afgerond' => 'purple',
		'Uitvoering' => 'purple',
		'Veiligheidstoets' => 'purple',
		'Verweerschrift in voorbereiding' => 'purple',
		'Voorstel opstellen' => 'purple',
		'Zitting afgerond' => 'purple',
		'Zoeken documenten' => 'purple',
		'Begunstigingstermijn' => 'orange',
		'Bij commissie' => 'orange',
		'Geagendeerd' => 'orange',
		'Gepland' => 'orange',
		'Gereed voor agendering' => 'orange',
		'Hoorzitting gepland' => 'orange',
		'Lakken / Anonimiseren' => 'orange',
		'Ter goedkeuring' => 'orange',
		'Vergadering' => 'orange',
		'Verlenging aangevraagd' => 'orange',
		'Verweerschrift ingediend' => 'orange',
		'Vooraankondiging' => 'orange',
		'Wacht op aanvulling' => 'orange',
		'Zienswijze' => 'orange',
		'Zitting gepland' => 'orange',
		'Advies uitgebracht' => 'teal',
		'Beslissing op bezwaar' => 'teal',
		'Besluit' => 'teal',
		'Besluit genomen' => 'teal',
		'Besluitvorming' => 'teal',
		'Decision' => 'teal',
		'Gunning' => 'teal',
		'Handhavingsbesluit' => 'teal',
		'Uitspraak ontvangen' => 'teal',
		'Afgehandeld' => 'green',
		'Afgerond' => 'green',
		'Akkoord' => 'green',
		'Bekendmaking' => 'green',
		'Beschikt' => 'green',
		'Betaald' => 'green',
		'Completed' => 'green',
		'Gegund' => 'green',
		'Publicatie' => 'green',
		'Publiceren' => 'green',
		'Schikking' => 'green',
		'Afgewezen' => 'red',
		'Gestrand' => 'red',
		'Invordering' => 'red',
		'Niet-ontvankelijk' => 'red',
		'Verbeuring' => 'red',
		'Beëindigd' => 'grey',
		'Gearchiveerd' => 'grey',
		'Ingetrokken' => 'grey',
	];

	/**
	 * The colour each shipped row held before this palette, by its slug or id.
	 * A stored row still holding it was never recoloured here.
	 *
	 * @var array<string, string>
	 */
	public const PREVIOUSLY_SHIPPED = [
		'bp-completed' => 'green',
		'bp-decision' => 'purple',
		'bp-inprogress' => 'orange',
		'bp-received' => 'blue',
		'complaint-completed' => 'green',
		'complaint-inprogress' => 'orange',
		'complaint-investigation' => 'orange',
		'complaint-received' => 'blue',
		'foi-completed' => 'green',
		'foi-decision' => 'purple',
		'foi-inprogress' => 'orange',
		'foi-received' => 'blue',
		'grant-assessment' => 'orange',
		'grant-completed' => 'green',
		'grant-decision' => 'purple',
		'grant-inprogress' => 'orange',
		'grant-received' => 'blue',
		'klacht-afgehandeld' => 'green',
		'klacht-onderzoek' => 'orange',
		'klacht-ontvangen' => 'blue',
		'melding-afgehandeld' => 'green',
		'melding-in-behandeling' => 'orange',
		'melding-ontvangen' => 'blue',
		'omgevingsvergunning-afgehandeld' => 'green',
		'omgevingsvergunning-besluitvorming' => 'purple',
		'omgevingsvergunning-in-behandeling' => 'orange',
		'omgevingsvergunning-ontvangen' => 'blue',
		'subsidieaanvraag-afgehandeld' => 'green',
		'subsidieaanvraag-beoordeling' => 'orange',
		'subsidieaanvraag-besluitvorming' => 'purple',
		'subsidieaanvraag-ontvangen' => 'blue',
		'woo-verzoek-afgehandeld' => 'grey',
		'woo-verzoek-beoordelen' => 'orange',
		'woo-verzoek-besluit' => 'purple',
		'woo-verzoek-lakken' => 'orange',
		'woo-verzoek-ontvangst' => 'blue',
		'woo-verzoek-ontvankelijkheid' => 'blue',
		'woo-verzoek-publicatie' => 'green',
		'woo-verzoek-zoeken' => 'orange',
	];

	/**
	 * The colour a shipped status should take now, or null to leave it.
	 *
	 * A row takes the board colour while it holds no colour, the schema's
	 * grey default, or the colour dossiq shipped it with; any other colour was
	 * chosen here and stays.
	 *
	 * @param array<string, mixed> $row The stored status type.
	 *
	 * @return string|null The colour to write, or null.
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-a-shipped-status-takes-the-colour-its-boards-draw
	 */
	public function recolour(array $row): ?string {
		$target = $this->boardColourOf(row: $row);
		$current = ($row['colour'] ?? null);
		if ($target === null || $current === $target) {
			return null;
		}

		if ($this->isUnchosen(colour: $current) === true || $this->wasShipped(row: $row, colour: $current) === true) {
			return $target;
		}

		return null;
	}//end recolour()

	/**
	 * The board colour for a row's status name, or null for a name the palette does not know.
	 *
	 * @param array<string, mixed> $row The stored status type.
	 *
	 * @return string|null The colour.
	 */
	private function boardColourOf(array $row): ?string {
		$name = ($row['name'] ?? null);
		if (is_string($name) === false) {
			return null;
		}

		return (self::COLOURS[$name] ?? null);
	}//end boardColourOf()

	/**
	 * Whether a colour is one nobody chose: none, empty, or the schema's grey default.
	 *
	 * @param mixed $colour The stored colour.
	 *
	 * @return bool True when it was never chosen.
	 */
	private function isUnchosen(mixed $colour): bool {
		return in_array($colour, [null, '', 'grey'], true);
	}//end isUnchosen()

	/**
	 * Whether a row still holds the colour dossiq shipped it with, matched by its slug or id.
	 *
	 * @param array<string, mixed> $row    The stored status type.
	 * @param mixed                $colour The stored colour.
	 *
	 * @return bool True when the colour is the shipped one.
	 */
	private function wasShipped(array $row, mixed $colour): bool {
		$self = ($row['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		foreach ([($self['slug'] ?? null), ($row['id'] ?? null), ($self['id'] ?? null)] as $key) {
			if (is_string($key) === true && (self::PREVIOUSLY_SHIPPED[$key] ?? null) === $colour) {
				return true;
			}
		}

		return false;
	}//end wasShipped()
}//end class
