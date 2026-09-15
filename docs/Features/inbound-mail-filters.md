# Inbound mail filters

A gemeente postbus gets more auto-replies than aanvragen. Every message now
walks a named pipeline before it can become a case, and every message leaves a
line in the intake log.

## Nextcloud Mail holds the account

You pick a Nextcloud Mail account in the dossiq mail settings. There is no host
field, no username field and no password field, because dossiq stores no mailbox
credential. Nextcloud Mail already runs the OAuth 2.0 flow for Microsoft 365 and
Google, so a shared postbus on either works without a second login.

Upgrading deletes the password dossiq used to store. A credential nobody reads
is still a credential in a database backup.

If the Mail app is disabled, intake reports that it is unavailable. It does not
fail the cron run.

## The pipeline runs in a declared order

Each filter has a name, and each one answers accept, reject, quarantine, forward
or pass. The log records which filter decided and why. A message no filter
objects to is accepted, and that default is written down rather than implied.

The first filters catch the mail nobody should have to read:

- an auto-reply, recognised by its auto-submitted header
- a permanent delivery failure coming back from another server
- our own notification returning to the postbus, which is how a loop starts
- an out of office reply

## Four checks on every sender

Every message carries four results, and each is pass, fail, none or
unavailable:

- **SPF** and **DMARC**, read from the authentication-results header the
  receiving server wrote
- **DKIM**, from the check Nextcloud Mail already runs
- **threading**, which asks whether a referenced message id is one this account
  really holds

A check nobody made reads as unavailable, never as a pass. A message with no
authentication-results header says so on the row.

The threading check is the one no mail server can answer for you, because only
you know what you sent. A forged `In-Reply-To` against somebody else's case tag
gets a threading result of fail, and dossiq refuses to link it on the subject
tag alone.

## Each case type decides what a failure means

A melding openbare ruimte from an unauthenticated sender is a normal Tuesday. A
bezwaar from one carries a statutory consequence. So the policy sits on the case
type, not on the instance:

| policy | what happens |
|---|---|
| accept | a case is created, with the verdict recorded on it |
| quarantine | the message waits for the intake role to release it |
| refuse | the message is refused, recorded, and the sender is told |

The default is quarantine. Accepting a forged bezwaar is bad. Dropping a real
one is worse, and quarantine is the answer that is neither.

## Bounce and move are two different acts

**Bounce** sends a message on to another address with the original intact. Awb
2:3 is the doorzendplicht, so a message for another administrative body goes
there and dossiq records the address and the reason. No case is created, because
the message was never ours, and the sender receives no delivery failure.

**Move** files a message in another folder of the same account. That is a
mailbox act, not a case act.

## Nothing is dropped

A message matching no case and no case type lands in the intake inbox with its
verdict on it. A message somebody has to pick up is a visible problem. A message
that vanished is a missed statutory term nobody can see.

## Who may open a case by mail

The allow half is Nextcloud Mail's trusted-sender list, read as it stands. The
block half lives in dossiq, because blocking who may open a case is a case
decision.

A sender you block in dossiq can still mail your colleagues. The block stops a
case being opened, and the log records every time it did.

## The junk verdict names its rule

A message classified as junk carries the rule that classified it, and you can
read that rule in the log. Wrong verdicts happen, so the intake role can mark a
message as not junk. It re-enters the pipeline and the correction is recorded.

## Server-side filtering with Sieve stays available

Some municipalities would rather filter on their own mail server, and Nextcloud
Mail offers Sieve for exactly that. dossiq neither requires it nor replaces it.

One consequence is worth knowing before you write a Sieve rule: **a message your
mail server filters away never reaches this pipeline.** dossiq cannot log,
quarantine or bounce a message that was never delivered to the folder. The
intake log then says the mailbox delivered nothing, which is the honest reading
of an empty table.

So keep the rules that move newsletters out of the postbus, and think twice
about a rule that files a sender you also receive aanvragen from.

## Who can read the log

The intake log holds the original source of every message the postbus received,
so only the intake role can open it. It follows the case type's retention rule.

## Next

Open Settings, then Mail intake, and pick the account the postbus runs on. Then
set the intake policy on your bezwaar case type before the first one arrives.
