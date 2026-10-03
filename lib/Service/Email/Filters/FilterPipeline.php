<?php

/**
 * Dossiq Inbound Filter Pipeline
 *
 * The declared order every message walks before it can become a case, and the
 * one place that records which filter decided and why (design D-3).
 *
 * 🔴 THE DEFAULT IS WRITTEN DOWN RATHER THAN IMPLIED. A message that reaches the
 * end with nothing objecting is ACCEPTED, and {@see FilterOutcome::DEFAULT_OUTCOME}
 * is where that is stated. The old poller's default was `continue` with no log
 * line, which is why an instance losing every inbound message looked exactly
 * like an instance receiving none.
 *
 * The order is taken from each filter's own `order()` rather than from the
 * order the container happened to hand them over in. Two filters can both
 * refuse the same message, and which name the intake log records has to be
 * stable: a handler searching the log for "auto-reply" must not find half of
 * last week's under "out-of-office" because the DI container was rebuilt.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email\Filters
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email\Filters;

use OCA\Dossiq\Service\Email\InboundMessage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the named filters in their declared order.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
class FilterPipeline {

	/**
	 * The filters, sorted once at construction.
	 *
	 * @var array<int, InboundMailFilter>
	 */
	private array $ordered = [];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface             $logger  Logger.
	 * @param array<int, InboundMailFilter> $filters The filters, in any order.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		array $filters = [],
	) {
		$this->ordered = $filters;
		usort(
			$this->ordered,
			static function (InboundMailFilter $left, InboundMailFilter $right): int {
				$byOrder = ($left->order() <=> $right->order());
				if ($byOrder !== 0) {
					return $byOrder;
				}

				// Two filters claiming the same slot is a configuration mistake,
				// not a reason to be non-deterministic about it.
				return strcmp($left->name(), $right->name());
			}
		);
	}//end __construct()

	/**
	 * The filters in the order they run, by name.
	 *
	 * Published so the intake log surface and the admin settings can show the
	 * order rather than describe it.
	 *
	 * @return array<int, string> The names.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function declaredOrder(): array {
		return array_map(
			static function (InboundMailFilter $filter): string {
				return $filter->name();
			},
			$this->ordered
		);
	}//end declaredOrder()

	/**
	 * Walk one message through every filter until one decides.
	 *
	 * A filter that throws is treated as having no opinion, and the throw is
	 * logged with the filter's name. The alternative is letting one broken
	 * filter stop intake for the whole mailbox, which turns a bug in a spam
	 * heuristic into a missed statutory term.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return FilterVerdict The deciding verdict, or the written-down default.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function run(InboundMessage $message): FilterVerdict {
		foreach ($this->ordered as $filter) {
			try {
				$verdict = $filter->decide(message: $message);
			} catch (Throwable $e) {
				$this->logger->error(
					'Dossiq: the {filter} intake filter threw, so it decided nothing',
					['filter' => $filter->name(), 'error' => $e->getMessage()]
				);
				continue;
			}

			if ($verdict->isDecisive() === false) {
				continue;
			}

			return $verdict;
		}//end foreach

		return new FilterVerdict(
			outcome: FilterOutcome::DEFAULT_OUTCOME,
			filterName: '',
			reason: 'No filter objected, and the declared default is to accept.'
		);
	}//end run()
}//end class
