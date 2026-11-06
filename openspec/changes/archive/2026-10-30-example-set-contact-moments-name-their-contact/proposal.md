# Proposal: example-set-contact-moments-name-their-contact

## Why

Every contact moment of the Zuiddrecht set had `contact` empty (found by NCV-CONTROLS). `contactmoment.contact` is the person or company the moment is about, with the same semantic type as `case.requester`. With it empty, the contact column on the case's communication list is blank and the contact's own history misses the moment.

## What changes

- Each contact moment names its case's requester as its `contact`.
- portaliq's moments on the Lindelaan case get the same fix in portaliq's fragment.
