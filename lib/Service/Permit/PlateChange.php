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

namespace OCA\Dossiq\Service\Permit;

/**
 * A checked plate change: the request, the permit it is about and the case type that takes it.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
final class PlateChange {
	/**
	 * Constructor.
	 *
	 * @param PlateChangeRequest   $request    The resident's request.
	 * @param array<string, mixed> $permit     The permit, held by the resident.
	 * @param array<string, mixed> $changeType The change case type.
	 */
	public function __construct(
		public readonly PlateChangeRequest $request,
		public readonly array $permit,
		public readonly array $changeType,
	) {
	}//end __construct()

	/**
	 * The change case type's id.
	 *
	 * @return string The id, or ''.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function changeTypeId(): string {
		return (string)($this->changeType['id'] ?? '');
	}//end changeTypeId()

	/**
	 * The case to write, before its answers and status.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function draft(): array {
		$request = $this->request;

		return [
			'title' => 'Kenteken wijzigen: ' . (string)($this->permit['title'] ?? ''),
			// The handler reads the request even when the case type declares
			// no property for it.
			'description' => 'Nieuw kenteken ' . $request->plate . ' voor vergunning ' . $request->permitId . '.',
			'caseType' => $this->changeTypeId(),
			'startDate' => date('Y-m-d'),
			'portalSubject' => $request->subjectRef,
			'portalParty' => 'subject:' . $request->subjectRef,
			'intakeChannel' => 'website',
		];
	}//end draft()

	/**
	 * The answer per property name the change case carries.
	 *
	 * @return array<string, string> The permit and the new plate.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function answers(): array {
		return ['permit' => $this->request->permitId, 'nieuwKenteken' => $this->request->plate];
	}//end answers()

	/**
	 * The status the change type opens in.
	 *
	 * @return string The status, or '' when it names none.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function initialStatus(): string {
		return trim((string)($this->changeType['initialStatus'] ?? ''));
	}//end initialStatus()
}//end class
