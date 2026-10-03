<?php

/**
 * A case whose work happens in another application.
 *
 * PinkRoccade's claim is an architecture rather than a feature: the zaak lives
 * centrally and the work happens in the specialist application. A gemeente
 * runs burgerzaken, sociaal domein and belastingen in separate products, and
 * the teamleider still wants one list.
 *
 * 🔑 DOSSIQ'S HONEST VERSION IS A DECLARATION (D-6). The case says which
 * application holds it, what it is called there, and where to open it. It
 * keeps its type, its status, its terms and its parties, because that is what
 * the central list is for.
 *
 * 🔴 WHAT DOSSIQ MUST NOT DO IS PRETEND TO HOLD THE WORK. A case homed
 * elsewhere still offers its lifecycle acts in the menu today, and a handler
 * who takes one moves a status here that the specialist application will never
 * hear about. Two systems then disagree about the same case and neither knows
 * it. So the acts that perform work are published DISABLED, carrying the
 * application that does hold it.
 *
 * Keeping them in the list rather than hiding them is the point: an act that
 * vanished would read as a permission problem, and somebody would spend an
 * afternoon on the rights matrix. An act that is there and greyed with a
 * reason answers the question on the spot.
 *
 * The connector that keeps the two in step is integriq's
 * (`zgw-connectors-for-dossiq`), and the facade a specialist application reads
 * the case through is openregister's (`objecten-api-facade`). dossiq declares
 * and builds neither.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

/**
 * Reads a case's external-home declaration, and says what it means for an act.
 *
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */
class ExternalHome {

	/**
	 * The case field naming the application that holds the work.
	 *
	 * @var string
	 */
	public const APPLICATION = 'externalApplication';

	/**
	 * The case field naming the case in that application.
	 *
	 * @var string
	 */
	public const IDENTIFIER = 'externalIdentifier';

	/**
	 * The case field linking to it.
	 *
	 * @var string
	 */
	public const URL = 'externalUrl';

	/**
	 * Whether this case declares that the work happens elsewhere.
	 *
	 * 🔑 THE APPLICATION IS THE TEST, AND ONLY THE APPLICATION. An identifier
	 * or a link with no application names a system nobody can ask about, so a
	 * case carrying only those is NOT externally homed: it is a case with a
	 * stray reference on it, and disabling its lifecycle for that would strand
	 * the work here with no way to get it back.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return bool True when the case names an application.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-may-be-homed-in-another-application-req-hand-04
	 */
	public function isHomedElsewhere(array $case): bool {
		return ($this->applicationOf(case: $case) !== '');
	}//end isHomedElsewhere()

	/**
	 * The declaration on a case, or an empty array when there is none.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return array{application: string, identifier: string, url: string}|array{}
	 *         The declaration, or [].
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-may-be-homed-in-another-application-req-hand-04
	 */
	public function declarationOn(array $case): array {
		$application = $this->applicationOf(case: $case);
		if ($application === '') {
			return [];
		}

		return [
			'application' => $application,
			'identifier' => trim((string)($case[self::IDENTIFIER] ?? '')),
			'url' => trim((string)($case[self::URL] ?? '')),
		];
	}//end declarationOn()

	/**
	 * The sentence a disabled act carries, naming where the work is done.
	 *
	 * It names the application and, when the case carries one, the identifier
	 * there, because a handler about to phone the specialist team needs the
	 * number they will be asked for.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return string The sentence, '' when the case is handled here.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-may-be-homed-in-another-application-req-hand-04
	 */
	public function whereTheWorkIs(array $case): string {
		$declaration = $this->declarationOn(case: $case);
		if ($declaration === []) {
			return '';
		}

		if ($declaration['identifier'] !== '') {
			return 'This case is handled in ' . $declaration['application']
				. ', as ' . $declaration['identifier'] . '.';
		}

		return 'This case is handled in ' . $declaration['application'] . '.';
	}//end whereTheWorkIs()

	/**
	 * The application named on a case, trimmed.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return string The application, or ''.
	 */
	private function applicationOf(array $case): string {
		return trim((string)($case[self::APPLICATION] ?? ''));
	}//end applicationOf()
}//end class
