<?php

/**
 * Dossiq advice request filing.
 *
 * What a new advice request writes: the adviceRequest object in OpenRegister,
 * then the decidesk `advice` Decision it is made in, whose reference is stored
 * back on the request. Fails closed when decidesk does not answer.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Advice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/remaining-decision-delegation/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Advice;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Saves a new advice request and raises the decidesk Decision it is made in.
 *
 * @spec openspec/specs/remaining-decision-delegation/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class AdviceRequestFiling {

	/**
	 * Constructor.
	 *
	 * @param AdviceDelegationService $delegation      Advice delegation to decidesk (ADR-019).
	 * @param SettingsService         $settingsService Names the adviceRequest schema.
	 * @param LoggerInterface         $logger          The logger.
	 */
	public function __construct(
		private readonly AdviceDelegationService $delegation,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Save the advice request, raise its decidesk Decision and store the reference on it.
	 *
	 * @param array{objectService: object, register: string, data: array, payload: array} $advice The OpenRegister ObjectService,
	 *        the register id, the request data and the adviceRequest payload.
	 *
	 * @return array<string, mixed> The stored record, or [] when the save answered no array.
	 *
	 * @throws RuntimeException If decidesk fails closed
	 *
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function file(array $advice): array {
		$savedRecord     = $this->savedAdvice(advice: $advice);
		$advice['saved'] = $savedRecord;

		$this->delegateAdviceDecision(advice: $advice);

		return $savedRecord;
	}//end file()

	/**
	 * Save the adviceRequest payload and answer the stored record.
	 *
	 * @param array{objectService: object, register: string, data: array, payload: array} $advice The OpenRegister ObjectService,
	 *        the register id, the request data and the adviceRequest payload.
	 *
	 * @return array<string, mixed> The stored record, or [] when the save answered no array.
	 *
	 * @spec openspec/changes/archive/2026-06-13-vth-module/tasks.md#task-6
	 */
	private function savedAdvice(array $advice): array {
		$saved = $advice['objectService']->saveObject(
			object: $advice['payload'],
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $advice['register'], schema: $this->adviceSchema()))
		);

		$savedRecord = [];
		if (is_array($saved) === true) {
			$savedRecord = $saved;
		}

		return $savedRecord;
	}//end savedAdvice()


	/**
	 * Raise the decidesk `advice` Decision for a new advice request and
	 * persist its reference on the saved adviceRequest.
	 *
	 * The advice request travels as requestAdvice() wrote it: `objectService` (the
	 * OpenRegister ObjectService), `register` (the register id), `data` (the advice
	 * request data), `payload` (the persisted adviceRequest payload) and `saved`
	 * (the normalized saveObject() result).
	 *
	 * @param array<string, mixed> $advice The advice request.
	 *
	 * @return void
	 *
	 * @throws RuntimeException If decidesk fails closed
	 */
	private function delegateAdviceDecision(array $advice): void {
		// REQ-PDRD-001 / REQ-PDRD-002: the advice is *made* in decidesk. Raise a
		// decidesk `advice` Decision for this request and persist its ref. Fail
		// CLOSED — never author an advice outcome locally as a fallback.
		$saved = $advice['saved'];
		$adviceId = (string)($saved['id'] ?? ($saved['uuid'] ?? ''));
		$subjectId = (string)$advice['payload']['case'];
		if ($adviceId !== '') {
			$subjectId = $adviceId;
		}

		try {
			$decisionRef = $this->delegation->raiseAdviceDecision(
				subjectSchema: 'adviesAanvraag',
				subjectId: $subjectId,
				payload: $this->decisionPayload(advice: $advice),
			);

			if ($adviceId !== '') {
				$this->storeDecisionRef(advice: $advice + ['adviceId' => $adviceId], decisionRef: $decisionRef);
			}
		} catch (RuntimeException $e) {
			$this->logger->error(
				'Dossiq: requestAdvice: decidesk advice Decision raise failed — failing closed: ' . $e->getMessage(),
				['app' => AppIdentity::APP_ID]
			);
			// REQ-PDRD-002: fail closed; surface the error.
			throw new RuntimeException('Decision service unavailable: ' . $e->getMessage(), 0, $e);
		}//end try
	}//end delegateAdviceDecision()


	/**
	 * Persist the decidesk Decision reference on the saved adviceRequest.
	 *
	 * @param array<string, mixed> $advice      The advice request, with its `adviceId`.
	 * @param string               $decisionRef The decidesk Decision reference.
	 *
	 * @return void
	 */
	private function storeDecisionRef(array $advice, string $decisionRef): void {
		$advice['objectService']->saveObject(
			object: array_merge($advice['saved'], ['decisionRef' => $decisionRef]),
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $advice['register'], schema: $this->adviceSchema()),
				uuid: $advice['adviceId']
			)
		);
	}//end storeDecisionRef()


	/**
	 * What decidesk is told about the advice request.
	 *
	 * @param array<string, mixed> $advice See {@see self::delegateAdviceDecision()}.
	 *
	 * @return array<string, string>
	 */
	private function decisionPayload(array $advice): array {
		$data = $advice['data'];

		return [
			'subjectRegister' => $advice['register'],
			'externalReference' => (string)$advice['payload']['case'],
			'subjectLabel' => (string)($data['question'] ?? 'Adviesaanvraag'),
			'question' => (string)($data['question'] ?? ''),
			'advisor' => (string)$advice['payload']['advisor'],
		];
	}//end decisionPayload()


	/**
	 * The configured advice request schema.
	 *
	 * @return string The schema id or slug.
	 */
	private function adviceSchema(): string {
		$schema = (string)$this->settingsService->getConfigValue('advies_aanvraag_schema');
		if ($schema === '') {
			return 'adviesAanvraag';
		}

		return $schema;
	}//end adviceSchema()
}//end class
