<?php

/**
 * Contract of CorrespondenceLanguageConsumer
 *
 * The public surface of CorrespondenceLanguageConsumer that other components call, so they depend
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
 * Contract of CorrespondenceLanguageConsumer.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CorrespondenceLanguageConsumerInterface {

	/**
	 * The language to write to one party in.
	 *
	 * @param string $partyId The party.
	 *
	 * @return array{available: bool, language: string, rule: string, stated: bool, sentence: string}
	 *   The tag, the rule that produced it, whether the party actually stated
	 *   one, and the sentence a surface renders.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-the-language-to-write-to-a-party-in-comes-from-the-resolver-with-its-reason-req-plq-06
	 */
	public function forParty(string $partyId): array;
}//end interface
