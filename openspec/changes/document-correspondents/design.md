# Design: document-correspondents

## D-1. Two fields on the projection, not a second object

`dispatch` modelled the correspondent as its own object beside the document.
A sender is document metadata. The projection gains `sender`, `recipient`
(each `{party?: ref, name: string}`) and `direction` (`inbound|outbound|
internal`). The `dispatch` schema and its seed go; nothing reads them
(`git grep dispatch src/ lib/Service` before deleting, count the hits).

## D-2. Writers are the two places a document enters

Outbound: the beschikking delivery already files the letter into the case
folder; it sets `recipient` from the addressed party and `direction:
outbound`. Inbound: the mail intake that files an attachment sets `sender`
from the From header, matching a known party by e-mail address when it can,
and `direction: inbound`. Uploads by hand ask for neither; the properties
dialog lets you fill them in.

## D-3. Columns are declared once nextcloud-vue reads them

`files-browser-columns` (nextcloud-vue, row 4.8) is the way the Files tab
shows extra columns. The manifest declares Sender and Recipient now; the
browser ignores unknown columns until it reads them, and the properties
dialog shows the fields in the meantime.
