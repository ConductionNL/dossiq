<?php

/**
 * Contract of PartyKindConsumer
 *
 * The public surface of PartyKindConsumer that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of PartyKindConsumer.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PartyKindConsumerInterface {

	/**
	 * The kinds a case type has DECLARED, in order, or null when it declared none.
	 *
	 * Null and an empty list are different answers. Null is "nothing declared,
	 * so every active kind is offered" or "pipelinq is not here"; an empty list
	 * would be a declaration that the type accepts no party at all.
	 *
	 * @param string $caseType The case type's key.
	 *
	 * @return array<int, string>|null The declared codes, or null.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
	 */
	public function acceptanceOf(string $caseType): ?array;

	/**
	 * Declare what one case type accepts, in the order handlers should see.
	 *
	 * The order is part of the declaration: the first kind is the one most
	 * handlers take, and pipelinq is required not to re-sort it.
	 *
	 * @param string $caseType The case type's key.
	 * @param array<int, string> $kinds The accepted kind codes, in order.
	 *
	 * @return array{declared: bool, target: string, reason: string} The outcome.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
	 */
	public function declareAcceptance(string $caseType, array $kinds): array;

	/**
	 * The kinds a picker on this case type should offer.
	 *
	 * @param string $caseType The case type's key.
	 *
	 * @return array{source: string, kinds: array<int, array<string, mixed>>}
	 *   The kinds and who answered: `pipelinq` or `dossiq`.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
	 */
	public function kindsFor(string $caseType): array;

	/**
	 * Every kind there is to choose from, for the case type editor.
	 *
	 * `kindsFor()` answers what one case type OFFERS, which after a declaration
	 * is only the declared subset. The editor needs the whole list to let an
	 * admin add a kind back, so it asks pipelinq's vocabulary itself.
	 *
	 * @return array{source: string, kinds: array<int, array<string, mixed>>}
	 *   Every kind and who answered: `pipelinq` or `dossiq`.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
	 */
	public function vocabulary(): array;
}//end interface
