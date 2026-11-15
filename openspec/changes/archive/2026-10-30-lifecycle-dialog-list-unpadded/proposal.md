# The lifecycle dialog's list adds no padding

## Why
r30 measured the DqVolgendeStap dialog 30px taller than the board. The `.case-acts` list padded itself 8px above and 16px below, on top of the dialog body's own padding.

## What changes
The list drops its padding. With nextcloud-vue's board footer fix (no NcDialog margin), the footer sits where the board draws it (probed: 942.8 vs 943).
