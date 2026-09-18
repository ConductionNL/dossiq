<?php

/**
 * The link a split leaves between the two halves.
 *
 * REQ-SPL-02 has two halves and both are easy to get half right. The relation
 * has to be one dossiq ALREADY stores, so the Related tab can read it without
 * a second codec, and it has to be readable from the case it was written on,
 * which means the encoded SHAPE matters as much as the value: `relatedCases`
 * is a JSON-encoded string of typed relations and never an array of ids. An
 * array there stores something nothing reads, and the tab is empty on every
 * split with nothing anywhere reporting it.
 *
 * MUTATION-CHECKED 2026-09-18: writing `relatedCases` as a plain array instead
 * of a JSON string reddens testTheNewCaseNamesTheOriginalInTheShapeTheTabReads
 * on the decode; changing the relation to a type `CaseRelationService` does not
 * declare reddens testTheRelationIsOneDossiqAlreadyStores. Restored after.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Split\CaseSplitService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Each half names the other, in the shape the Related tab reads.
 *
 * @covers \OCA\Dossiq\Service\Split\CaseSplitService
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class CaseSplitRelationTest extends TestCase {

	/**
	 * The store the service reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * One case with one document to divide out of it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'caseType', uuid: 'ct-1', row: ['title' => 'Melding']);
		$this->store->seed(
			schema: 'case',
			uuid: 'case-1',
			row: ['title' => 'Twee klachten', 'caseType' => 'ct-1'],
		);
		$this->store->seed(schema: 'caseDocument', uuid: 'doc-1', row: ['case' => 'case-1', 'title' => 'doc-1']);
	}//end setUp()

	/**
	 * The new case names the original, decodable by the Related tab's codec.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-the-split-is-related-in-both-directions-req-spl-02
	 */
	public function testTheNewCaseNamesTheOriginalInTheShapeTheTabReads(): void {
		$answer = $this->splits()->split(
			caseId: 'case-1',
			title: 'De tweede klacht',
			chosen: ['documents' => ['doc-1']],
			actor: 'jan',
		);

		$related = ($answer['case']['relatedCases'] ?? null);
		self::assertIsString(
			$related,
			'`relatedCases` is a JSON-encoded string of typed relations; an array stores a shape nothing reads.',
		);

		$decoded = json_decode((string)$related, true);
		self::assertIsArray($decoded);
		self::assertSame('case-1', $decoded[0]['caseId'], 'The new half names the original.');
		self::assertStringContainsString(
			'Split',
			(string)$decoded[0]['toelichting'],
			'And says the link came from a split rather than from a copy or a follow-up somebody made by hand.',
		);
	}//end testTheNewCaseNamesTheOriginalInTheShapeTheTabReads()

	/**
	 * The original names the new half too, without a query.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-the-split-is-related-in-both-directions-req-spl-02
	 */
	public function testTheOriginalNamesTheNewHalf(): void {
		$answer = $this->splits()->split(
			caseId: 'case-1',
			title: 'De tweede klacht',
			chosen: ['documents' => ['doc-1']],
			actor: 'jan',
		);

		$original = $this->store->row(schema: 'case', uuid: 'case-1');

		self::assertSame(
			(string)$answer['case']['id'],
			(string)$original['splitInto'],
			'Each case names the other, so neither needs a query to find its other half.',
		);
	}//end testTheOriginalNamesTheNewHalf()

	/**
	 * The relation is one dossiq already stores, not a private link.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-the-split-is-related-in-both-directions-req-spl-02
	 */
	public function testTheRelationIsOneDossiqAlreadyStores(): void {
		self::assertContains(
			CaseSplitService::RELATION,
			CaseRelationService::RELATION_TYPES,
			'A split that invented its own type would be a link the Related tab cannot label.',
		);

		$answer = $this->splits()->split(
			caseId: 'case-1',
			title: 'De tweede klacht',
			chosen: ['documents' => ['doc-1']],
			actor: 'jan',
		);
		$decoded = json_decode((string)$answer['case']['relatedCases'], true);

		self::assertSame(CaseSplitService::RELATION, $decoded[0]['aardRelatie']);
	}//end testTheRelationIsOneDossiqAlreadyStores()

	/**
	 * The service under test.
	 *
	 * @return CaseSplitService The service.
	 */
	private function splits(): CaseSplitService {
		return new CaseSplitService(
			settingsService: $this->settings(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end splits()

	/**
	 * A settings service that answers the in-memory store and the slugs it holds.
	 *
	 * @return SettingsService The settings service.
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_type_schema' => 'caseType',
					'case_document_schema' => 'caseDocument',
					'role_schema' => 'role',
				];

				return ($map[$key] ?? $default);
			}
		);

		return $settings;
	}//end settings()
}//end class
