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

namespace OCA\Dossiq\Service\Pipelinq;

use OCA\Dossiq\Service\People\PartyIndicatorReader;

/**
 * Whether an act on a party is refused, asking both the apps that can say so.
 *
 * Writing to somebody who died last month, or publishing an address that is
 * under geheimhouding, is the failure an indicator prevents. Two apps hold
 * indicators now: OpenRegister, which `PartyIndicatorReader` already asks and
 * which also enforces the refusal inside the platform, and pipelinq, which owns
 * the party's standing indicators and answers whether an act is blocked.
 *
 * 🔴 JOINED, NEVER REPOINTED. The tempting move is to point the existing reader
 * at pipelinq. That is wrong twice: it drops the half OpenRegister still
 * enforces, and a duck-typed lookup aimed at a name nothing answers to no-ops
 * rather than erroring, so the panel would render empty forever and look
 * correct. Both are asked, and an act is refused when EITHER refuses.
 *
 * Two sources answering one question can only disagree in one direction: one of
 * them says no. Taking the refusal is the safe direction, and it is the one
 * this takes.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-dossiq-asks-before-it-sends-and-before-it-publishes-and-either-refusal-stops-it-req-plq-05
 */
class PartyRefusalReader {

	/**
	 * The act of sending something to a party.
	 */
	public const ACT_SEND = 'send';

	/**
	 * The act of publishing a party's address.
	 */
	public const ACT_PUBLISH_ADDRESS = 'publishAddress';

	/**
	 * @param PipelinqGateway $gateway The only seam that names pipelinq.
	 * @param PartyIndicatorReader $openRegisterReader OpenRegister's own answer,
	 *   left pointed where it already points.
	 */
	public function __construct(
		private readonly PipelinqGateway $gateway,
		private readonly PartyIndicatorReader $openRegisterReader,
	) {
	}//end __construct()

	/**
	 * Whether dossiq may send something to a party.
	 *
	 * @param string $partyUuid The party.
	 *
	 * @return array{refused: bool, sources: array<int, string>, indicator: string}
	 *   The verdict, which sources refused, and the indicator a handler reads.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-dossiq-asks-before-it-sends-and-before-it-publishes-and-either-refusal-stops-it-req-plq-05
	 */
	public function maySendTo(string $partyUuid): array {
		$partyUuid = trim($partyUuid);
		if ($partyUuid === '') {
			return ['refused' => false, 'sources' => [], 'indicator' => ''];
		}

		$sources = [];
		$indicator = '';

		$openRegister = $this->openRegisterReader->sendRefusalFor(partyUuid: $partyUuid);
		if ($openRegister !== null) {
			$sources[] = 'openregister';
			$indicator = $openRegister;
		}

		$pipelinq = $this->pipelinqRefusal(partyId: $partyUuid, act: self::ACT_SEND);
		if ($pipelinq !== '') {
			$sources[] = 'pipelinq';
			// pipelinq's label wins the sentence when both refuse: it carries
			// the severity and the vocabulary an administrator maintains.
			$indicator = $pipelinq;
		}

		return [
			'refused' => ($sources !== []),
			'sources' => $sources,
			'indicator' => $indicator,
		];
	}//end maySendTo()

	/**
	 * Whether dossiq may publish a case, given the parties on it.
	 *
	 * @param string $caseId The case.
	 * @param array<int, string> $partyUuids The parties on it.
	 *
	 * @return array{refused: bool, sources: array<int, string>, indicator: string}
	 *   The verdict.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-dossiq-asks-before-it-sends-and-before-it-publishes-and-either-refusal-stops-it-req-plq-05
	 */
	public function mayPublish(string $caseId, array $partyUuids = []): array {
		$sources = [];
		$indicator = '';

		$openRegister = $this->openRegisterReader->publicationRefusal(caseId: trim($caseId));
		if ($openRegister !== null) {
			$sources[] = 'openregister';
			$indicator = (string)($openRegister['label'] ?? $openRegister['key'] ?? '');
		}

		foreach ($partyUuids as $partyUuid) {
			$pipelinq = $this->pipelinqRefusal(
				partyId: trim((string)$partyUuid),
				act: self::ACT_PUBLISH_ADDRESS,
			);

			if ($pipelinq === '') {
				continue;
			}

			$sources[] = 'pipelinq';
			$indicator = $pipelinq;
			break;
		}

		return [
			'refused' => ($sources !== []),
			'sources' => $sources,
			'indicator' => $indicator,
		];
	}//end mayPublish()

	/**
	 * A party's indicators as pipelinq resolves them, live.
	 *
	 * Nothing is copied onto the case: an indicator set today has to show on a
	 * case opened last year, and one lifted today has to leave every surface at
	 * once.
	 *
	 * @param string $partyId The party.
	 *
	 * @return array{available: bool, indicators: array<int, array<string, mixed>>}
	 *   The indicators, and whether pipelinq answered at all.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-dossiq-asks-before-it-sends-and-before-it-publishes-and-either-refusal-stops-it-req-plq-05
	 */
	public function indicatorsOnParty(string $partyId): array {
		$answer = $this->gateway->ask(
			class: PipelinqGateway::PARTY_INDICATORS,
			method: 'resolve',
			arguments: ['partyId' => trim($partyId)],
			fallback: [],
		);

		if ($answer['answered'] === false || is_array($answer['value']) === false) {
			return ['available' => false, 'indicators' => []];
		}

		return ['available' => true, 'indicators' => $answer['value']];
	}//end indicatorsOnParty()

	/**
	 * The label of the pipelinq indicator refusing an act, '' when none does.
	 *
	 * @param string $partyId The party.
	 * @param string $act One of the ACT_* constants.
	 *
	 * @return string The label.
	 */
	private function pipelinqRefusal(string $partyId, string $act): string {
		if ($partyId === '') {
			return '';
		}

		$answer = $this->gateway->ask(
			class: PipelinqGateway::PARTY_INDICATORS,
			method: 'isBlocked',
			arguments: ['partyId' => $partyId, 'act' => $act],
			fallback: null,
		);

		if ($answer['answered'] === false || is_array($answer['value']) === false) {
			return '';
		}

		$verdict = $answer['value'];
		if (($verdict['blocked'] ?? false) !== true) {
			return '';
		}

		$first = ((array)($verdict['indicators'] ?? []))[0] ?? [];

		return trim((string)($first['label'] ?? $first['code'] ?? 'an indicator on this party'));
	}//end pipelinqRefusal()
}//end class
