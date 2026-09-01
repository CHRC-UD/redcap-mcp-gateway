---
name: redcap-gateway-workflows
description: Workflow patterns and known gotchas for the REDCap gateway MCP connector (host.mcp("redcap", ...)) — project discovery/selection, safe row-level export/summary vs. privacy-protected identifier aggregation, pagination, and live tool discovery. Generic across whatever REDCap project(s) this gateway is configured for. Load when analyzing data from any REDCap project through this connector.
---

# REDCap Gateway Workflows

Practical patterns for `host.mcp("redcap", <tool>, ...)`, applicable to **any** REDCap
project(s) this gateway is configured to front — the gateway enforces disclosure-control
safeguards on Identifier fields regardless of which project you point it at. For the
live, auto-generated tool/schema reference (exact tool names, current project aliases,
JSON schemas), load `mcp-redcap` — that doc regenerates from the live server, so treat
it as ground truth over anything cached here. This skill is the "how and why" that
survives across projects and across the tool set changing.

MCP calls only work in the **`repl` tool**, never `python`/`r`. Hand results to
`python`/`r` via `./handoff/*.json` files.

## Discover configured projects first — never hardcode an alias

Don't assume which project aliases exist or what they're called. Call the
projects-listing tool (currently `redcap_gateway_list_configured_projects`) to get
the live set — it returns each configured project's alias, ID, title, longitudinal
status, instruments, and identifier-export setting, with no records or tokens. Every
other tool takes an optional `project=<alias>` argument; pass it explicitly once you
know the alias, and don't guess a name from a prior session — aliases are a gateway
config choice, not something intrinsic to the underlying REDCap project. If the
gateway fronts only one project via a single default token, `project` can be omitted,
but check first rather than assuming.

If a user says "this project" ambiguously after you've touched more than one this
session, ask which they mean rather than defaulting silently.

## Two tool families — pick the right one for the field in question

**1. Row-level tools** (export/report/summarize-style): return record-level or
per-report data restricted to non-Identifier fields. Any field flagged `identifier`
in that project's data dictionary (check via the schema tool) — and potentially
other fields the gateway treats as identifier-*adjacent* even when the dictionary
doesn't flag them (a date-of-birth-derived age field is a common example; the actual
set is project- and gateway-config-specific, so verify empirically per project
rather than assuming a fixed list carries over) — is **silently stripped** from
every response, and the record-ID field itself is always excluded this way. A 403
saying every requested field was dropped means the fields you asked for are
Identifier-flagged or -adjacent for *this* project — it is not a transient error,
don't retry the same fields hoping for a different result.

Always paginate with `offset`/`limit` and loop while the response signals more
rows remain (a `truncated` flag or equivalent). Cross-check totals with two
different page sizes when the exact count matters — that catches an off-by-one in
either your loop or the gateway's paging.

**2. Privacy-protected aggregate tools** — the only path to a distribution over an
Identifier field. These are deliberately restricted to **full-project scope**: no
per-record filter, no subgroup selector, no pagination. That restriction is not an
oversight — allowing a subgroup filter on an Identifier-aggregate call is exactly
the side channel that lets you reconstruct an individual's record by intersecting
filtered counts (concretely: filtering on a suppressed category while aggregating a
second, unfiltered field leaks the filtered subgroup's size). Any cell below the
project's minimum-cell-size threshold is shown as suppressed rather than the whole
field being withheld — so results still convey a usable shape (e.g., every age band
except one very small band).

Expect this family to include, at minimum: a general multi-field aggregator (numeric
means, date-to-age conversion, coded-category counts, cell-suppressed, in one call),
and single-field variants for categorical/radio-dropdown counts, checkbox-option
counts, numeric distributions, age bands from a date field, per-year date
distributions, per-instrument completion counts, and per-field missingness. Verify
each tool's shape empirically before trusting it in a deliverable — e.g. a
checkbox-style multi-select field passed to a categorical-counts tool may be
rejected (checkboxes need the checkbox-specific tool instead of the radio/dropdown
one), and a checkbox tool's per-option breakdown should be sanity-checked against
the field's known number of choices before you rely on the counts.

**Never route around the full-project-only restriction** by calling a row-level
tool with a filter that references an Identifier field, purely to infer a subgroup
size from a second, unfiltered field's count — that reconstructs exactly the value
the aggregate tools suppress, which is why well-configured gateways explicitly block
filters that reference Identifier fields. If you find a *new* way to back out a
suppressed value, stop, don't build the deliverable from it, and tell the user —
that is a disclosure-control gap to report, not a workaround to ship.

## Discovering the current tool set

There is typically no `list_tools` method. Two ways to see what's live right now:
1. Call any bogus method name — the resulting error message lists every known
   method for the server.
2. Reload `skill({"skill": "mcp-redcap"})` (or whatever the connector's
   auto-generated doc is named) — it regenerates from the live server and includes
   full JSON schemas per tool, including the current project-alias enum.

Either reflects additions/removals immediately. Don't assume last session's tool
list, or last session's known-Identifier-field list for a given project, is still
current — re-verify, especially after being told the gateway config changed.

## Per-project Identifier-field discovery

Because which fields are Identifier-flagged or -adjacent is a property of each
underlying REDCap project's data dictionary (plus whatever the gateway additionally
restricts), don't carry a hardcoded field list from one project to another. Instead,
for each new project: pull its schema, note fields flagged `identifier`, and treat
any field that gets silently dropped from row-level exports/summaries as
identifier-adjacent even if unflagged — then reach for the aggregate tools for those
specific fields on that project.

