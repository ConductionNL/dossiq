<?php

/**
 * Unit tests for ReportGroupingOnCreateListener: a created case queues its
 * grouping, anything else queues nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-identical-reports-collapse-on-the-case-and-dossiq-decides-what-a-group-means-req-aic-04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\BackgroundJob\ReportGroupingJob;
use OCA\Dossiq\Listener\ReportGroupingOnCreateListener;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Listener\ReportGroupingOnCreateListener
 */
class ReportGroupingOnCreateListenerTest extends TestCase {

	/**
	 * Build the listener with a resolver answering one slug.
	 *
	 * @param string   $slug    The slug the resolver answers.
	 * @param IJobList $jobList The job list.
	 *
	 * @return ReportGroupingOnCreateListener The listener.
	 */
	private function listener(string $slug, IJobList $jobList): ReportGroupingOnCreateListener {
		$resolver = $this->createMock(originalClassName: ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturn($slug);

		return new ReportGroupingOnCreateListener(jobList: $jobList, slugResolver: $resolver);
	}//end listener()

	/**
	 * A created object event.
	 *
	 * @param string|null $uuid The uuid, or null for none.
	 *
	 * @return ObjectCreatedEvent The event.
	 */
	private function event(?string $uuid): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		if ($uuid !== null) {
			$entity->setUuid($uuid);
		}

		$entity->setObject(['title' => 'Stroomstoring']);

		return new ObjectCreatedEvent($entity);
	}//end event()

	/**
	 * A created case queues its grouping.
	 *
	 * @return void
	 */
	public function testACreatedCaseQueuesItsGrouping(): void {
		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->expects($this->once())->method('add')->with(ReportGroupingJob::class, ['caseId' => 'case-9']);

		$this->listener(slug: 'case', jobList: $jobList)->handle($this->event(uuid: 'case-9'));
	}//end testACreatedCaseQueuesItsGrouping()

	/**
	 * Another schema, an id-less case and another event queue nothing.
	 *
	 * @return void
	 */
	public function testAnythingElseQueuesNothing(): void {
		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->expects($this->never())->method('add');

		$this->listener(slug: 'caseTask', jobList: $jobList)->handle($this->event(uuid: 't-1'));
		$this->listener(slug: 'case', jobList: $jobList)->handle($this->event(uuid: null));
		$this->listener(slug: 'case', jobList: $jobList)->handle(new Event());
	}//end testAnythingElseQueuesNothing()
}//end class
