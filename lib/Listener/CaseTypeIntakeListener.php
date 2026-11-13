<?php

/**
 * Dossiq Case Type Intake Listener
 *
 * Runs a case type's `portalIntake` on a received case of that type,
 * whichever path wrote it (decision a1): before the case is stored, the
 * origin, the party and the property entries; once it exists, the link to
 * the resident's dossier, queued and checked for ownership by
 * LinkCollectionToCaseJob. A draft is not received and is left alone
 * (decision 180), as is a case type without the intake.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-intake-per-case-type/specs/case-intake-destination/spec.md#requirement-a-case-type-may-say-what-its-intake-does-to-a-received-case
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Intake\CaseTypeIntake;
use OCA\Dossiq\Service\Intake\CaseTypeProperties;
use OCA\Dossiq\Service\Intake\CollectionLinkQueue;
use OCA\Dossiq\Service\Intake\ReceiptMoment;
use OCA\Dossiq\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies a case type's intake to a received case.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/case-intake-per-case-type/specs/case-intake-destination/spec.md#requirement-a-case-type-may-say-what-its-intake-does-to-a-received-case
 */
class CaseTypeIntakeListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SettingsService          $settingsService  The case schema, the register and the object service.
	 * @param CaseTypeResolver         $caseTypeResolver The effective case type.
	 * @param LoggerInterface          $logger           Says when a case could not be read.
	 * @param CollectionLinkQueue|null $linkQueue        Links the case to the dossier once it exists.
	 * @param CaseTypeIntake           $intake           What the intake changes.
	 * @param ReceiptMoment            $receipt          Tells a received case from a draft.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeResolver $caseTypeResolver,
		private readonly LoggerInterface $logger,
		private readonly ?CollectionLinkQueue $linkQueue = null,
		private readonly CaseTypeIntake $intake = new CaseTypeIntake(),
		private readonly ReceiptMoment $receipt = new ReceiptMoment(),
	) {
	}//end __construct()

	/**
	 * Shape a case about to be received; link one that was.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-intake-per-case-type/specs/case-intake-destination/spec.md#requirement-a-case-type-may-say-what-its-intake-does-to-a-received-case
	 */
	public function handle(Event $event): void {
		$entity = $this->receipt->objectBeingReceived(event: $event);
		if ($entity !== null) {
			try {
				$case = $entity->jsonSerialize();
			} catch (Throwable $e) {
				$this->logger->debug('Dossiq intake: a received case could not be read', ['exception' => $e->getMessage()]);
				return;
			}

			$changes = $this->changesFor(case: $case);
			if ($changes !== []) {
				$entity->setObject(array_merge($entity->getObject(), $changes));
			}

			return;
		}

		$case = $this->receipt->receivedObject(event: $event, for: self::class);
		if ($case !== null) {
			$this->linkDossier(case: $case);
		}
	}//end handle()

	/**
	 * The intake's changes for a case of the case schema, or [].
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function changesFor(array $case): array {
		$config = $this->configFor(case: $case);
		if ($config === []) {
			return [];
		}

		$objectService = $this->settingsService->getObjectService();
		$register      = (string)$this->settingsService->getConfigValue('register');
		$caseTypeId    = $this->caseTypeIdOf(case: $case);
		$properties    = new CaseTypeProperties(settingsService: $this->settingsService);
		$logger        = $this->logger;

		return $this->intake->changesFor(
			case: $case,
			config: $config,
			propertiesOf: static function (array $answers) use ($objectService, $register, $caseTypeId, $properties, $logger): array {
				if ($objectService === null || $register === '') {
					return [];
				}

				try {
					return $properties->forAnswers(objectService: $objectService, register: $register, caseTypeId: $caseTypeId, answers: $answers);
				} catch (Throwable $e) {
					// The case is received without the entries; the answers stay on it.
					$logger->warning(
						'Dossiq intake: the case type property definitions could not be read',
						['caseType' => $caseTypeId, 'error' => $e->getMessage()]
					);
					return [];
				}
			}
		);
	}//end changesFor()

	/**
	 * Queue the link to the dossier the answers name, if any.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return void
	 */
	private function linkDossier(array $case): void {
		$dossier = $this->intake->dossierOf(case: $case, config: $this->configFor(case: $case));
		$caseId  = trim((string)($case['id'] ?? ($case['@self']['id'] ?? '')));
		$subject = trim((string)($case['portalSubject'] ?? ''));
		$register = (string)$this->settingsService->getConfigValue('register');
		if ($dossier === '' || $caseId === '' || $subject === '' || $register === '' || $this->linkQueue === null) {
			return;
		}

		$this->linkQueue->queue(register: $register, caseId: $caseId, collectionId: $dossier, subjectRef: $subject);
	}//end linkDossier()

	/**
	 * The intake of the case's type, or [] for anything else.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function configFor(array $case): array {
		$expected  = (string)$this->settingsService->getConfigValue('case_schema');
		$candidate = (string)($case['@self']['schema'] ?? ($case['schema'] ?? ''));
		if ($expected === '' || ($candidate !== $expected && str_ends_with($candidate, '/' . $expected) === false)) {
			return [];
		}

		$caseTypeId = $this->caseTypeIdOf(case: $case);
		if ($caseTypeId === '') {
			return [];
		}

		return $this->intake->configOf(caseType: $this->caseTypeResolver->effectiveCaseType(caseTypeId: $caseTypeId));
	}//end configFor()

	/**
	 * The case type id of a case, or ''.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return string
	 */
	private function caseTypeIdOf(array $case): string {
		$value = ($case['caseType'] ?? '');
		if (is_array($value) === true) {
			return trim((string)($value['id'] ?? ($value['@self']['id'] ?? '')));
		}

		return trim((string)$value);
	}//end caseTypeIdOf()
}//end class
