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

use OCA\Dossiq\Service\People\PartyVocabulary;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Which kinds of party a case type accepts, and who says so.
 *
 * Pipelinq holds the fleet's vocabulary; dossiq knows its own case types.
 * So the two halves go opposite ways: the KINDS are read from pipelinq, and
 * the ACCEPTANCE per case type is declared to it, named `dossiq:case:<type>`,
 * which pipelinq stores as an opaque string and never parses.
 *
 * 🔴 THE SHIPPED VOCABULARY IS A FALLBACK, NOT A DUPLICATE. `PartyVocabulary`
 * keeps its three kinds. Deleting them would make an instance without pipelinq
 * offer an empty picker, which a handler reads as "this case type accepts no
 * party at all". The fallback is what the app shipped last week and it keeps
 * working; it is simply not offered BESIDE pipelinq's when pipelinq answers.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
 */
class PartyKindConsumer {
	use SearchesObjects;


	/**
	 * How a dossiq case type is named to pipelinq.
	 *
	 * `<app>:<schema>:<type>`, per pipelinq's acceptance contract. The literal
	 * is built here and nowhere else, so the two halves cannot drift.
	 */
	public const TARGET_PREFIX = 'dossiq:case:';

	/**
	 * pipelinq's register, by slug. Resolved by slug rather than by id
	 * because dossiq has no configuration key for another app's register and
	 * must not grow one.
	 */
	public const PIPELINQ_REGISTER = 'pipelinq';

	/**
	 * The schema an acceptance is written to.
	 */
	public const ACCEPTANCE_SCHEMA = 'partyKindAcceptance';

	/**
	 * @param PipelinqGateway $gateway The only seam that names pipelinq.
	 * @param PartyVocabulary $vocabulary Dossiq's own kinds, the fallback.
	 * @param SettingsService $settingsService Resolves OpenRegister's object service.
	 * @param LoggerInterface $logger Says when a declaration did not land.
	 */
	public function __construct(
		private readonly PipelinqGateway $gateway,
		private readonly PartyVocabulary $vocabulary,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The `<app>:<schema>:<type>` literal for one case type.
	 *
	 * @param string $caseType The case type's key.
	 *
	 * @return string The target.
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
	 */
	public function targetFor(string $caseType): string {
		return self::TARGET_PREFIX . trim($caseType);
	}//end targetFor()

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
	public function kindsFor(string $caseType): array {
		$answer = $this->gateway->ask(
			class: PipelinqGateway::PARTY_KINDS,
			method: 'kindsFor',
			arguments: ['recordType' => $this->targetFor(caseType: $caseType)],
			fallback: null,
		);

		if ($answer['answered'] === false || is_array($answer['value']) === false) {
			// Dossiq's own three. Said rather than quietly substituted, so a
			// picker can show where its list came from.
			return ['source' => 'dossiq', 'kinds' => $this->vocabulary->kinds()];
		}

		$kinds = array_values(array_filter($answer['value'], static fn ($kind): bool => is_array($kind) === true));

		if ($kinds === []) {
			// Pipelinq answered an empty list. That is a real answer, not an
			// absence: a record type may accept no party. It is still reported
			// as pipelinq's, so nobody papers over it with the fallback.
			return ['source' => 'pipelinq', 'kinds' => []];
		}

		return ['source' => 'pipelinq', 'kinds' => $kinds];
	}//end kindsFor()

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
	public function declareAcceptance(string $caseType, array $kinds): array {
		$target = $this->targetFor(caseType: $caseType);

		$ordered = $this->orderedKinds(kinds: $kinds);

		if ($ordered === []) {
			return ['declared' => false, 'target' => $target, 'reason' => 'no kinds were named'];
		}

		// WRITTEN AS AN OBJECT, not called as a method. pipelinq publishes no
		// write for an acceptance, and rightly: the declaration is the
		// consuming app's policy and the object is pipelinq's, so dossiq
		// writes a `partyKindAcceptance` row into pipelinq's register the way
		// every cross-app declaration in this fleet is written. Resolving a
		// method pipelinq does not have would have no-opped silently, which is
		// the one failure mode this whole change is written to avoid.
		$objectService = $this->objectService();
		if ($objectService === null) {
			return ['declared' => false, 'target' => $target, 'reason' => 'OpenRegister is unavailable'];
		}

		if ($this->gateway->isAvailable() === false) {
			return ['declared' => false, 'target' => $target, 'reason' => 'pipelinq is not installed'];
		}

		try {
			$this->writeAcceptance(objectService: $objectService, target: $target, ordered: $ordered);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq pipelinq: a case type could not declare which party kinds it accepts, so the '
				. 'picker offers every active kind: ' . $e->getMessage(),
				['target' => $target]
			);

			return ['declared' => false, 'target' => $target, 'reason' => $e->getMessage()];
		}

		return ['declared' => true, 'target' => $target, 'reason' => ''];
	}//end declareAcceptance()

