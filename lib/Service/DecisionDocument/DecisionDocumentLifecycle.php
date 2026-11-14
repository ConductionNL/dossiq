<?php

/**
 * Dossiq Decision Document Lifecycle.
 *
 * Drafts a decision document and moves it through approval, signing, sending and archiving.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\DecisionDocument
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
 * @spec openspec/specs/decision-document/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\DecisionDocument;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\DocumentSeries\DocumentSeriesNumberer;
use Psr\Container\ContainerInterface;

/**
 * One lifecycle for every kind of decision document.
 *
 * It returns the fields to write and never writes them: the caller saves
 * through OpenRegister, where the frozen-record capability (C04, through
 * {@see DecisionDocumentDefinition::freezeDefinition()}) holds a signed
 * document still, whatever API writes it.
 *
 * The statuses are fixed and generic (draft, approved, signed, sent,
 * archived). What differs per procedure is configuration: the fields, the
 * rules, the remedy clause, the authority, the number series.
 *
 * @spec openspec/specs/decision-document/spec.md
 */
class DecisionDocumentLifecycle {

	/**
	 * The transitions a decision document may make.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const TRANSITIONS = [
		'draft' => ['approved'],
		'approved' => ['draft', 'signed'],
		'signed' => ['sent'],
		'sent' => ['archived'],
		'archived' => [],
	];

	/**
	 * Constructor.
	 *
	 * @param DecisionDocumentValidator $validator The validity check.
	 * @param DocumentSeriesNumberer $numberer Issues the number at signing.
	 * @param ContainerInterface $container Resolves the bound {@see DecisionAuthority}, when there is one.
	 */
	public function __construct(
		private readonly DecisionDocumentValidator $validator,
		private readonly DocumentSeriesNumberer $numberer,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * A new draft of a kind on a case.
	 *
	 * @param DecisionDocumentDefinition $definition The kind's definition.
	 * @param string $caseId The case.
	 * @param array<string, mixed> $fields The drafter's fields; they win over the defaults.
	 *
	 * @return array<string, mixed> The draft.
	 *
	 * @spec openspec/specs/decision-document/spec.md
	 */
	public function draft(DecisionDocumentDefinition $definition, string $caseId, array $fields = []): array {
		$defaults = [
			'kind' => $definition->kind,
			'caseId' => $caseId,
			'template' => $definition->template,
			'remedyClause' => $definition->remedyClause,
			'decisionKind' => $definition->decisionKind,
		];

		return array_merge($defaults, $fields, ['currentStatus' => 'draft', 'kind' => $definition->kind, 'caseId' => $caseId]);
	}//end draft()

	/**
	 * The fields to write to move a document to a status.
	 *
	 * @param DecisionDocumentDefinition $definition The kind's definition.
	 * @param array<string, mixed> $document The document as stored.
	 * @param string $to The target status.
	 * @param string $actor The acting user.
	 * @param DateTimeImmutable|null $moment The moment; now when null.
	 *
	 * @return array<string, mixed> The changes.
	 *
	 * @throws RefusedException 409 for a transition that does not exist; 422 when the document is
	 *                          invalid or unsigned; 403 or 503 from the authority check; 503 when no
	 *                          number can be issued.
	 *
	 * @spec openspec/specs/decision-document/spec.md
	 */
	public function transition(
		DecisionDocumentDefinition $definition,
		array $document,
		string $to,
		string $actor,
		?DateTimeImmutable $moment = null,
	): array {
		$from = (string)($document['currentStatus'] ?? 'draft');
		if (in_array($to, (self::TRANSITIONS[$from] ?? []), true) === false) {
			throw new RefusedException(
				rule: 'decision-document-transition',
				sentence: sprintf('A decision cannot move from %s to %s.', $from, $to),
				status: RefusedException::STATUS_REFUSED,
			);
		}

		$moment = ($moment ?? new DateTimeImmutable());
		$stamp = $moment->format(DateTimeInterface::ATOM);

		return match ($to) {
			'approved' => $this->approve(definition: $definition, document: $document, actor: $actor, stamp: $stamp),
			'signed' => $this->sign(definition: $definition, document: $document, moment: $moment),
			'sent' => ['currentStatus' => 'sent', 'sentAt' => $stamp],
			'archived' => ['currentStatus' => 'archived', 'archivedAt' => $stamp],
			default => ['currentStatus' => 'draft', 'approvedBy' => null, 'approvedAt' => null],
		};
	}//end transition()

	/**
	 * Approval: valid, and decided by someone who holds the authority.
	 *
	 * @param DecisionDocumentDefinition $definition The kind's definition.
	 * @param array<string, mixed> $document The document.
	 * @param string $actor The approving user.
	 * @param string $stamp The moment, ISO 8601.
	 *
	 * @return array<string, mixed> The changes.
	 *
	 * @throws RefusedException 422 when invalid; 403 or 503 from the authority.
	 */
	private function approve(DecisionDocumentDefinition $definition, array $document, string $actor, string $stamp): array {
		$problems = $this->validator->problems(definition: $definition, document: $document);
		if ($problems !== []) {
			throw new RefusedException(
				rule: 'decision-document-invalid',
				sentence: implode(' ', array_column($problems, 'message')),
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if ($definition->authorityAct !== '') {
			$this->authority()->assertMayDecide(
				caseId: (string)($document['caseId'] ?? ''),
				act: $definition->authorityAct,
				actor: $actor,
				document: $document,
			);
		}

		return ['currentStatus' => 'approved', 'approvedBy' => $actor, 'approvedAt' => $stamp];
	}//end approve()

	/**
	 * Signing: the signature is present, and the document takes its number.
	 *
	 * @param DecisionDocumentDefinition $definition The kind's definition.
	 * @param array<string, mixed> $document The document.
	 * @param DateTimeImmutable $moment The moment.
	 *
	 * @return array<string, mixed> The changes.
	 *
	 * @throws RefusedException 422 when unsigned; 503 when no number can be issued.
	 */
	private function sign(DecisionDocumentDefinition $definition, array $document, DateTimeImmutable $moment): array {
		if ($definition->signed === true && empty($document['signature']) === true) {
			throw new RefusedException(
				rule: 'decision-document-unsigned',
				sentence: 'The decision has no signature yet, so it cannot be marked as signed.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$changes = ['currentStatus' => 'signed', 'signedAt' => $moment->format(DateTimeInterface::ATOM)];
		if ($definition->series !== '' && trim((string)($document['reference'] ?? '')) === '') {
			$changes['reference'] = $this->numberer->issue(
				caseId: (string)($document['caseId'] ?? ''),
				series: $definition->series,
				defaultPrefix: $definition->defaultPrefix,
				moment: $moment,
			);
		}

		return $changes;
	}//end sign()

	/**
	 * The bound authority check, or a refusal when none is bound.
	 *
	 * @return DecisionAuthority The check.
	 *
	 * @throws RefusedException 503 when no authority check is bound.
	 */
	private function authority(): DecisionAuthority {
		if ($this->container->has(DecisionAuthority::class) === true) {
			$authority = $this->container->get(DecisionAuthority::class);
			if ($authority instanceof DecisionAuthority) {
				return $authority;
			}
		}

		throw new RefusedException(
			rule: 'decision-authority-unavailable',
			sentence: 'Who may decide this could not be checked, so the decision was not approved. Try again later.',
			status: RefusedException::STATUS_INDETERMINATE,
		);
	}//end authority()
}//end class
