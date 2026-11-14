<?php

/**
 * Dossiq Inbound Mail Filter Contract
 *
 * One filter, one question, one place in a declared order. Zammad's postmaster
 * pipeline is thirty small classes in one directory and that is the reason it
 * can be reasoned about at all (design D-3); this is the same shape.
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

/**
 * A named filter in the intake pipeline.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
interface InboundMailFilter {

	/**
	 * The filter's name, as the intake log records it.
	 *
	 * Stable and machine-readable: it is written into stored rows and a handler
	 * searches the log by it, so it is not a translated label.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function name(): string;

	/**
	 * Where this filter sits in the declared order. Lower runs first.
	 *
	 * @return integer The order.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function order(): int;

	/**
	 * What this filter makes of one message.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return FilterVerdict The verdict, `pass` when this filter has no opinion.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function decide(InboundMessage $message): FilterVerdict;
}//end interface
