<?php

/**
 * Tests for the processing-log row of a case's special categories.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
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
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Dossiq\Kernel\Contracts\TenantAuditTrailServiceInterface;
use OCA\Dossiq\Listener\SpecialCategoryProcessingLogListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The listener writes one row when a case gains or changes its special categories.
 *
 * @covers \OCA\Dossiq\Listener\SpecialCategoryProcessingLogListener
 */
class SpecialCategoryProcessingLogListenerTest extends TestCase {

	/**
	 * The rows written to the processing log.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * The audit trail mock.
	 *
	 * @var TenantAuditTrailServiceInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private TenantAuditTrailServiceInterface $auditTrail;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The listener under test.
	 *
	 * @var SpecialCategoryProcessingLogListener
	 */
	private SpecialCategoryProcessingLogListener $listener;

	/**
	 * Build the listener over a recording processing log, signed in as `anna`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [];
		$this->auditTrail = $this->createMock(originalClassName: TenantAuditTrailServiceInterface::class);
		$this->auditTrail->method('emit')->willReturnCallback(
			function (array $payload): array {
				$this->rows[] = $payload;
				return $payload;
			}
		);

		$resolver = $this->createMock(originalClassName: ObjectSchemaSlugResolverInterface::class);
		$resolver->method('resolveFromPayload')->willReturnCallback(
			static fn (array $payload): string => (string) ($payload['@self']['schema'] ?? '')
		);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->listener = new SpecialCategoryProcessingLogListener(
			auditTrail: $this->auditTrail,
			slugResolver: $resolver,
			userSession: $session,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * A case created with special categories writes one row naming them, the case and the user.
	 *
	 * @return void
	 */
	public function testACaseCreatedWithCategoriesIsLogged(): void {
		$this->listener->handle(new ObjectCreatedEvent($this->entity(record: ['gdprClassification' => ['health', ' criminal ']])));

		self::assertSame(
			expected: [
				[
					'action' => 'case_special_categories_recorded',
					'actor' => 'anna',
					'resource' => 'case:case-1',
					'bio' => ['caseId' => 'case-1', 'categories' => ['criminal', 'health'], 'previous' => []],
				],
			],
			actual: $this->rows
		);
	}//end testACaseCreatedWithCategoriesIsLogged()

	/**
	 * A case without categories, and any other object, writes nothing.
	 *
	 * @return void
	 */
	public function testNothingIsLoggedWithoutCategoriesOrForOtherObjects(): void {
		$this->listener->handle(new ObjectCreatedEvent($this->entity(record: [])));
		$this->listener->handle(new ObjectCreatedEvent($this->entity(record: ['gdprClassification' => ['health']], schema: 'toestemming')));
		$this->listener->handle(new Event());

		self::assertSame(expected: [], actual: $this->rows);
	}//end testNothingIsLoggedWithoutCategoriesOrForOtherObjects()

	/**
	 * An update logs a change of the categories with what they were, and not a save that keeps them.
	 *
	 * @return void
	 */
	public function testAnUpdateLogsOnlyAChange(): void {
		$this->listener->handle(
			$this->updated(before: ['gdprClassification' => ['health', 'religion']], after: ['gdprClassification' => ['religion', 'health'], 'title' => 'b'])
		);
		self::assertSame(expected: [], actual: $this->rows);

		$this->listener->handle($this->updated(before: ['gdprClassification' => ['health']], after: ['gdprClassification' => ['health', 'ethnicity']]));
		self::assertCount(expectedCount: 1, haystack: $this->rows);
		self::assertSame(
			expected: ['caseId' => 'case-1', 'categories' => ['ethnicity', 'health'], 'previous' => ['health']],
			actual: $this->rows[0]['bio']
		);

		$this->listener->handle($this->updated(before: ['gdprClassification' => ['health']], after: []));
		self::assertCount(expectedCount: 2, haystack: $this->rows);
		self::assertSame(expected: ['caseId' => 'case-1', 'categories' => [], 'previous' => ['health']], actual: $this->rows[1]['bio']);
	}//end testAnUpdateLogsOnlyAChange()

	/**
	 * A processing log that refuses the row is logged as a warning and does not stop the save.
	 *
	 * @return void
	 */
	public function testARefusedRowIsWarnedAbout(): void {
		$auditTrail = $this->createMock(originalClassName: TenantAuditTrailServiceInterface::class);
		$auditTrail->method('emit')->willThrowException(new RuntimeException(message: 'down'));
		$resolver = $this->createMock(originalClassName: ObjectSchemaSlugResolverInterface::class);
		$resolver->method('resolveFromPayload')->willReturn('case');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Dossiq: the special categories of a case could not be written to the processing log',
			['caseId' => 'case-1', 'exception' => 'down']
		);

		$listener = new SpecialCategoryProcessingLogListener(auditTrail: $auditTrail, slugResolver: $resolver, userSession: $session, logger: $logger);
		$listener->handle(new ObjectCreatedEvent($this->entity(record: ['gdprClassification' => 'health'])));
	}//end testARefusedRowIsWarnedAbout()

	/**
	 * An object entity serialising to the record.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string               $schema The schema slug.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $record, string $schema = 'case'): ObjectEntity {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(
			array_merge(['@self' => ['id' => 'case-1', 'schema' => $schema]], $record)
		);

		return $entity;
	}//end entity()

	/**
	 * A case update from before to after.
	 *
	 * @param array<string, mixed> $before The case before.
	 * @param array<string, mixed> $after  The case after.
	 *
	 * @return ObjectUpdatedEvent The event.
	 */
	private function updated(array $before, array $after): ObjectUpdatedEvent {
		return new ObjectUpdatedEvent($this->entity(record: $after), $this->entity(record: $before));
	}//end updated()
}//end class
