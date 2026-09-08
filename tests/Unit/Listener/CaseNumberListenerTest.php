<?php

/**
 * CaseNumberListener unit tests.
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
 * @spec openspec/specs/case-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CaseNumberListener;
use OCA\Dossiq\Service\CaseNumberService;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * The listener fires for a case and for nothing else.
 *
 * The schema guard is the assertion that matters. Its sibling
 * {@see \OCA\Dossiq\Listener\DeadlineCaseCreatedListener} read the schema
 * straight off the payload, where it is an ID and never a slug, so its guard
 * short-circuited on EVERY object and no term was ever bound — for months,
 * silently. This listener resolves through the shared resolver, and these
 * tests drive it with the id-shaped payload OpenRegister actually emits.
 *
 * @covers \OCA\Dossiq\Listener\CaseNumberListener
 */
class CaseNumberListenerTest extends TestCase {

	/**
	 * Build a listener whose resolver answers with a given slug.
	 *
	 * @param string            $slug    The slug the resolver returns.
	 * @param CaseNumberService $numbers The number service (usually a mock).
	 *
	 * @return CaseNumberListener The listener.
	 */
	private function listener(string $slug, CaseNumberService $numbers): CaseNumberListener {
		$resolver = $this->createMock(ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturn($slug);

		return new CaseNumberListener(caseNumbers: $numbers, slugResolver: $resolver);
	}//end listener()

	/**
	 * A created case is handed to the number service.
	 *
	 * @return void
	 */
	public function testACreatedCaseIsHandedToTheNumberService(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('c1');
		$entity->setObject(['id' => 'c1', 'startDate' => '2026-03-01']);

		$numbers = $this->createMock(CaseNumberService::class);
		$numbers->expects($this->once())
			->method('assign')
			->willReturn('2026-0042');

		$this->listener('case', $numbers)->handle(new ObjectCreatedEvent($entity));
	}//end testACreatedCaseIsHandedToTheNumberService()

	/**
	 * An object of another schema is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('t1');
		$entity->setObject(['id' => 't1']);

		$numbers = $this->createMock(CaseNumberService::class);
		$numbers->expects($this->never())->method('assign');

		$this->listener('caseTask', $numbers)->handle(new ObjectCreatedEvent($entity));
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * A schema that cannot be resolved is fail-closed, not fail-open.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSchemaIsFailClosed(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('x1');
		$entity->setObject(['id' => 'x1']);

		$numbers = $this->createMock(CaseNumberService::class);
		$numbers->expects($this->never())->method('assign');

		$this->listener('', $numbers)->handle(new ObjectCreatedEvent($entity));
	}//end testAnUnresolvableSchemaIsFailClosed()

	/**
	 * An unrelated event is ignored.
	 *
	 * @return void
	 */
	public function testAnUnrelatedEventIsIgnored(): void {
		$numbers = $this->createMock(CaseNumberService::class);
		$numbers->expects($this->never())->method('assign');

		$this->listener('case', $numbers)->handle(new Event());
	}//end testAnUnrelatedEventIsIgnored()
}//end class