## Per-tool parameter names are inconsistent — don't guess, schema-check first

Tool names all start `redcap_gateway_`, but the argument that selects the field(s)
of interest is spelled differently per tool, and several are **required** despite
looking optional at a glance. Observed on this gateway:

- Project selector is `project=<alias>` (an enum of live aliases) on every tool —
  **not** `alias`. `list_configured_projects` is the only method that takes no
  project arg (it lists all of them).
- `redcap_gateway_aggregate_categorical_counts` / `redcap_gateway_aggregate_numeric_distribution`
  take a **singular** `field` (one string).
- `redcap_gateway_summarize_fields` / `redcap_gateway_get_project_schema` take a
  **plural** `fields` (array), and `get_project_schema`'s `fields`/`forms` filters
  are optional — but `summarize_fields`'s `fields` is **required** (no
  "summarize everything" mode).
- `redcap_gateway_aggregate_completion_counts` takes `forms` (array) and it's
  **required** — you must name the instrument(s) explicitly, there's no
  project-wide default.

A `schema validation failed: data must have required property '<x>'` error names
the exact missing key — that error already tells you the fix; don't guess a
second time, read the message. When starting work on a tool you haven't called
yet this session, it's faster to load `mcp-redcap` with a `filter` matching the
tool name and check its JSON schema than to trial-and-error the call.

## Cross-check aggregate-tool output against `summarize_fields` before trusting it

Two failure modes observed against a real project on this gateway, both silent
(no error, just a wrong-looking or rejected result):

- `aggregate_categorical_counts` on a radio/dropdown field can return a single
  garbled "category" whose label is a fused fragment of the field's own
  choice-definition string (e.g. one category named `"Usual Care | 2, Guided
  Exercise"` holding the *entire* project's count) instead of one row per coded
  choice. If a categorical-counts result has fewer categories than the field's
  known choice list, or a category label contains a literal `|` or a stray
  leading comma-number, treat it as a parsing failure of that tool for that
  field — don't report the number.
- `aggregate_numeric_distribution` outright rejects fields whose
  `text_validation_type_or_show_slider_number` is a decimal-typed validation
  (e.g. `number_1dp`) with `"field must be a valid numeric field"`, even though
  the field is genuinely numeric (schema shows `field_type: text` with that
  validation). It appears to only accept a validation type it recognizes as
  numeric, and that list doesn't include the decimal variants.

The reliable fallback for both is `summarize_fields` (pass the field names, even
mixed categorical + numeric in one call): it returns `nonmissing`/`missing`/
`distinct_values`/`value_counts` per field, correctly coded, and works for both
integer-coded categoricals and decimal-numeric fields alike — recompute means/
distributions from `value_counts` locally rather than trusting the
per-field-type aggregate tool blindly. If you need exact per-row values (not
achievable from `value_counts` alone, e.g. computing a mean by subgroup),
`export_records` is the row-level path — see the pagination note below.

## `export_records` pagination and response shape

- The returned rows live under the key **`data`**, not `records` — check
  `list(response.keys())` once per session rather than assuming.
- Default page size is small (e.g. 200) and the response carries
  `returned_rows`, `offset`, `limit`, and a boolean `truncated`; loop
  incrementing `offset` by the page size until `truncated` is `False`, and
  concatenate `data` across pages before analysis — a single call's `data` is
  very likely a partial result on any project with more than a couple hundred
  rows.
- `excluded_identifier_fields` in the response lists which of your *requested*
  fields got silently dropped for this call — check it every time rather than
  assuming every field you asked for came back. On one project, an
  age-band categorical field with no visible identifier marking in the plain
  schema view was still excluded this way — the field-note text alone
  ("synthetic, non-identifying") is not a reliable signal; treat the
  `excluded_identifier_fields` list as ground truth over the schema's
  `identifier` flag or field-note wording.
