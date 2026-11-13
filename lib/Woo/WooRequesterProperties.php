<?php

/**
 * Dossiq Woo Requester Properties (site-woo-request-in-steps)
 *
 * The requester details a Woo request carries, written as answers to the
 * questions the case type itself asks. The request keeps what the resident
 * filled in at the time; the case's own `properties` bag is where a handler
 * reads it on the case screen, beside the question. Both, because they answer
 * different questions, and neither is derived from the other.
 *
 * Its own class rather than a method on {@see WooRequestIntake}: that class is
 * at the complexity phpmd refuses, and this is a read of the case type that
 * has nothing to do with writing a case.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
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
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-woo-request-keeps-what-kind-of-documents-and-which-requester-req-sws-010
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Service\Intake\CaseTypeProperties;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The requester details as entries of a case's `properties` bag.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-woo-request-keeps-what-kind-of-documents-and-which-requester-req-sws-010
 */
class WooRequesterProperties {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Names the propertyDefinition schema.
	 * @param LoggerInterface $logger          Says when the definitions cannot be read.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The entries for one case type, or [] when there are none to write.
	 *
	 * A definition the type does not declare is skipped rather than invented,
	 * and a read that fails costs the entries and not the request: the same
	 * answers are kept on `wooRequest` either way.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param string               $register      The dossiq register.
	 * @param string               $caseTypeId    The case type whose questions these answer.
	 * @param array<string, mixed> $wooRequest    The normalised request.
	 *
	 * @return array<int, array<string, string>> The entries.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-woo-request-keeps-what-kind-of-documents-and-which-requester-req-sws-010
	 */
	public function forCaseType(object $objectService, string $register, string $caseTypeId, array $wooRequest): array {
		$answers = [];
		foreach (WooRequestForm::REQUESTER_FIELDS as $field) {
			$answers[$field] = ($wooRequest[$field] ?? '');
		}

		try {
			return (new CaseTypeProperties(settingsService: $this->settingsService))
				->forAnswers(objectService: $objectService, register: $register, caseTypeId: $caseTypeId, answers: $answers);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WooRequestIntake: the case type property definitions could not be read, so the requester details are kept on the request only',
				['error' => $e->getMessage()]
			);
			return [];
		}
	}//end forCaseType()
}//end class
