<?php

/**
 * Dossiq delegated authority guard.
 *
 * Refuses a transition until the person who acts holds the authority for it,
 * as decidiq's delegatie- en mandaatregister answers. 🔑 decidiq ANSWERS,
 * dossiq ENFORCES: the register, its matrix and its validity rules are
 * decidiq's (refactor capability C17, hydra ADR-118), and this guard only asks
 * one question and turns the answer into a refusal a handler can read.
 *
 * Guard config shape (every key optional except one of `act` / `actField`):
 *   - `act`: the act key, literally (for example a decision type).
 *   - `actField`: the case field, or case-type property, that holds the act key.
 *   - `actorField`: the case field naming who acts; default `signatory`, and the
 *     current user when the case names nobody.
 *   - `amountField`: the case field holding the amount in euro cents (ADR-031).
 *
 * 🔴 EVERY UNCERTAIN ANSWER REFUSES. No decidiq on the instance, a decidiq that
 * throws, an act the case does not name and an amount that is not a number all
 * refuse, each with its own sentence. The lookup is duck-typed through
 * FleetAppId so an instance running the app under its old id is still asked.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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
 * @spec openspec/changes/delegated-authority-guard/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Support\FleetAppId;
use Psr\Container\ContainerInterface;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks decidiq whether the acting person holds the authority for a transition.
 *
 * @spec openspec/changes/delegated-authority-guard/specs/status-transition-engine/spec.md
 */
class DelegatedAuthorityGuard implements GuardEvaluatorInterface {

	/**
	 * Config defaults merged under every evaluation.
	 *
	 * @var array<string, mixed>
	 */
	private array $defaults = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The server container, for the duck-typed decidiq lookup.
	 * @param LoggerInterface    $logger    The logger.
	 * @param IL10N              $l10n      Translates the refusals.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * A copy of this guard with config defaults, for a legacy guard key whose
	 * stored declarations carry no config.
	 *
	 * @param array<string, mixed> $defaults The defaults.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/delegated-authority-guard/specs/status-transition-engine/spec.md
	 */
	public function withDefaults(array $defaults): self {
		$copy           = clone $this;
		$copy->defaults = $defaults;

		return $copy;
	}//end withDefaults()

	/**
	 * Evaluate the guard.
	 *
	 * @param array<string, mixed> $guardConfig The guard configuration block.
	 * @param array<string, mixed> $case        The case object.
	 * @param string               $userId      The current user UID.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/delegated-authority-guard/specs/status-transition-engine/spec.md
	 */
	public function evaluate(array $guardConfig, array $case, string $userId): GuardResult {
		$config = ($guardConfig + $this->defaults);

		$act = trim((string)($config['act'] ?? ''));
		if ($act === '' && trim((string)($config['actField'] ?? '')) !== '') {
			$act = (string)$this->caseValue(case: $case, field: (string)$config['actField']);
		}

		if ($act === '') {
			return $this->refuse(reason: 'no-act');
		}

		$actor = (string)$this->caseValue(case: $case, field: (string)($config['actorField'] ?? 'signatory'));
		if ($actor === '') {
			$actor = $userId;
		}

		$amount = null;
		if (trim((string)($config['amountField'] ?? '')) !== '') {
			$cents = $this->caseValue(case: $case, field: (string)$config['amountField']);
			if ($cents !== null && is_numeric($cents) === false) {
				return new GuardResult(
					passed: false,
					failureMessage: $this->l10n->t('The amount on the case is not a number, so the mandate cannot be checked.'),
					details: ['authority' => 'amount-unreadable'],
				);
			}

			if ($cents !== null) {
				$amount = ((float)$cents / 100);
			}
		}

		return $this->ask(actor: $actor, act: $act, amount: $amount);
	}//end evaluate()

	/**
	 * Ask decidiq and translate the answer.
	 *
	 * @param string     $actor  The acting account.
	 * @param string     $act    The act key.
	 * @param float|null $amount The amount in euro.
	 *
	 * @return GuardResult
	 */
	private function ask(string $actor, string $act, ?float $amount): GuardResult {
		$check = FleetAppId::getService(
			container: $this->container,
			canonical: 'decidiq',
			relative: 'Service\\DelegatedAuthorityCheck'
		);
		if ($check === null) {
			return new GuardResult(
				passed: false,
				failureMessage: $this->l10n->t('The mandate register (decidiq) is not installed, so the mandate cannot be checked.'),
				details: ['authority' => 'register-absent'],
			);
		}

		try {
			$answer = $check->check(actor: $actor, act: $act, amount: $amount);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq could not ask decidiq for delegated authority: ' . $e->getMessage(),
				['app' => 'dossiq', 'act' => $act]
			);

			return $this->refuse(reason: 'register-unreadable');
		}

		if (is_array($answer) === true && ($answer['authorised'] ?? false) === true) {
			return new GuardResult(
				passed: true,
				details: ['authority' => 'confirmed', 'allocation' => (string)($answer['allocation'] ?? '')],
			);
		}

		$reason = 'register-unreadable';
		if (is_array($answer) === true) {
			$reason = (string)($answer['reason'] ?? 'register-unreadable');
		}

		return $this->refuse(reason: $reason);
	}//end ask()

	/**
	 * A refusal with the sentence for its reason.
	 *
	 * @param string $reason The reason key.
	 *
	 * @return GuardResult
	 */
	private function refuse(string $reason): GuardResult {
		return new GuardResult(
			passed: false,
			failureMessage: $this->sentenceFor(reason: $reason),
			details: ['authority' => $reason],
		);
	}//end refuse()

	/**
	 * The refusal sentence for a decidiq reason key; an unknown key reads as no allocation.
	 *
	 * @param string $reason The reason key.
	 *
	 * @return string
	 */
	private function sentenceFor(string $reason): string {
		return match ($reason) {
			'over-ceiling' => $this->l10n->t('The amount is above the financial ceiling of the signatory\'s mandate.'),
			'register-unreadable' => $this->l10n->t('The mandate register cannot be read right now, so this step cannot be approved.'),
			'no-actor' => $this->l10n->t('No signatory is known.'),
			'no-act' => $this->l10n->t('This step names no act to check the mandate for.'),
			default => $this->l10n->t('The signing official does not hold enough mandate for this decision. Consult the mandate register.'),
		};
	}//end sentenceFor()

	/**
	 * Read a case field, or the case-type property the case answers in `properties[]`.
	 *
	 * @param array<string, mixed> $case  The case.
	 * @param string               $field The field name.
	 *
	 * @return string|null The value, or null when the case does not answer it.
	 */
	private function caseValue(array $case, string $field): ?string {
		$field = trim($field);
		if ($field === '') {
			return null;
		}

		$direct = ($case[$field] ?? null);
		if (is_scalar($direct) === true && trim((string)$direct) !== '') {
			return trim((string)$direct);
		}

		$entries = ($case['properties'] ?? null);
		if (is_array($entries) === false) {
			return null;
		}

		foreach ($entries as $entry) {
			if (is_array($entry) === false || (string)($entry['name'] ?? '') !== $field) {
				continue;
			}

			$value = trim((string)($entry['value'] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return null;
	}//end caseValue()
}//end class
