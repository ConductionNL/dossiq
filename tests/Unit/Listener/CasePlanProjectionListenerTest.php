<?php

/**
 * CasePlanProjectionListener unit tests.
 *
 * The listener is what makes the projection happen AT CASE START; without it
 * `CasePlanProjectionService` is a mapping nobody calls and every CMMN case
 * starts with no plan in OpenRegister at all.
 *
 * The schema guard is the assertion that matters, for the reason its sibling
 * {@see \OCA\Dossiq\Listener\CaseNumberListener} records: a guard that reads
 * the schema straight off the payload short-circuits on EVERY object, and the
 * failure is silent.
 *
 * @category Tests
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
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CasePlanProjectionListener;
use OCA\Dossiq\Service\CasePlanProjectionService;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\CasePlanProjectionListener
 * @uses \OCA\Dossiq\Service\CasePlanProjectionService
 * @uses \OCA\Dossiq\Service\ObjectSchemaSlugResolver
 */
class CasePlanProjectionListenerTest extends TestCase {

	/**
	 * Build a listener whose resolver answers with a given slug.
	 *
	 * @param string                    $slug       The slug the resolver returns.
	 * @param CasePlanProjectionService $projection The projection (usually a mock).
	 *
	 * @return CasePlanProjectionListener The listener.
	 */
	private function listener(string $slug, CasePlanProjectionService $projection): CasePlanProjectionListener {
		$resolver = $this->createMock(ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturn($slug);

		return new CasePlanProjectionListener(
			projection: $projection,
			slugResolver: $resolver,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end listener()

	/**
	 * A created case is handed to the projection, with its caseType.
	 *
	 * @return void
	 */
	public function testACreatedCaseIsProjected(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('c1');
		$entity->setObject(['id' => 'c1', 'caseType' => 'ct-1']);

		$projection = $this->createMock(CasePlanProjectionService::class);
		$projection->expects($this->once())
			->method('projectForCase')
			->with('c1', 'ct-1')
			->willReturn(['projected' => true, 'reason' => 'projected']);

		$this->listener('case', $projection)->handle(new ObjectCreatedEvent($entity));
	}//end testACreatedCaseIsProjected()

	/**
	 * An object of another schema is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('t1');
		$entity->setObject(['id' => 't1', 'caseType' => 'ct-1']);

		$projection = $this->createMock(CasePlanProjectionService::class);
		$projection->expects($this->never())->method('projectForCase');

		$this->listener('caseTask', $projection)->handle(new ObjectCreatedEvent($entity));
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * A schema that cannot be resolved is fail-closed, not fail-open.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSchemaIsFailClosed(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('x1');
		$entity->setObject(['id' => 'x1', 'caseType' => 'ct-1']);

		$projection = $this->createMock(CasePlanProjectionService::class);
		$projection->expects($this->never())->method('projectForCase');

		$this->listener('', $projection)->handle(new ObjectCreatedEvent($entity));
	}//end testAnUnresolvableSchemaIsFailClosed()

	/**
	 * A case with no caseType is not projected, and does not throw.
	 *
	 * @return void
	 */
	public function testACaseWithoutACaseTypeIsNotProjected(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('c2');
		$entity->setObject(['id' => 'c2']);

		$projection = $this->createMock(CasePlanProjectionService::class);
		$projection->expects($this->never())->method('projectForCase');

		$this->listener('case', $projection)->handle(new ObjectCreatedEvent($entity));
	}//end testACaseWithoutACaseTypeIsNotProjected()

	/**
	 * A projection that throws is logged, never propagated: a failed plan must
	 * not roll back the case the caseworker just created.
	 *
	 * @return void
	 */
	public function testAThrowingProjectionDoesNotBreakCaseCreation(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('c3');
		$entity->setObject(['id' => 'c3', 'caseType' => 'ct-1']);

		$projection = $this->createMock(CasePlanProjectionService::class);
		$projection->method('projectForCase')->willThrowException(new \RuntimeException('boom'));

		$this->listener('case', $projection)->handle(new ObjectCreatedEvent($entity));
		$this->addToAssertionCount(1);
	}//end testAThrowingProjectionDoesNotBreakCaseCreation()

	/**
	 * An unrelated event is ignored.
	 *
	 * @return void
	 */
	public function testAnUnrelatedEventIsIgnored(): void {
		$projection = $this->createMock(CasePlanProjectionService::class);
		$projection->expects($this->never())->method('projectForCase');

		$this->listener('case', $projection)->handle(new Event());
	}//end testAnUnrelatedEventIsIgnored()
}//end class
