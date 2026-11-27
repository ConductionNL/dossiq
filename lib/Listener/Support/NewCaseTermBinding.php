<?php

/**
 * Dossiq new case term binding.
 *
 * Binds the statutory term and the other clocks (the planned end and the
 * internal target) to a case that was just received, and says out loud
 * when a case runs without a statutory term or a binding failed. Split out
 * of {@see \OCA\Dossiq\Listener\DeadlineCaseCreatedListener}, which keeps
 * telling a received case apart and reading who it is and what it is about.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/termijn-binding/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCA\Dossiq\Exception\NoTermijnDefinitieException;
use OCA\Dossiq\Kernel\Contracts\CaseTermsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\IntakeTermStartInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermResolutionInterface;
use OCA\Dossiq\Listener\CreatedCase;
use OCA\Dossiq\Listener\NewCaseIdentity;
use Psr\Log\LoggerInterface;

/**
 * The term and the other clocks of a newly received case.
 *
 * @spec openspec/specs/termijn-binding/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class NewCaseTermBinding {

	/**
	 * Constructor.
	 *
	 * @param TermijnServiceInterface        $termService Creates the statutory term instance.
	 * @param LoggerInterface                $logger      Says when a case runs without a term or a binding failed.
	 * @param CaseTermsServiceInterface|null $caseTerms   Binds the planned end and the internal target.
	 * @param TermResolutionInterface|null   $resolution  Picks which of a case type's terms applies.
	 * @param IntakeTermStartInterface|null  $intake      When the term starts: at receipt.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly TermijnServiceInterface $termService,
		private readonly LoggerInterface $logger,
		private readonly ?CaseTermsServiceInterface $caseTerms = null,
		private readonly ?TermResolutionInterface $resolution = null,
		private readonly ?IntakeTermStartInterface $intake = null,
	) {
	}//end __construct()

	/**
	 * Bind the statutory term and the other clocks to a new case.
	 *
	 * @param NewCaseIdentity      $identity The case id, case type reference and slug.
	 * @param array<string, mixed> $payload  The case payload.
	 * @param array<string, string> $context The organisation, service, priority and case type the term is resolved by.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/termijn-binding/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function bind(NewCaseIdentity $identity, array $payload, array $context): void {
		$caseType = $identity->caseType;
		$resolution = $this->resolution?->resolve(caseType: $caseType, context: $context);

		// The service norm, resolved by the same rule. A case type with no
		// statutory term (a melding, a demo type) still has a clock the team
		// works to, and it is bound beside the statutory term rather than as it.
		$plannedDefinition = $this->resolution?->plannedDefinitionFor(caseType: $caseType, context: $context);

		$created = new CreatedCase(identity: $identity, payload: $payload);
		try {
			// The term counts from receipt, not from the moment this row was
			// written (REQ-OTE-02). Null only when no intake reader is wired,
			// which is the old behaviour: the term starts now.
			$this->createTerm(created: $created, resolution: $resolution);
			$this->bindTheOtherClocks(created: $created, plannedDefinition: $plannedDefinition);
		} catch (NoTermijnDefinitieException $e) {
			// NOT debug. A case that matched no definition at all has no
			// statutory clock running, which is exactly the state that hid a
			// fleet-wide key mismatch behind a quiet log line. It is a valid
			// configuration for a case type with no beslistermijn, so it is a
			// warning rather than an error — but it is visible.
			$this->warnNoDefinition(identity: $identity);

			// A subsidy round declares a closing DATE and no lead time, so it
			// has no TermijnDefinitie and a refusal here is the ordinary path
			// rather than the broken one. The other clocks still bind, and the
			// fixed date still becomes the statutory term.
			$this->bindTheOtherClocks(created: $created, plannedDefinition: $plannedDefinition);
		} catch (\Throwable $e) {
			$this->logBindFailure(identity: $identity, error: $e);
		}//end try
	}//end bind()

	/**
	 * Create the case's term instance, counting from receipt when an intake reader is wired.
	 *
	 * @param CreatedCase               $created    The case id and case type slug, and the case payload.
	 * @param array<string, mixed>|null $resolution The resolved term, if any.
	 *
	 * @return void
	 *
	 * @throws NoTermijnDefinitieException When no definition matches the case.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function createTerm(CreatedCase $created, ?array $resolution): void {
		$this->termService->createTermijnInstance(
			$created->identity->caseId,
			$created->identity->caseType,
			$this->intake?->termStartFor(case: $created->payload),
			$resolution
		);
	}//end createTerm()

	/**
	 * Log, visibly, that a case runs without a statutory term.
	 *
	 * @param NewCaseIdentity $identity The case id, case type reference and slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function warnNoDefinition(NewCaseIdentity $identity): void {
		$caseId = $identity->caseId;
		$caseType = $identity->caseType;
		$caseTypeRef = $identity->caseTypeRef;
		$this->logger->warning(
			'Dossiq termijn: no active TermijnDefinitie for case type "' . $caseType . '", '
			. 'so case ' . $caseId . ' runs without a statutory term',
			['case' => $caseId, 'caseType' => $caseType, 'caseTypeId' => $caseTypeRef]
		);
	}//end warnNoDefinition()

	/**
	 * Log that a term could not be bound to a case.
	 *
	 * @param NewCaseIdentity $identity The case id and case type slug.
	 * @param \Throwable      $error    What went wrong.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function logBindFailure(NewCaseIdentity $identity, \Throwable $error): void {
		$caseId = $identity->caseId;
		$caseType = $identity->caseType;
		$this->logger->error(
			'Dossiq termijn: could not bind a term to case ' . $caseId . ': ' . $error->getMessage(),
			['case' => $caseId, 'caseType' => $caseType, 'exception' => $error->getMessage()]
		);
	}//end logBindFailure()

	/**
	 * Bind the planned end, the internal target and the fixed closing date.
	 *
	 * Separate from the statutory bind above, and tolerant of its own failure:
	 * a service norm that could not be bound must never stop a case being
	 * created, and the warning is what says the case is carrying fewer clocks
	 * than its type declares.
	 *
	 * @param CreatedCase $created The case UUID, the case type as the case carries it, and the
	 *        created case. Its clocks start where the statutory term starts, so the three
	 *        clocks of one case count from one day.
	 * @param array<string, mixed>|null $plannedDefinition The `planned` definition that resolved
	 *        for the case, bound when the case type declares no planned lead time of its own.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/termijn-binding/spec.md
	 * @spec openspec/specs/termijn-binding/spec.md#requirement-the-statutory-term-counts-from-receipt-req-ote-02
	 * @spec openspec/specs/termijn-binding/spec.md
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function bindTheOtherClocks(CreatedCase $created, ?array $plannedDefinition = null): void {
		if ($this->caseTerms === null) {
			return;
		}

		$payload = $created->payload;
		$caseId = $created->identity->caseId;
		$caseTypeRef = $created->identity->caseTypeRef;

		try {
			$this->caseTerms->bindForCase(
				caseId: $caseId,
				caseTypeId: $caseTypeRef,
				start: $this->intake?->termStartFor(case: $payload),
				plannedStart: trim((string)($payload['plannedStartDate'] ?? '')),
				plannedDefinition: $plannedDefinition,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq termijn: the planned end and the internal target could not be bound to case ' . $caseId,
				['case' => $caseId, 'caseType' => $caseTypeRef, 'error' => $e->getMessage()]
			);
		}
	}//end bindTheOtherClocks()
}//end class
