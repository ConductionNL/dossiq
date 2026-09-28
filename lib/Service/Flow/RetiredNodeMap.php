<?php

/**
 * The flow node types dossiq no longer offers, and what each one becomes.
 *
 * A node type that leaves the catalogue does not leave the stored flows. A flow
 * saved last year still names it, and OpenRegister's engine fails the step when
 * it reaches a type nobody registers. This table is the one place that says,
 * per retired type, what an upgrade does with such a step.
 *
 * A ROW WITH A REPLACEMENT AND NO TRANSLATION renames the step's type and keeps
 * its config. A ROW WITH A TRANSLATION hands the config to
 * {@see RetiredNodeTranslator}, which returns the step or steps that replace
 * it, or refuses; a refused step stays where it is and is logged. A ROW WITH
 * NEITHER removes the step and bridges its edges, and the repair step logs a
 * warning naming the flow and the step. Nothing is dropped silently.
 *
 * Add a row here when a node is retired. The repair step
 * {@see \OCA\Dossiq\Repair\RewriteRetiredFlowNodes} reads nothing else.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

/**
 * Lookup over the retired-node table.
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */
class RetiredNodeMap {

	/**
	 * The prefix of a configured-action node; the rest of the id is the action type.
	 *
	 * @var string
	 */
	public const ACTION_NODE_PREFIX = 'dossiq.action.';

	/**
	 * Translation keys, one per retired config shape.
	 *
	 * @var string
	 */
	public const EMAIL_ACTION = 'email-action';
	public const EMAIL_TRANSITION = 'email-transition';
	public const NOTIFY_ROLE = 'notify-role';
	public const NOTIFY_TRANSITION = 'notify-transition';
	public const SET_FIELD = 'set-field';
	public const DECISION = 'decision';
	public const DOCUMENT_CREATE = 'document-create';
	public const DOCUMENT_MERGE = 'document-merge';
	public const WEBHOOK = 'webhook';

	/**
	 * Why the owned-elsewhere steps left, written into every log line.
	 *
	 * @var string
	 */
	private const OWNED_BY_OPENREGISTER = 'OpenRegister now sends mail and notifications, writes objects and evaluates decision tables itself';
	private const OWNED_BY_FILINQ = 'Filinq now generates documents for the fleet';
	private const OWNED_BY_INTEGRIQ = 'Integriq now owns outbound calls';

	/**
	 * The retired node types.
	 *
	 * `replacement` is the node type the step becomes, or null when nothing
	 * replaces it. `translation` names the config translation, when the
	 * replacement reads a different config. `reason` is written into the log.
	 *
	 * @var array<string, array{replacement: string|null, reason: string, translation?: string}>
	 */
	private const ROWS = [
		'dossiq.action.scheduleReminder' => [
			'replacement' => null,
			'reason' => 'the reminder job it queued never existed, so the step never sent a reminder',
		],
		'dossiq.action.sendEmail' => [
			'replacement' => RetiredNodeTranslator::SEND_EMAIL,
			'reason' => self::OWNED_BY_OPENREGISTER,
			'translation' => self::EMAIL_ACTION,
		],
		'dossiq.sendEmail' => [
			'replacement' => RetiredNodeTranslator::SEND_EMAIL,
			'reason' => self::OWNED_BY_OPENREGISTER,
			'translation' => self::EMAIL_TRANSITION,
		],
		'dossiq.action.notifyRole' => [
			'replacement' => RetiredNodeTranslator::SEND_NOTIFICATION,
			'reason' => self::OWNED_BY_OPENREGISTER,
			'translation' => self::NOTIFY_ROLE,
		],
		'dossiq.notify' => [
			'replacement' => RetiredNodeTranslator::SEND_NOTIFICATION,
			'reason' => self::OWNED_BY_OPENREGISTER,
			'translation' => self::NOTIFY_TRANSITION,
		],
		'dossiq.setField' => [
			'replacement' => RetiredNodeTranslator::OBJECT_WRITE,
			'reason' => self::OWNED_BY_OPENREGISTER,
			'translation' => self::SET_FIELD,
		],
		'dossiq.evaluateDecision' => [
			'replacement' => RetiredNodeTranslator::DECISION_TABLE,
			'reason' => self::OWNED_BY_OPENREGISTER,
			'translation' => self::DECISION,
		],
		'dossiq.action.createDocument' => [
			'replacement' => RetiredDocumentSteps::GENERATE_DOCUMENT,
			'reason' => self::OWNED_BY_FILINQ,
			'translation' => self::DOCUMENT_CREATE,
		],
		'dossiq.action.mergeTemplate' => [
			'replacement' => RetiredDocumentSteps::GENERATE_DOCUMENT,
			'reason' => self::OWNED_BY_FILINQ,
			'translation' => self::DOCUMENT_MERGE,
		],
		'dossiq.requestDecision' => [
			'replacement' => 'decidiq.request-decision',
			'reason' => 'Decidiq now raises decisions from a flow itself, with the same configuration',
		],
		'dossiq.webhook' => [
			'replacement' => null,
			'reason' => self::OWNED_BY_INTEGRIQ,
			'translation' => self::WEBHOOK,
		],
		'dossiq.action.callWebhook' => [
			'replacement' => null,
			'reason' => self::OWNED_BY_INTEGRIQ,
			'translation' => self::WEBHOOK,
		],
	];

	/**
	 * The rows this map answers from.
	 *
	 * @var array<string, array{replacement: string|null, reason: string, translation?: string}>
	 */
	private readonly array $rows;

	/**
	 * Constructor.
	 *
	 * @param array<string, array{replacement: string|null, reason: string, translation?: string}>|null $rows The table,
	 *        or null for the shipped one. Tests pass their own to cover a replacement row.
	 */
	public function __construct(?array $rows = null) {
		$this->rows = ($rows ?? self::ROWS);
	}//end __construct()

	/**
	 * The row for a node type, or null when the type is not retired.
	 *
	 * @param string $type The node type.
	 *
	 * @return array{replacement: string|null, reason: string, translation?: string}|null The row.
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	public function rowFor(string $type): ?array {
		return ($this->rows[$type] ?? null);
	}//end rowFor()

	/**
	 * The retired configured-action types nothing replaces, as stored on an `automaticAction`.
	 *
	 * @return array<string, string> Action type => reason.
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	public function retiredActionTypes(): array {
		$types = [];
		foreach ($this->rows as $nodeType => $row) {
			// A type with a replacement still runs: the migration command
			// builds its flow from the replacement. Only a type nothing
			// replaces is one whose stored actions will not run.
			if (str_starts_with($nodeType, self::ACTION_NODE_PREFIX) === false || $row['replacement'] !== null) {
				continue;
			}

			$types[substr($nodeType, strlen(self::ACTION_NODE_PREFIX))] = $row['reason'];
		}

		return $types;
	}//end retiredActionTypes()
}//end class
