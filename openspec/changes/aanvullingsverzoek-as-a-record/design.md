# Design: aanvullingsverzoek-as-a-record

## D-1. The request is the record, the pause is the consequence

A pause is what the clock does. A request is what a person did. They are
not the same fact and they do not have the same lifetime: a request that
was answered late still has to be readable years afterwards, and the timer
it suspended is long gone.

So the request is an object on the case, and it names the pause it caused
rather than living inside it.

## D-2. Missing items are a list, not a sentence

"Please send the missing documents" is one free-text field and no
reporting. A list of named items lets the answer say which of them arrived,
which is how a partially answered request is handled in practice and the
reason a second request is so often needed.

## D-3. The typed reason comes from pause-reason-with-chasing

That change already administers `pauseReason` per case type, with the legal
basis under Awb 4:5 or 4:15. Typing the request from the same rows means
one vocabulary, and it means the chases the reason declares are the chases
this request gets. Declaring a second reason list here would be the second
vocabulary.

## D-4. Expiry is a state, not a deletion

A request nobody answered is the evidence that the applicant was given the
chance. It becomes `expired` and stays. Nothing removes it, and nothing
rewrites the ask.

## D-5. The filter is on the case, because that is where the question is asked

A handler asks "what am I waiting on" from a work list. So the case carries
the derived fact that an open request exists, and the list filters on it.
The count comes from the requests themselves, so the two cannot drift for
long, and the derived flag is a read convenience rather than a second
truth.
