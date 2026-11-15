<?php

/**
 * Unit tests for PublicationChecks: what the reachability pass reads off the store.
 *
 * 🔴 WHAT THESE GUARD IS A LIFECYCLE THAT READS AS CHECKED AND WAS NOT. The
 * authoring page writes a template's `transitions` as a JSON string, so a
 * reader that only accepted a list would see no moves on exactly the case types
 * that have the most, and every reachability finding would silently vanish. A
 * status with no name must still be named in a finding, by its id, or the
 * sentence asks the author to fix a status called "".
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

/**
 * Unit tests for PublicationChecks.
 *
 * @covers \OCA\Dossiq\Service\CaseType\PublicationChecks
 */
class PublicationChecksTest extends TestCase {

	/**
	 * Rows by schema, then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * A case type starting in `s1`, with an unnamed final status and a status with no id.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [
			'caseType' => [
				'ct' => ['id' => 'ct', 'title' => 'Bezwaar', 'isDraft' => true, 'initialStatus' => 's1'],
			],
			'statusType' => [
				's1' => ['id' => 's1', 'name' => 'Ontvangen', 'caseType' => 'ct', 'order' => 1],
				's2' => ['id' => 's2', 'caseType' => 'ct', 'order' => 2, 'isFinal' => true],
				'anon' => ['name' => 'Zonder id', 'caseType' => 'ct', 'order' => 3],
			],
			'workflowTemplate' => [
				'wf' => [
					'id' => 'wf',
					'caseType' => 'ct',
					'isActive' => true,
					'transitions' => json_encode([
						['label' => 'Afronden', 'fromStatus' => 's1', 'toStatus' => 'gone'],
						'not a move',
					]),
				],
			],
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
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed>|null The row.
			 */
			public function find(string $id, $register=null, $schema=null): ?array {
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

		$settings = $this->createMock(SettingsService::class);
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
		$store = new CaseTypeStore($settings);

		return new PublicationChecks(
			caseTypeResolver: new CaseTypeResolver(store: $store),
			store: $store,
			declarations: new CaseTypeDeclarationChecks(
				acknowledgement: new CaseTypeAcknowledgement(l10n: new SourceL10n()),
				admissibility: $this->createMock(AdmissibilityJudgement::class),
				remedy: new RemedyClauseDeclaration(l10n: new SourceL10n()),
				handling: new CaseTypeHandling(),
			),
			unreadTriggers: new UnreadTriggerService(l10n: new SourceL10n()),
			reachability: new CaseTypeReachability(),
			senders: $this->createMock(SenderIdentity::class),
		);
	}//end checks()

	/**
	 * Moves stored as a JSON string are read, and an unnamed status is named by its id.
	 *
	 * The broken move is reported once; `s2` is reported as unreachable under
	 * its id; the status with no id is no status of the lifecycle at all, so
	 * no finding names "Zonder id"; and since nothing reached is final the
	 * lifecycle cannot close.
	 *
	 * @return void
	 */
	public function testMovesStoredAsJsonAreJudged(): void {
		$findings = $this->checks()->validate(caseTypeId: 'ct');

		self::assertContains('The move "Afronden" leads to a status this case type does not have.', $findings);
		self::assertContains(
			'No move leads to the status "s2", so a case can never be in it. Give it a move in, or take it off this case type.',
			$findings
		);
		self::assertContains(
			'No sequence of moves leads from "Ontvangen" to a status that closes a case. A case of this type could never be finished.',
			$findings
		);
		self::assertSame([], array_values(array_filter($findings, static fn (string $f): bool => str_contains($f, 'Zonder id'))));
	}//end testMovesStoredAsJsonAreJudged()

	/**
	 * Transitions that decode to no list are no moves, so nothing is unreachable.
	 *
	 * @return void
	 */
	public function testUndecodableTransitionsAreNoMoves(): void {
		$this->rows['workflowTemplate']['wf']['transitions'] = '{not json';

		$findings = $this->checks()->validate(caseTypeId: 'ct');

		self::assertSame([], array_values(array_filter($findings, static fn (string $f): bool => str_contains($f, 'move'))));
	}//end testUndecodableTransitionsAreNoMoves()

	/**
	 * A case type that cannot be read warns about nothing, and fails validation by name.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseType(): void {
		$checks = $this->checks();

		self::assertSame([], $checks->warnings(caseTypeId: 'missing'));
		self::assertSame(['This case type could not be read.'], $checks->validate(caseTypeId: 'missing'));
	}//end testAnUnreadableCaseType()
}//end class
