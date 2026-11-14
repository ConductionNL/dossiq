<?php

/**
 * DeadlineCaseCreatedListener guard tests.
 *
 * What the listener does before and around binding a term: an object of
 * another schema, a case without id or case type, a case type that resolves
 * to no slug, and a term service that fails for a reason other than a
 * missing definition.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
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
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Exception\NoTermijnDefinitieException;
use OCA\Dossiq\Listener\DeadlineCaseCreatedListener;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\TermijnService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * A new case binds its term only when it is a case with a resolvable type; failures are logged.
 *
 * @covers \OCA\Dossiq\Listener\DeadlineCaseCreatedListener
 * @uses   \OCA\Dossiq\Service\Intake\ReceiptMoment
 * @uses   \OCA\Dossiq\Service\Support\ReferencedUuid
 * @uses   \OCA\Dossiq\Service\Support\SerialisedResult
 * @uses   \OCA\Dossiq\Listener\BezwaarDecisionListener
 */
class DeadlineCaseCreatedListenerGuardsTest extends TestCase {

	/**
	 * Log records as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The term service.
	 *
	 * @var TermijnService&MockObject
	 */
	private TermijnService $terms;

	/**
	 * The other clocks.
	 *
	 * @var CaseTermsService&MockObject
	 */
	private CaseTermsService $caseTerms;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->terms = $this->createMock(TermijnService::class);
		$this->caseTerms = $this->createMock(CaseTermsService::class);
	}//end setUp()

	/**
	 * The listener with the given schema slug and case type slug.
	 *
	 * @param string $schemaSlug   What the payload's schema resolves to.
	 * @param string $caseTypeSlug What the case type resolves to.
	 *
	 * @return DeadlineCaseCreatedListener
	 */
	private function listener(string $schemaSlug = 'case', string $caseTypeSlug = 'omgevingsvergunning-regulier'): DeadlineCaseCreatedListener {
		$schemaSlugs = $this->createMock(ObjectSchemaSlugResolver::class);
		$schemaSlugs->method('resolveFromPayload')->willReturn($schemaSlug);
		$caseTypeSlugs = $this->createMock(CaseTypeSlugResolver::class);
		$caseTypeSlugs->method('toSlug')->willReturn($caseTypeSlug);

		$logged = &$this->logged;
		$logger = new class($logged) extends AbstractLogger {
			/** @var array<int, array{0: string, 1: string, 2: array<string, mixed>}> */
			private array $records;

			/**
			 * @param array<int, array{0: string, 1: string, 2: array<string, mixed>}> $records The record.
			 */
			public function __construct(array &$records) {
				$this->records = &$records;
			}

			/**
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->records[] = [(string)$level, (string)$message, $context];
			}
		};

		return new DeadlineCaseCreatedListener(
			termService: $this->terms,
			slugResolver: $schemaSlugs,
			caseTypeSlugs: $caseTypeSlugs,
			logger: $logger,
			caseTerms: $this->caseTerms,
		);
	}//end listener()

	/**
	 * A created object with the given uuid and fields.
	 *
	 * @param string               $uuid   The uuid.
	 * @param array<string, mixed> $fields The fields.
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
	 * An object of another schema starts no term.
	 *
	 * @return void
	 */
	public function testAnObjectOfAnotherSchemaStartsNoTerm(): void {
		$this->terms->expects($this->never())->method('createTermijnInstance');
		$this->caseTerms->expects($this->never())->method('bindForCase');

		$this->listener(schemaSlug: 'task')->handle(self::created('task-1', ['caseType' => 'ct-uuid']));

		self::assertSame([], $this->logged);
	}//end testAnObjectOfAnotherSchemaStartsNoTerm()

	/**
	 * A case without a case type starts no term and says nothing.
	 *
	 * @return void
	 */
	public function testACaseWithoutACaseTypeStartsNoTerm(): void {
		$this->terms->expects($this->never())->method('createTermijnInstance');

		$this->listener()->handle(self::created('case-2', ['title' => 'Zonder type']));

		self::assertSame([], $this->logged);
	}//end testACaseWithoutACaseTypeStartsNoTerm()

	/**
	 * A case type that resolves to no slug starts no term, and that is said out loud.
	 *
	 * @return void
	 */
	public function testAnUnresolvableCaseTypeIsWarnedAbout(): void {
		$this->terms->expects($this->never())->method('createTermijnInstance');

		$this->listener(caseTypeSlug: '')->handle(self::created('case-3', ['caseType' => 'ct-gone']));

		self::assertSame(
			[[
				'warning',
				'Dossiq termijn: the case type behind a new case could not be resolved to a slug, so no statutory term was started',
				['case' => 'case-3', 'caseType' => 'ct-gone'],
			]],
			$this->logged
		);
	}//end testAnUnresolvableCaseTypeIsWarnedAbout()

	/**
	 * A term service failing for another reason is an error, and the other clocks are not bound.
	 *
	 * @return void
	 */
	public function testAFailingTermServiceIsAnError(): void {
		$this->terms->method('createTermijnInstance')->willThrowException(new RuntimeException('register locked'));
		$this->caseTerms->expects($this->never())->method('bindForCase');

		$this->listener()->handle(self::created('case-4', ['caseType' => 'ct-uuid']));

		self::assertSame(
			[[
				'error',
				'Dossiq termijn: could not bind a term to case case-4: register locked',
				['case' => 'case-4', 'caseType' => 'omgevingsvergunning-regulier', 'exception' => 'register locked'],
			]],
			$this->logged
		);
	}//end testAFailingTermServiceIsAnError()

	/**
	 * A case type without a definition is warned about and its other clocks still bind.
	 *
	 * @return void
	 */
	public function testNoDefinitionStillBindsTheOtherClocks(): void {
		$this->terms->method('createTermijnInstance')->willThrowException(new NoTermijnDefinitieException(message: 'none'));
		$this->caseTerms->expects($this->once())->method('bindForCase')->willReturn([]);

		$this->listener()->handle(self::created('case-5', ['caseType' => 'ct-uuid']));

		self::assertSame('warning', $this->logged[0][0]);
		self::assertSame(
			'Dossiq termijn: no active TermijnDefinitie for case type "omgevingsvergunning-regulier", so case case-5 runs without a statutory term',
			$this->logged[0][1]
		);
	}//end testNoDefinitionStillBindsTheOtherClocks()
}//end class
