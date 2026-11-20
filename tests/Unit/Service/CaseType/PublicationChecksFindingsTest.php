<?php

/**
 * Unit tests for PublicationChecks: the status, field, handling and cycle findings, and the publication warnings.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-type-publish-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\Beschikking\RemedyClauseDeclaration;
use OCA\Dossiq\Service\CaseType\CaseTypeDeclarationChecks;
use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use OCA\Dossiq\Service\CaseType\CaseTypeReachability;
use OCA\Dossiq\Service\CaseType\PublicationChecks;
use OCA\Dossiq\Service\CaseTypeAcknowledgement;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Email\SenderIdentity;
use OCA\Dossiq\Service\Intake\AdmissibilityJudgement;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\UnreadTriggerService;
use OCA\Dossiq\Tests\Unit\Service\Transitions\SourceL10n;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * Unit tests for the findings and warnings of PublicationChecks.
 *
 * @covers \OCA\Dossiq\Service\CaseType\PublicationChecks
 * @uses   \OCA\Dossiq\Service\Beschikking\RemedyClauseDeclaration
 * @uses   \OCA\Dossiq\Service\CaseType\CaseTypeDeclarationChecks
 * @uses   \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 * @uses   \OCA\Dossiq\Service\CaseType\InheritedRowValues
 * @uses   \OCA\Dossiq\Service\CaseType\RowOrigins
 * @uses   \OCA\Dossiq\Service\CaseTypeAcknowledgement
 * @uses   \OCA\Dossiq\Service\CaseTypeResolver
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\Support\SerialisedResult
 * @uses   \OCA\Dossiq\Service\Support\StoredArray
 * @uses   \OCA\Dossiq\Service\UnreadTriggerService
 */
class PublicationChecksFindingsTest extends TestCase {

