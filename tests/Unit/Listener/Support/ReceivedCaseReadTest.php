<?php

/**
 * ReceivedCaseRead Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener\Support
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
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Kernel\Contracts\CaseTypeSlugResolverInterface;
use OCA\Dossiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Dossiq\Listener\Support\ReceivedCaseRead;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Unit tests for ReceivedCaseRead.
 *
 * @covers \OCA\Dossiq\Listener\Support\ReceivedCaseRead
 * @uses   \OCA\Dossiq\Listener\CreatedCase
 * @uses   \OCA\Dossiq\Listener\NewCaseIdentity
 * @uses   \OCA\Dossiq\Service\Intake\ReceiptMoment
 */
final class ReceivedCaseReadTest extends TestCase {

	/**
	 * Log records as [level, message, context].
	 *
	 * @var array<int,array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $logged = [];

	/**
	 * Build the reader over fixed slugs.
	 *
	 * @param string $schemaSlug   What the schema resolves to.
	 * @param string $caseTypeSlug What the case type resolves to.
	 *
	 * @return ReceivedCaseRead
	 */
	private function reader(string $schemaSlug='case', string $caseTypeSlug='omgevingsvergunning'): ReceivedCaseRead {
		$schemaSlugs = $this->createMock(originalClassName: ObjectSchemaSlugResolverInterface::class);
		$schemaSlugs->method('resolveFromPayload')->willReturn($schemaSlug);
		$caseTypeSlugs = $this->createMock(originalClassName: CaseTypeSlugResolverInterface::class);
		$caseTypeSlugs->method('toSlug')->willReturn($caseTypeSlug);

		$logged = &$this->logged;
		$logger = new class($logged) extends AbstractLogger {
			/**
			 * The records, by reference.
			 *
			 * @var array<int,mixed>
			 */
			private array $records;

			/**
			 * Constructor.
			 *
			 * @param array<int,mixed> $records The records, by reference.
			 */
			public function __construct(array &$records) {
				$this->records = &$records;
			}//end __construct()

			/**
			 * Record one log call.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed>       $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->records[] = [(string)$level, (string)$message, $context];
			}//end log()
		};

		return new ReceivedCaseRead(slugResolver: $schemaSlugs, caseTypeSlugs: $caseTypeSlugs, logger: $logger);
	}//end reader()

	/**
	 * A created object with the given uuid and fields.
	 *
	 * @param string              $uuid   The uuid.
	 * @param array<string,mixed> $fields The object's fields.
	 *
	 * @return ObjectCreatedEvent
	 */
	private static function created(string $uuid, array $fields): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema('case');
		$entity->setObject($fields);

		return new ObjectCreatedEvent($entity);
	}//end created()

	/**
	 * A received case answers its id, case type reference, slug and payload.
	 *
	 * @return void
	 */
	public function testAReceivedCaseAnswersItsIdentityAndPayload(): void {
		$created = $this->reader()->read(event: self::created('case-1', ['caseType' => 'ct-uuid']), for: self::class);

		$this->assertNotNull($created);
		$this->assertSame('case-1', $created->identity->caseId);
		$this->assertSame('ct-uuid', $created->identity->caseTypeRef);
		$this->assertSame('omgevingsvergunning', $created->identity->caseType);
		$this->assertSame('ct-uuid', $created->payload['caseType']);
		$this->assertSame([], $this->logged);
	}//end testAReceivedCaseAnswersItsIdentityAndPayload()

	/**
	 * An event that is no create, an object of another schema, or a case without a case type answers null quietly.
	 *
	 * @return void
	 */
	public function testWhatIsNoReceivedCaseAnswersNullQuietly(): void {
		$this->assertNull($this->reader()->read(event: new Event(), for: self::class));
		$this->assertNull(
			$this->reader(schemaSlug: 'task')->read(event: self::created('task-1', ['caseType' => 'ct-uuid']), for: self::class)
		);
		$this->assertNull($this->reader()->read(event: self::created('case-2', ['title' => 'Zonder type']), for: self::class));
		$this->assertSame([], $this->logged);
	}//end testWhatIsNoReceivedCaseAnswersNullQuietly()

	/**
	 * A case type that resolves to no slug answers null and is warned about.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutASlugIsWarnedAbout(): void {
		$read = $this->reader(caseTypeSlug: '')->read(event: self::created('case-3', ['caseType' => 'ct-gone']), for: self::class);

		$this->assertNull($read);
		$this->assertSame(
			[[
				'warning',
				'Dossiq termijn: the case type behind a new case could not be resolved to a slug, so no statutory term was started',
				['case' => 'case-3', 'caseType' => 'ct-gone'],
			]],
			$this->logged
		);
	}//end testACaseTypeWithoutASlugIsWarnedAbout()
}//end class
