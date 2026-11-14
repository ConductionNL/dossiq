<?php

/**
 * Unit tests for the cases DeadlineCaseCreatedListener does not bind a term
 * to, and for its failures: other schemas, incomplete cases, an unresolvable
 * case type, a failing term write and a failing bind of the other clocks.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\DeadlineCaseCreatedListener;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\TermijnService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Cases that get no term, and failures.
 */
class DeadlineCaseCreatedListenerRefusalsTest extends TestCase {

	/**
	 * Mock term service.
	 *
	 * @var TermijnService&MockObject
	 */
	private TermijnService&MockObject $terms;

	/**
	 * Mock case terms service.
	 *
	 * @var CaseTermsService&MockObject
	 */
	private CaseTermsService&MockObject $caseTerms;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->terms = $this->createMock(TermijnService::class);
		$this->caseTerms = $this->createMock(CaseTermsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the listener.
	 *
	 * @param string $schemaSlug What the payload's schema resolves to
	 * @param string $caseTypeSlug What the case type resolves to
	 *
	 * @return DeadlineCaseCreatedListener
	 */
	private function listener(string $schemaSlug = 'case', string $caseTypeSlug = 'omgevingsvergunning-regulier'): DeadlineCaseCreatedListener {
		$schemaSlugs = $this->createMock(ObjectSchemaSlugResolver::class);
		$schemaSlugs->method('resolveFromPayload')->willReturn($schemaSlug);
		$caseTypeSlugs = $this->createMock(CaseTypeSlugResolver::class);
		$caseTypeSlugs->method('toSlug')->willReturn($caseTypeSlug);

		return new DeadlineCaseCreatedListener(
			termService: $this->terms,
			slugResolver: $schemaSlugs,
			caseTypeSlugs: $caseTypeSlugs,
			logger: $this->logger,
			caseTerms: $this->caseTerms,
		);

	}//end listener()

	/**
	 * A created case event.
	 *
	 * @param array<string, mixed> $fields The case fields
	 * @param string $uuid The case uuid
	 *
	 * @return ObjectCreatedEvent
	 */
	private static function created(array $fields, string $uuid = 'case-1'): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema('case');
		$entity->setObject($fields);

		return new ObjectCreatedEvent($entity);

	}//end created()

	/**
	 * Another schema's create binds nothing.
	 *
	 * @return void
	 */
	public function testAnotherSchemaBindsNothing(): void {
		$this->terms->expects($this->never())->method('createTermijnInstance');
		$this->caseTerms->expects($this->never())->method('bindForCase');

		$this->listener(schemaSlug: 'task')->handle(self::created(['caseType' => 'ct-uuid']));

	}//end testAnotherSchemaBindsNothing()

	/**
	 * A case without a case type binds nothing.
	 *
	 * @return void
	 */
	public function testACaseWithoutCaseTypeBindsNothing(): void {
		$this->terms->expects($this->never())->method('createTermijnInstance');

		$this->listener()->handle(self::created(['title' => 'no type']));

	}//end testACaseWithoutCaseTypeBindsNothing()

	/**
	 * A case type that resolves to no slug is warned about and binds nothing.
	 *
	 * @return void
	 */
	public function testAnUnresolvableCaseTypeIsWarnedAbout(): void {
		$this->terms->expects($this->never())->method('createTermijnInstance');
		$this->caseTerms->expects($this->never())->method('bindForCase');
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Dossiq termijn: the case type behind a new case could not be resolved to a slug, so no statutory term was started',
				['case' => 'case-1', 'caseType' => 'ct-unknown']
			);

		$this->listener(caseTypeSlug: '')->handle(self::created(['caseType' => 'ct-unknown']));

	}//end testAnUnresolvableCaseTypeIsWarnedAbout()

	/**
	 * A failing term write is logged as an error and the other clocks are not bound.
	 *
	 * @return void
	 */
	public function testAFailingTermWriteIsLoggedAsAnError(): void {
		$this->terms->method('createTermijnInstance')->willThrowException(new RuntimeException('register down'));
		$this->caseTerms->expects($this->never())->method('bindForCase');
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'Dossiq termijn: could not bind a term to case case-1: register down',
				['case' => 'case-1', 'caseType' => 'omgevingsvergunning-regulier', 'exception' => 'register down']
			);

		$this->listener()->handle(self::created(['caseType' => 'ct-uuid']));

	}//end testAFailingTermWriteIsLoggedAsAnError()

	/**
	 * A failing bind of the planned end and internal target is a warning; the statutory term stays.
	 *
	 * @return void
	 */
	public function testAFailingBindOfTheOtherClocksIsAWarning(): void {
		$this->terms->expects($this->once())
			->method('createTermijnInstance')
			->with('case-1', 'omgevingsvergunning-regulier')
			->willReturn(['id' => 't1']);
		$this->caseTerms->expects($this->once())
			->method('bindForCase')
			->with('case-1', 'ct-uuid', null, '2026-12-01', null)
			->willThrowException(new RuntimeException('no planned definition'));
		$this->logger->expects($this->never())->method('error');
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Dossiq termijn: the planned end and the internal target could not be bound to case case-1',
				['case' => 'case-1', 'caseType' => 'ct-uuid', 'error' => 'no planned definition']
			);

		$this->listener()->handle(self::created(['caseType' => 'ct-uuid', 'plannedStartDate' => ' 2026-12-01 ']));

	}//end testAFailingBindOfTheOtherClocksIsAWarning()
}//end class
