<?php

/**
 * Dossiq Junk Filter
 *
 * A junk verdict that names the rule that reached it, so an administrator can
 * read why and a handler can correct it.
 *
 * Quarantines rather than refuses. Junk classification is the one verdict in
 * this pipeline that is a guess, and a wrongly junked aanvraag that nobody can
 * find is exactly the silently dropped message this change exists to prevent.
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
use OCA\Dossiq\Service\Email\JunkRules;

/**
 * Quarantines a message a readable rule calls junk.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) — the static calls here are named
 *  constructors and value-object factories (`InboundMessage::fromRow()`,
 *  `FilterVerdict::accept()`, `AuthenticationVerdict::unknown()`), which hold no
 *  state and exist so a caller cannot build a half-built value.
 */
class JunkFilter implements InboundMailFilter {

	/**
	 * The name the intake log records.
	 */
	public const NAME = 'junk';

	/**
	 * Constructor.
	 *
	 * @param JunkRules $rules The rules an administrator can read.
	 */
	public function __construct(
		private readonly JunkRules $rules,
	) {
	}//end __construct()

	/**
	 * The name the intake log records.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function name(): string {
		return self::NAME;
	}//end name()

	/**
	 * Where this filter sits in the declared order.
	 *
	 * @return integer The order.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function order(): int {
		return 60;
	}//end order()

	/**
	 * Whether a rule calls this junk, and which one.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return FilterVerdict Quarantine when a rule matches, pass otherwise.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function decide(InboundMessage $message): FilterVerdict {
		$rule = $this->rules->matching(message: $message);
		if ($rule === null) {
			return FilterVerdict::pass(filterName: self::NAME);
		}

		return FilterVerdict::quarantine(
			filterName: self::NAME,
			reason: 'Rule ' . $rule['name'] . ': ' . $rule['description']
		);
	}//end decide()
}//end class
