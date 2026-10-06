---
name: ui-ux-review
description: Review or change the Clear Stock embedded app's UI. Use before shipping any screen, dialog, form, table or flow in app/frontend/, when asked to check UI/UX or Built for Shopify, or to test the app by hand in a browser.
---

# UI/UX review for Clear Stock

The app is embedded in the Shopify admin and built with Polaris web components (`s-*`). A screen is
done when it passes the three passes below, in this order. Automated tests do not replace pass 2:
every bug in "Known traps" passed the test suite and was found by using the app.

## Pass 1: read the code against the rules

**Structure (Built for Shopify)**
- One `s-page` per screen with a heading; the menu (`s-app-nav`) has at most 8 entries and none is repeated in the page body.
- Tabs only change what is below them, never wrap, never move; the active tab is in the URL (`?tab=` or its own route).
- One primary action per card or dialog. Row actions in tables are secondary; at most two visible, the rest under "More" (`s-menu`).
- A form with more than five inputs is grouped under headings (`s-heading` + `s-divider`), as in `ProductSettingsForm` and `SupplierModal`.
- Unsaved changes on a page use the contextual save bar (`SaveBar`), never a Save button in the page. Dialogs have their own primary button.
- Features the plan lacks show `UpgradePrompt`; features switched off (`useFeature`) are not rendered at all.

**Every state exists**
- Loading: `LoadingPage` (with `group` when the page has section tabs).
- Empty: `s-empty-state` with one sentence and the action that fills it.
- Load error: `ErrorBanner` with `onRetry`. Never a spinner next to an error.
- Save error: nothing to write, `lib/queryClient` toasts every failed mutation. Validation errors (422) go on the field with `fieldError()`. Background writes opt out with `meta: { silent: true }`.
- Success: a toast in the past tense ("Supplier updated").

**Words and numbers**
- No text in JSX: everything through `t()`, six locale files in step (`npm run i18n:check`).
- Numbers, money and dates only through `utils/format`. Numeric table columns are `format="numeric"`; a stacked numeric cell needs `alignItems="end"`.
- Sentence case. The same thing has the same name everywhere (Cancel / Save / Delete / Try again come from `common.*`).
- A zero that means "nothing to do" is written in words ("Nothing more to order"), not "Order 0".

## Pass 2: use it like a merchant, in a browser

Open the real app outside the admin with App Bridge stubbed (see "Manual test page" below) and do
the task the screen exists for, start to finish, with the mouse and keyboard. For each flow:

1. Do it the normal way. Is it obvious what happened? (toast, list updated, dialog closed)
2. Do it twice in a row. Does the second time start clean?
3. Abandon it halfway (Escape, Cancel, navigate away), then come back.
4. Do the destructive thing. Were you asked first? Could a click meant for another button hit it?
5. Enter something wrong (empty, 0, negative, end before start). Is the error at the field, in words?
6. Look at the same screen at 390 px wide.
7. Compare with the neighbouring screens: same order of columns, same button labels, same spacing.

## Pass 3: make it stay fixed

Every problem found gets a test that would have caught it (`app/frontend/e2e/plans.spec.ts`, or
`failures.spec.ts` for outages and bad input), and anything a machine can check goes into CI.

## Known traps (each cost a real bug)

- **Dialog content must not change while it closes.** After a save, lists refetch and props change; freeze what the dialog shows until `afterhide` (`MarkOrderedModal`, `useConfirm`).
- **Reset a dialog's form when it opens (`onShow`), not only when its entity changes.** Otherwise "add, dismiss, add" shows what was typed before.
- **`<s-select>` with options from the API needs `key={optionsKey(n)}`** (`utils/select.ts`), or it shows the first option instead of its value.
- **`<s-option value="">` sends its label.** Use `NO_VALUE`, `optionValue()`, `fromOption()`.
- **`@container` grid values cannot contain commas** (no `minmax(0, 1fr)`) and only work inside `<s-query-container>`.
- **Destructive actions always confirm** (`useConfirm`, `destructive: true`, a `cancelLabel` that says what is kept).
- **Slots of the page title bar (`primary-action`, `secondary-actions`, `breadcrumb-actions`) only render inside the Shopify admin.** A feature reachable only from there cannot be tested outside it, and needs a second way in where that matters (empty state button).
- **A status has one definition in two places** (`EloquentForecastQueryRepository::statusSql` for counts and filters, `ForecastStatusResolver` for the badge). Change both and extend the "agree on every status" tests; they once disagreed for an out-of-stock product with its order on the way.
- **Escape closes the whole dialog**, also while a date picker inside it is open.

## Manual test page

`app/frontend/preview.html` (ignored by git) is the app's page with `window.shopify` stubbed and a
token from `php artisan dev:session-token --ttl=14400`; toasts and the save bar are drawn on the
page. Open `http://localhost:8080/preview.html?to=/reorder`. The Vite container must run with a
working `APP_URL` (memory: local-dev-environment-quirks). Recreate the file from the stub in
`app/frontend/e2e/support/app.ts` when it is missing. Restore what a manual pass changed: cancel open
manual orders, clear the budget, delete test events.
