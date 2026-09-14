<?php

/**
 * AcknowledgementOnCreateListener unit tests.
 *
 * The trigger the ontvangstbevestiging never had, and the three ways it could
 * fail to be one: firing for objects that are not cases, short-circuiting on
 * every object because the schema guard reads an id where it expects a slug, or
 * sending inline and taking a case creation down with a mail server.
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
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\BackgroundJob\AcknowledgementDispatchJob;
use OCA\Dossiq\Listener\AcknowledgementOnCreateListener;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A created case queues its acknowledgement, and nothing else does.
 *
 * @covers \OCA\Dossiq\Listener\AcknowledgementOnCreateListener
 */
class AcknowledgementOnCreateListenerTest extends TestCase {

	/**
	 * Build a listener whose resolver answers with a given slug.
	 *
	 * @param string   $slug    The slug the resolver returns.
	 * @param IJobList $jobList The job list, usually a mock.
	 *
	 * @return AcknowledgementOnCreateListener The listener.
	 */
	private function listener(string $slug, IJobList $jobList): AcknowledgementOnCreateListener {
		$resolver = $this->createMock(originalClassName: ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturn($slug);

		return new AcknowledgementOnCreateListener(
			jobList: $jobList,
			slugResolver: $resolver,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end listener()

	/**
	 * An OpenRegister event carrying one case.
	 *
	 * @param array<string, mixed> $object The case payload.
	 *
	 * @return ObjectCreatedEvent The event.
	 */
	private function event(array $object): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setUuid((string)($object['id'] ?? 'c1'));
		$entity->setObject($object);

		return new ObjectCreatedEvent($entity);
	}//end event()

	/**
	 * A created case queues an acknowledgement, and queues it once.
	 *
	 * The intake channel is not read here on purpose: the listener queues and
	 * the service decides. Deciding in the listener would put the rule in two
	 * places, and the second copy is the one that goes stale.
	 *
	 * @return void
	 */
	public function testACreatedCaseQueuesItsAcknowledgement(): void {
		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->expects($this->once())
			->method('add')
			->with(
				AcknowledgementDispatchJob::class,
				['caseId' => 'case-9', 'attempt' => 1]
			);

		$this->listener(slug: 'case', jobList: $jobList)->handle(
			$this->event(object: ['id' => 'case-9', 'intakeChannel' => 'website'])
		);
	}//end testACreatedCaseQueuesItsAcknowledgement()

	/**
	 * An object of another schema is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->expects($this->never())->method('add');

		$this->listener(slug: 'caseTask', jobList: $jobList)->handle($this->event(object: ['id' => 't1']));
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * A schema that cannot be resolved is fail-closed, not fail-open.
	 *
	 * 🔴 THIS IS THE ASSERTION THAT MATTERS MOST. Its sibling
	 * `DeadlineCaseCreatedListener` read the schema straight off the payload,
	 * where it is an ID and never a slug, so its guard short-circuited on EVERY
	 * object and no term was ever bound, silently, for months. An unresolvable
	 * schema must queue nothing rather than queue everything.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSchemaQueuesNothing(): void {
		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->expects($this->never())->method('add');

		$this->listener(slug: '', jobList: $jobList)->handle($this->event(object: ['id' => 'x1']));
	}//end testAnUnresolvableSchemaQueuesNothing()

	/**
	 * A case created without an id queues nothing, because nothing could run.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnIdQueuesNothing(): void {
		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->expects($this->never())->method('add');

		$entity = new ObjectEntity();
		$entity->setObject(['intakeChannel' => 'email']);

		$this->listener(slug: 'case', jobList: $jobList)->handle(new ObjectCreatedEvent($entity));
	}//end testACaseWithoutAnIdQueuesNothing()
}//end class
