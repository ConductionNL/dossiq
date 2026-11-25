# Tasks: contacts-list-columns-as-drawn

- [x] 1.1 Test: column widths and a sortable description (`tests/vitest/contactsColumnWidths.spec.js`, red on development).
- [x] 1.2 Contacts columns.
- [x] 1.4 The Name column wraps (`cellClass: "cn-cell--wrap"`), test in `tests/vitest/contactsColumnWidths.spec.js`; the avatar widget wraps in such a cell from nextcloud-vue #2360.
- [x] 1.3 Live: DqContacten addresses read in full on :8080.
  - 25 Nov 2026 sandbox: with development's nextcloud-vue, "Oosthavenweg 12, Zuid..." was cut (red). With 1.4 and the #2360 rule injected, every address reads in full. Done 25 Nov 2026 with nextcloud-vue 2.225.1 (contains #2360, raised in the same PR): no contact row is clipped, Ans Bos reads "Oosthavenweg 12, Zuiddrecht" in full over two lines.
