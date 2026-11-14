<?php

/**
 * A filinq that refuses exactly as filinq does.
 *
 * Its anonymisation runs filinq's REAL review guard (`RedactionOutputGuard`,
 * `RedactionReviewGate`, `DetectionRunIdentity`, copied verbatim under
 * tests/Stubs/Filinq) before anything else, then filinq's detector gate, in
 * the order filinq's `AnonymizationService::runAnonymize()` runs them. So a
 * test that forgets the review mark, or marks a different entity list, is
 * refused by filinq's own rule, not by an assertion written for the test.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\Filinq\Exception\RedactionNotReviewedException;
use OCA\Filinq\Service\AnonymizeOptions;
use Exception;
use OCA\Filinq\Exception\DetectionUnavailableException;
use OCA\Filinq\Service\Redaction\DetectionRunIdentity;
use OCA\Filinq\Service\Redaction\RedactionOutputGuard;
use OCA\Filinq\Service\Redaction\RedactionReviewGate;
use OCA\Filinq\Service\Redaction\RedactionReviewMarkRepository;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Filinq's anonymisation service, behind filinq's real review gate.
 */
class FakeFilinq {

	/**
	 * What detection finds, in filinq's normalised shape.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $entities = [];

	/**
	 * Whether OpenRegister has a live entity detector.
	 *
	 * @var bool
	 */
	public bool $detectorLive = true;

	/**
	 * The file id the anonymised copy gets, or null for a run that names none.
	 *
	 * @var int|null
	 */
	public ?int $outputFileId = 9001;

	/**
	 * Every anonymisation that got past both gates.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $anonymised = [];

	/**
	 * Whether filinq's four-eyes setting applies to Woo runs.
	 *
	 * @var bool
	 */
	public bool $fourEyes = false;

	/**
	 * Who checked the findings, for the four-eyes refusal.
	 *
	 * @var string
	 */
	public string $checkedBy = '';

	/**
	 * Where the review marks are kept.
	 *
	 * @var RedactionReviewMarkRepository
	 */
	public RedactionReviewMarkRepository $marks;

	/**
	 * Filinq's review guard.
	 *
	 * @var RedactionOutputGuard
	 */
	public RedactionOutputGuard $guard;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->marks = new RedactionReviewMarkRepository();
		$this->guard = new RedactionOutputGuard(
			gate: new RedactionReviewGate(),
			marks: $this->marks,
			runs: new DetectionRunIdentity()
		);
	}//end __construct()

	/**
	 * Filinq's detection.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return array<string, mixed>
	 */
	public function extractAndDetectEntities(int $fileId): array {
		return ['entities' => $this->entities, 'entityCount' => count($this->entities), 'fileId' => $fileId];
	}//end extractAndDetectEntities()

	/**
	 * Filinq's anonymisation, with filinq's signature.
	 *
	 * @param int               $fileId       The Nextcloud file id.
	 * @param array<int, mixed> $entities     The entities to redact.
	 * @param string            $outputFormat The output format.
	 * @param array<int, mixed> $unredacted   Entities published unredacted.
	 * @param array<int, mixed> $overrides    Acknowledged overrides.
	 * @param string            $userId       The acting user.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Filinq's signature, kept whole.
	 */
	public function anonymizeDocument(
		int $fileId,
		array $entities,
		mixed $outputFormat = 'pdf-only',
		array $unredacted = [],
		array $overrides = [],
		string $userId = '',
	): array {
		$wooCase = false;
		if ($outputFormat instanceof AnonymizeOptions) {
			$userId  = $outputFormat->userId();
			$wooCase = $outputFormat->wooCase();
		}

		$this->guard->assertMayWrite(fileId: $fileId, entities: $entities);

		// Four eyes (redaction-four-eyes-setting REQ-RWB-09): on a Woo run the
		// person who checked may not also anonymise.
		if ($this->fourEyes === true && $wooCase === true && $this->checkedBy !== '' && $this->checkedBy === $userId) {
			throw new RedactionNotReviewedException(
				reason: 'same_person',
				message: 'You checked the findings of this document yourself. Four eyes are required: a colleague must anonymise it.',
				document: (string)$fileId
			);
		}

		if ($this->detectorLive === false) {
			throw new DetectionUnavailableException(
				reason: DetectionUnavailableException::REASON_DISABLED,
				message: 'Anonymisation refused: entity detection is disabled on this instance.',
				backend: ''
			);
		}

		$this->anonymised[] = ['fileId' => $fileId, 'entities' => $entities, 'userId' => $userId, 'wooCase' => $wooCase];

		$outcome = 'redacted';
		if ($entities === []) {
			$outcome = 'nothing_found';
		}

		return [
			'anonymizedFileId' => $this->outputFileId,
			'detection' => ['ran' => true, 'backend' => 'regex', 'entitiesRedacted' => count($entities), 'outcome' => $outcome],
		];
	}//end anonymizeDocument()

	/**
	 * A container that answers filinq's anonymisation service and review guard, and nothing else.
	 *
	 * @return ContainerInterface
	 */
	public function container(): ContainerInterface {
		$services = [
			'OCA\Filinq\Service\AnonymizationService' => $this,
			'OCA\Filinq\Service\Redaction\RedactionOutputGuard' => $this->guard,
		];

		return new class($services) implements ContainerInterface {

			/**
			 * Constructor.
			 *
			 * @param array<string, object> $services The registered services.
			 */
			public function __construct(private array $services) {
			}

			/**
			 * One service.
			 *
			 * @param string $id The class name.
			 *
			 * @return mixed
			 */
			public function get(string $id): mixed {
				if (isset($this->services[$id]) === false) {
					throw new class('not registered: ' . $id) extends Exception implements NotFoundExceptionInterface {
					};
				}

				return $this->services[$id];
			}

			/**
			 * Whether a service is registered.
			 *
			 * @param string $id The class name.
			 *
			 * @return bool
			 */
			public function has(string $id): bool {
				return isset($this->services[$id]);
			}
		};
	}//end container()
}//end class
