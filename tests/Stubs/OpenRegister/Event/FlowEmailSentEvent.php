<?php

/**
 * OpenRegister FlowEmailSentEvent test stub.
 *
 * A verbatim copy of openregister's class (lib/Event/FlowEmailSentEvent.php, merged
 * into development by openregister#4120, read 2026-09-28), so
 * FlowEmailSentListener can be tested and analysed without that OpenRegister.
 * tests/bootstrap.php loads it only when the real class is absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * One email a flow sent, with who, what and on whose behalf.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The event is a value carrier;
 * each constructor argument is one field of the published contract.
 *
 */
class FlowEmailSentEvent extends Event {

	/**
	 * The recipient was a Nextcloud user.
	 */
	public const KIND_USER = 'user';

	/**
	 * The recipient was an email address outside Nextcloud.
	 */
	public const KIND_EXTERNAL = 'external';

	/**
	 * Constructor.
	 *
	 * @param string|null $register The register of the item the email was about, when the item is an object.
	 * @param string|null $schema The schema of the item the email was about, when the item is an object.
	 * @param string|null $objectUuid The uuid of the object the email was about.
	 * @param string $recipient Who the email went to: an address for an external recipient, a uid for a user.
	 * @param string $channelKind Whether the recipient was a Nextcloud user or an external address.
	 * @param string $subject The rendered subject.
	 * @param string $body The rendered body, as sent.
	 * @param string|null $flowId The flow the sending run belongs to.
	 * @param string|null $runId The run that sent the email.
	 * @param string $stepName The step that sent the email: the node id when known, otherwise the node type.
	 * @param string $actingUser The user the run acted as, the sender of record.
	 */
	public function __construct(
		private readonly ?string $register,
		private readonly ?string $schema,
		private readonly ?string $objectUuid,
		private readonly string $recipient,
		private readonly string $channelKind,
		private readonly string $subject,
		private readonly string $body,
		private readonly ?string $flowId,
		private readonly ?string $runId,
		private readonly string $stepName,
		private readonly string $actingUser,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The register of the item the email was about, when the item is an object.
	 *
	 * @return string|null The register id, or null.
	 *
	 */
	public function getRegister(): ?string {
		return $this->register;
	}//end getRegister()

	/**
	 * The schema of the item the email was about, when the item is an object.
	 *
	 * @return string|null The schema id, or null.
	 *
	 */
	public function getSchema(): ?string {
		return $this->schema;
	}//end getSchema()

	/**
	 * The uuid of the object the email was about.
	 *
	 * @return string|null The uuid, or null when the item is not an object.
	 *
	 */
	public function getObjectUuid(): ?string {
		return $this->objectUuid;
	}//end getObjectUuid()

	/**
	 * Who the email went to: an address for an external recipient, a uid for a user.
	 *
	 * @return string The address or uid.
	 *
	 */
	public function getRecipient(): string {
		return $this->recipient;
	}//end getRecipient()

	/**
	 * Whether the recipient was a Nextcloud user or an external address.
	 *
	 * @return string `user` or `external`.
	 *
	 */
	public function getChannelKind(): string {
		return $this->channelKind;
	}//end getChannelKind()

	/**
	 * The rendered subject.
	 *
	 * @return string The subject.
	 *
	 */
	public function getSubject(): string {
		return $this->subject;
	}//end getSubject()

	/**
	 * The rendered body, as sent.
	 *
	 * @return string The body.
	 *
	 */
	public function getBody(): string {
		return $this->body;
	}//end getBody()

	/**
	 * The flow the sending run belongs to.
	 *
	 * @return string|null The flow id, or null outside a stored run.
	 *
	 */
	public function getFlowId(): ?string {
		return $this->flowId;
	}//end getFlowId()

	/**
	 * The run that sent the email.
	 *
	 * @return string|null The run uuid, or null outside a stored run.
	 *
	 */
	public function getRunId(): ?string {
		return $this->runId;
	}//end getRunId()

	/**
	 * The step that sent the email: the node id when known, otherwise the node type.
	 *
	 * @return string The step name.
	 *
	 */
	public function getStepName(): string {
		return $this->stepName;
	}//end getStepName()

	/**
	 * The user the run acted as, the sender of record.
	 *
	 * @return string The acting user's uid.
	 *
	 */
	public function getActingUser(): string {
		return $this->actingUser;
	}//end getActingUser()
}//end class