	/**
	 * The named kinds, trimmed, without blanks and without repeats.
	 *
	 * @param array<int, mixed> $kinds The kinds as the caller named them.
	 *
	 * @return array<int, string> The kinds, in the order they were first named.
	 *
	 * @psalm-return list<string>
	 */
	private function orderedKinds(array $kinds): array {
		$ordered = [];
		foreach ($kinds as $kind) {
			$kind = trim((string)$kind);
			if ($kind !== '' && in_array($kind, $ordered, true) === false) {
				$ordered[] = $kind;
			}
		}

		return $ordered;
	}//end orderedKinds()

	/**
	 * Write the acceptance for a target: a create the first time, a patch after.
	 *
	 * @param object             $objectService The OpenRegister object service.
	 * @param string             $target        The record type the acceptance is for.
	 * @param array<int, string> $ordered       The kinds being declared.
	 *
	 * @return void
	 */
	private function writeAcceptance(object $objectService, string $target, array $ordered): void {
		$existing = $this->existingAcceptanceId(objectService: $objectService, target: $target);

		if ($existing === null) {
			// A FIRST declaration is a create, and a create's payload IS
			// the whole object, so a plain save is correct here.
			$objectService->saveObject(
				object: ['recordType' => $target, 'kinds' => $ordered],
				register: self::PIPELINQ_REGISTER,
				schema: self::ACCEPTANCE_SCHEMA,
			);

			return;
		}

		// 🔴 A RE-DECLARATION IS A PATCH, NEVER A SAVE WITH A UUID.
		// `saveObject()` with a uuid REPLACES the stored object, so handing it
		// these two fields would delete every other field pipelinq holds on
		// that row, silently, and on somebody else's data, which is why this is
		// the first of the six repairs and not the last. The row belongs to
		// pipelinq; we only ever change the part we declared.
		$this->patchObjectAsArray(
			objectService: $objectService,
			register: self::PIPELINQ_REGISTER,
			schema: self::ACCEPTANCE_SCHEMA,
			id: $existing,
			changes: ['recordType' => $target, 'kinds' => $ordered],
		);
	}//end writeAcceptance()

	/**
	 * The uuid of the acceptance already written for a target, or null.
	 *
	 * Re-declaring a case type has to UPDATE rather than append: a second row
	 * for one target leaves two answers and pipelinq takes whichever it reaches
	 * first.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $target The `<app>:<schema>:<type>` literal.
	 *
	 * @return string|null The uuid, or null for a first declaration.
	 */
	private function existingAcceptanceId(object $objectService, string $target): ?string {
		try {
			$rows = $objectService->findAll(
				[
					'filters' => [
						'register' => self::PIPELINQ_REGISTER,
						'schema' => self::ACCEPTANCE_SCHEMA,
						'recordType' => $target,
					],
					'limit' => 10,
				]
			);
		} catch (Throwable $e) {
			return null;
		}

		foreach ($rows as $row) {
			$data = $row;
			if ($row instanceof \JsonSerializable) {
				$data = $row->jsonSerialize();
			}

			if (is_array($data) === false) {
				continue;
			}

			if ((string)($data['recordType'] ?? '') !== $target) {
				continue;
			}

			$uuid = trim((string)($data['id'] ?? $data['uuid'] ?? ''));
			if ($uuid !== '') {
				return $uuid;
			}
		}

		return null;
	}//end existingAcceptanceId()

	/**
	 * OpenRegister's object service, or null when it is unavailable.
	 *
	 * @return object|null The service.
	 */
	private function objectService(): ?object {
		try {
			$service = $this->settingsService->getObjectService();
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === false || is_callable([$service, 'saveObject']) === false) {
			return null;
		}

		return $service;
	}//end objectService()

	/**
	 * The roles dossiq offers on a case, which stay dossiq's.
	 *
	 * A KIND is what a party is; a ROLE is what it does on this case. pipelinq
	 * owns the first because the fleet shares parties, and dossiq owns the
	 * second because `aanvrager` and `gemachtigde` are Awb words about a case.
	 * The two are not merged here, deliberately.
	 *
	 * @return array<int, array<string, mixed>> The `linkRoles` entries.
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
	 */
	public function roles(): array {
		return $this->vocabulary->roles();
	}//end roles()
}//end class