	/**
	 * Rows by schema, then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * A complete case type starting in `s1`, closing in `s2`, with no workflow template.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [
			'caseType' => [
				'ct' => [
					'id' => 'ct',
					'title' => ['nl' => 'Bezwaar', 'en' => ''],
					'purpose' => 'Bezwaar behandelen',
					'trigger' => 'Aanvraag',
					'subject' => 'Besluit',
					'processingDeadline' => 'P6W',
					'origin' => 'extern',
					'confidentiality' => 'openbaar',
					'responsibleUnit' => 'Juridische zaken',
					'isDraft' => true,
					'initialStatus' => 's1',
				],
			],
			'statusType' => [
				's1' => ['id' => 's1', 'name' => 'Ontvangen', 'caseType' => 'ct', 'order' => 1],
				's2' => ['id' => 's2', 'name' => 'Afgehandeld', 'caseType' => 'ct', 'order' => 2, 'isFinal' => 'true'],
			],
			'workflowTemplate' => [],
		];
		}//end setUp()

	/**
	 * The checks over an in-memory store and the real declaration readers.
	 *
	 * @return PublicationChecks The checks.
	 */
	private function checks(): PublicationChecks {
		$objectService = new class($this->rows) {
			/**
			 * @param array<string, array<string, array<string, mixed>>> $rows The rows.
			 */
			public function __construct(private array $rows) {
			}//end __construct()

			/**
			 * Read one row.
			 *
			 * @param string $id The id.
			 * @param ?ObjectFindOptions $options The options.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return array<string, mixed>|null The row.
			 */
			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): ?array {
				$register = $options?->register;
				$schema = $options?->schema;
				return ($this->rows[(string)$schema][$id] ?? null);
			}//end find()

			/**
			 * Search one schema on its bare `caseType` filter.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjects(array $query): array {
				$rows = ($this->rows[(string)($query['@self']['schema'] ?? '')] ?? []);
				if (isset($query['caseType']) === true) {
					$rows = array_filter($rows, static fn (array $row): bool => ($row['caseType'] ?? '') === $query['caseType']);
				}

				return array_values($rows);
			}//end searchObjects()
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default=''): string => match ($key) {
				'register' => 'dossiq',
				'case_type_schema' => 'caseType',
				'status_type_schema' => 'statusType',
				'workflow_template_schema' => 'workflowTemplate',
				default => $default,
			}
		);
		$store = new CaseTypeStore(settingsService: $settings);

		return new PublicationChecks(
			caseTypeResolver: new CaseTypeResolver(store: $store),
			store: $store,
			declarations: new CaseTypeDeclarationChecks(
				acknowledgement: new CaseTypeAcknowledgement(l10n: new SourceL10n()),
				admissibility: $this->createMock(originalClassName: AdmissibilityJudgement::class),
				remedy: new RemedyClauseDeclaration(l10n: new SourceL10n()),
				handling: new CaseTypeHandling(),
			),
			unreadTriggers: new UnreadTriggerService(l10n: new SourceL10n()),
			reachability: new CaseTypeReachability(),
			senders: $this->createMock(originalClassName: SenderIdentity::class),
		);
	}//end checks()

	/**
	 * A complete case type without a workflow template has no findings.
	 *
	 * @return void
	 */
	public function testACompleteCaseTypeHasNoFindings(): void {
		self::assertSame(expected: [], actual: $this->checks()->validate(caseTypeId: 'ct'));
	}//end testACompleteCaseTypeHasNoFindings()

	/**
	 * A case type without statuses is told to add one, and nothing about final or initial statuses.
	 *
	 * @return void
	 */
	public function testNoStatusesIsOneFinding(): void {
		$this->rows['statusType'] = [];

		self::assertSame(expected: ['Give the case type at least one status.'], actual: $this->checks()->validate(caseTypeId: 'ct'));
	}//end testNoStatusesIsOneFinding()

	/**
	 * Statuses without a final one, and an initial status the type does not have, are both named.
	 *
	 * @return void
	 */
	public function testNoFinalStatusAndAForeignInitialStatus(): void {
		$this->rows['statusType']['s2']['isFinal'] = 'no';
		$this->rows['caseType']['ct']['initialStatus'] = 'elsewhere';

		self::assertSame(
			expected: [
				'Mark one of the statuses as the final one, so a case can close.',
				'Pick the status a new case of this type starts in.',
			],
			actual: $this->checks()->validate(caseTypeId: 'ct')
		);
	}//end testNoFinalStatusAndAForeignInitialStatus()

	/**
	 * A case type naming no initial status is told to pick one.
	 *
	 * @return void
	 */
	public function testNoInitialStatusIsAFinding(): void {
		unset($this->rows['caseType']['ct']['initialStatus']);

		self::assertSame(expected: ['Pick the status a new case of this type starts in.'], actual: $this->checks()->validate(caseTypeId: 'ct'));
	}//end testNoInitialStatusIsAFinding()

	/**
	 * Every empty required field is named, in the order of REQUIRED_FIELDS.
	 *
	 * @return void
	 */
	public function testEmptyRequiredFieldsAreNamedInOrder(): void {
		$this->rows['caseType']['ct']['title'] = ['nl' => '', 'en' => ''];
		unset($this->rows['caseType']['ct']['responsibleUnit'], $this->rows['caseType']['ct']['trigger']);

		self::assertSame(
			expected: [
				'Give the case type a title.',
				'Give the case type a trigger.',
				'Give the case type a responsible unit.',
			],
			actual: $this->checks()->validate(caseTypeId: 'ct')
		);
	}//end testEmptyRequiredFieldsAreNamedInOrder()

	/**
	 * A handling switch nothing reads is a finding that names it.
	 *
	 * @return void
	 */
	public function testAnUnreadHandlingSwitchIsAFinding(): void {
		$this->rows['caseType']['ct']['handling'] = ['defaultGroup' => 'juristen', 'autoClose' => true];

		self::assertSame(
			expected: ['Nothing reads the handling switch "autoClose". Remove it, or name a switch that is read.'],
			actual: $this->checks()->validate(caseTypeId: 'ct')
		);
	}//end testAnUnreadHandlingSwitchIsAFinding()

	/**
	 * A parent chain that returns to the case type is refused with the resolver's message.
	 *
	 * @return void
	 */
	public function testAParentChainThatLoopsIsAFinding(): void {
		$this->rows['caseType']['ct']['parentCaseType'] = 'parent';
		$this->rows['caseType']['parent'] = ['id' => 'parent', 'title' => 'Ouder', 'parentCaseType' => 'ct'];

		$cycles = array_values(
			array_filter(
				$this->checks()->validate(caseTypeId: 'ct'),
				static fn (string $finding): bool => str_starts_with($finding, 'A case type cannot inherit from itself: ')
			)
		);

		self::assertSame(expected: ['A case type cannot inherit from itself: Bezwaar -> Ouder -> Bezwaar'], actual: $cycles);
	}//end testAParentChainThatLoopsIsAFinding()

	/**
	 * A sound parent chain is no finding.
	 *
	 * @return void
	 */
	public function testASoundParentChainIsNoFinding(): void {
		$this->rows['caseType']['ct']['parentCaseType'] = 'parent';
		$this->rows['caseType']['parent'] = ['id' => 'parent', 'title' => 'Ouder'];

		self::assertSame(expected: [], actual: $this->checks()->validate(caseTypeId: 'ct'));
	}//end testASoundParentChainIsNoFinding()

	/**
	 * A case type that switches off the acknowledgement of receipt is warned, not refused.
	 *
	 * @return void
	 */
	public function testSwitchingOffTheAcknowledgementIsAWarning(): void {
		$this->rows['caseType']['ct']['acknowledgement'] = ['enabled' => false];
		$checks = $this->checks();

		self::assertContains(
			needle: 'This case type sends no acknowledgement of receipt. Awb 4:3a asks for one on every electronic submission.',
			haystack: $checks->warnings(caseTypeId: 'ct')
		);
		self::assertSame(expected: [], actual: $checks->validate(caseTypeId: 'ct'));
	}//end testSwitchingOffTheAcknowledgementIsAWarning()
}//end class
