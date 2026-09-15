<?php

/**
 * The seat a case type may insist on before the besluit is signed.
 *
 * The clause behind this is precise: behandelaar and casemanager are different
 * people, and the Awb answer is signed by the second. So a case type may
 * declare that a coordinator has to be named, and the signing act refuses
 * while the seat is empty, naming the rule.
 *
 * 🔑 A CASE TYPE THAT DECLARES NOTHING NEVER ASKS. The default is off, which
 * is the opposite of the acknowledgement duty beside it and deliberately so:
 * the acknowledgement is the law's, and applies whether or not anybody
 * configured it, while the second seat is this organisation's own rule about
 * its own decisions. A product that insists on two people for a melding
 * openbare ruimte is a product nobody uses.
 *
 * 🔴 IT REFUSES, IT DOES NOT FILL THE SEAT. Naming a coordinator automatically
 * at signing time would satisfy the check and defeat the point of it: the
 * requirement exists so a person reads the decision before it goes out, and a
 * seat filled by the code has nobody behind it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\People
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
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Says whether a case needs a coordinator before signing, and refuses when it does.
 *
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */
class CoordinatorRequirement {

	use SearchesObjects;

	/**
	 * The case type field that declares the requirement.
	 *
	 * @var string
	 */
	public const DECLARATION = 'coordinatorRequiredBeforeSigning';

	/**
	 * The rule slug a signing refused for an empty seat carries.
	 *
	 * @var string
	 */
	public const RULE = 'coordinator-seat-empty';

	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param CaseTypeResolver $caseTypes       The effective case type, parents included.
	 * @param CaseSeats        $seats           The seats on the case.
	 * @param LoggerInterface  $logger          Says why the declaration could not be read.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeResolver $caseTypes,
		private readonly CaseSeats $seats,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this case's type asks for a coordinator before signing.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return bool True when the case type declares the requirement.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-type-may-require-a-coordinator-before-signing-req-hand-06
	 */
	public function applies(string $caseId): bool {
		$case = $this->caseOf(caseId: $caseId);
		if ($case === []) {
			return false;
		}

		$caseTypeId = trim((string)($case['caseType'] ?? ''));
		if ($caseTypeId === '') {
			return false;
		}

		try {
			$caseType = $this->caseTypes->effectiveCaseType(caseTypeId: $caseTypeId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq coordinator seat: the case type could not be read, so no requirement was applied',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);

			return false;
		}

		return (($caseType[self::DECLARATION] ?? false) === true);
	}//end applies()

	/**
	 * Refuse the signing of a case whose coordinator seat is empty.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the case type asks for a coordinator and none is named.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-type-may-require-a-coordinator-before-signing-req-hand-06
	 */
	public function requireSeatFilled(string $caseId): void {
		if ($this->applies(caseId: $caseId) === false) {
			return;
		}

		$case = $this->caseOf(caseId: $caseId);
		$seats = $this->seats->seatsOf(case: $case);
		if ($seats['coordinator'] !== '') {
			return;
		}

		throw new RefusedException(
			rule: self::RULE,
			sentence: 'This case type wants a coordinator named before the besluit is signed. '
				. 'Name one on the People tab, then sign.',
			status: RefusedException::STATUS_UNPROCESSABLE,
		);
	}//end requireSeatFilled()

	/**
	 * The stored case, or an empty array when it cannot be read.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed> The case.
	 */
	private function caseOf(string $caseId): array {
		$caseId = trim($caseId);
		if ($caseId === '') {
			return [];
		}

		try {
			$objectService = $this->settingsService->getObjectService();
			if ($objectService === null) {
				throw new RuntimeException('OpenRegister is not available');
			}

			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $this->settingsService->getConfigValue('register'),
				schema: $this->settingsService->getConfigValue('case_schema'),
				id: $caseId,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq coordinator seat: the case could not be read',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);

			return [];
		}

		return ($case ?? []);
	}//end caseOf()
}//end class
