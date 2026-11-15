<?php

/**
 * Tests for the link that lists a recorded decision on its case.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\DecisionDocument
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
 * @spec openspec/specs/case-management/spec.md#requirement-a-recorded-decision-is-listed-on-its-case-req-cm-81
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\DecisionDocument;

use OCA\Dossiq\Service\DecisionDocument\CaseDecisionLink;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A recorded decision is listed on its case once, and a case that cannot be
 * updated costs a logged warning, not the decision.
 *
 * @covers \OCA\Dossiq\Service\DecisionDocument\CaseDecisionLink
 *
 * @spec openspec/specs/case-management/spec.md#requirement-a-recorded-decision-is-listed-on-its-case-req-cm-81
 */
class CaseDecisionLinkTest extends TestCase {

	/**
	 * The stand-in OpenRegister store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The link under test.
	 *
	 * @var CaseDecisionLink
	 */
	private CaseDecisionLink $link;

	/**
	 * Build the link over an in-memory register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'case_schema' ? 'case' : '')
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->link = new CaseDecisionLink(settingsService: $settings, logger: $this->logger);
	}//end setUp()

	/**
	 * The decision is appended to the case's list, keeping the case's other fields.
	 *
	 * @return void
	 */
	public function testTheDecisionIsAppendedToTheCase(): void {
		$this->register->seed(schema: 'case', uuid: 'case-1', row: ['title' => 'Woo-verzoek', 'decisions' => ['dec-1']]);
		$this->logger->expects($this->never())->method('warning');

		$this->link->link(objectService: $this->register, register: 'dossiq', caseId: 'case-1', decisionId: 'dec-2');

		$case = $this->register->row(schema: 'case', uuid: 'case-1');
		$this->assertSame(['dec-1', 'dec-2'], $case['decisions']);
		$this->assertSame('Woo-verzoek', $case['title']);
	}//end testTheDecisionIsAppendedToTheCase()

	/**
	 * A case without a list starts one.
	 *
	 * @return void
	 */
	public function testACaseWithoutDecisionsStartsAList(): void {
		$this->register->seed(schema: 'case', uuid: 'case-1', row: ['title' => 'Woo-verzoek']);

		$this->link->link(objectService: $this->register, register: 'dossiq', caseId: 'case-1', decisionId: 'dec-1');

		$this->assertSame(['dec-1'], $this->register->row(schema: 'case', uuid: 'case-1')['decisions']);
	}//end testACaseWithoutDecisionsStartsAList()

	/**
	 * A decision already listed is not written again.
	 *
	 * @return void
	 */
	public function testAListedDecisionIsNotWrittenTwice(): void {
		$this->register->seed(schema: 'case', uuid: 'case-1', row: ['decisions' => ['dec-1']]);
		$writes = $this->register->writes;

		$this->link->link(objectService: $this->register, register: 'dossiq', caseId: 'case-1', decisionId: 'dec-1');

		$this->assertSame($writes, $this->register->writes);
		$this->assertSame(['dec-1'], $this->register->row(schema: 'case', uuid: 'case-1')['decisions']);
	}//end testAListedDecisionIsNotWrittenTwice()

	/**
	 * Entries that are not decision ids are dropped from the rewritten list.
	 *
	 * @return void
	 */
	public function testNonStringEntriesAreDropped(): void {
		$this->register->seed(schema: 'case', uuid: 'case-1', row: ['decisions' => ['dec-1', ['id' => 'x'], 7]]);

		$this->link->link(objectService: $this->register, register: 'dossiq', caseId: 'case-1', decisionId: 'dec-2');

		$this->assertSame(['dec-1', 'dec-2'], $this->register->row(schema: 'case', uuid: 'case-1')['decisions']);
	}//end testNonStringEntriesAreDropped()

	/**
	 * An empty decision id touches nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyDecisionIdIsIgnored(): void {
		$service = $this->createMock(CaseDecisionLinkObjectService::class);
		$service->expects($this->never())->method('find');
		$service->expects($this->never())->method('patchObject');

		$this->link->link(objectService: $service, register: 'dossiq', caseId: 'case-1', decisionId: '');
	}//end testAnEmptyDecisionIdIsIgnored()

	/**
	 * A case that does not exist is left alone, without a warning.
	 *
	 * @return void
	 */
	public function testAMissingCaseIsLeftAlone(): void {
		$this->logger->expects($this->never())->method('warning');

		$this->link->link(objectService: $this->register, register: 'dossiq', caseId: 'missing', decisionId: 'dec-1');

		$this->assertSame(0, $this->register->writes);
	}//end testAMissingCaseIsLeftAlone()

	/**
	 * A service that patches gets only the decisions field, on the case schema.
	 *
	 * @return void
	 */
	public function testAPatchingServiceGetsOnlyTheDecisions(): void {
		$service = $this->createMock(CaseDecisionLinkObjectService::class);
		$service->method('find')->willReturn(['title' => 'Woo-verzoek', 'decisions' => ['dec-1']]);
		$service->expects($this->once())
			->method('patchObject')
			->with('case-1', ['decisions' => ['dec-1', 'dec-2']], 'dossiq', 'case')
			->willReturn(['decisions' => ['dec-1', 'dec-2']]);

		$this->link->link(objectService: $service, register: 'dossiq', caseId: 'case-1', decisionId: 'dec-2');
	}//end testAPatchingServiceGetsOnlyTheDecisions()

	/**
	 * A case that cannot be updated is logged with its id and the error, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsLoggedNotThrown(): void {
		$service = $this->createMock(CaseDecisionLinkObjectService::class);
		$service->method('find')->willReturn(['decisions' => []]);
		$service->method('patchObject')->willThrowException(new RuntimeException('locked'));

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'CaseDecisionLink: the case could not list its recorded decision',
				['app' => 'dossiq', 'caseId' => 'case-1', 'error' => 'locked']
			);

		$this->link->link(objectService: $service, register: 'dossiq', caseId: 'case-1', decisionId: 'dec-1');
	}//end testAFailingWriteIsLoggedNotThrown()

}//end class

/**
 * The slice of OpenRegister's ObjectService the link calls, for mocking.
 */
class CaseDecisionLinkObjectService {

	/**
	 * Find one object.
	 *
	 * @param string     $id       The id.
	 * @param int|string $register The register.
	 * @param int|string $schema   The schema.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $id, int|string $register, int|string $schema): ?array {
		return null;
	}//end find()

	/**
	 * Patch one object.
	 *
	 * @param string               $objectId The id.
	 * @param array<string, mixed> $data     The changes.
	 * @param int|string           $register The register.
	 * @param int|string           $schema   The schema.
	 *
	 * @return array<string, mixed>|null
	 */
	public function patchObject(string $objectId, array $data, int|string $register, int|string $schema): ?array {
		return null;
	}//end patchObject()
}//end class
