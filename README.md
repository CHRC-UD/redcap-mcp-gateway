# REDCap MCP Gateway

This External Module is the REDCap-side backend for a Model Context Protocol
(MCP) server. It is not an MCP transport server itself: REDCap invokes module
code for each HTTP request, while an MCP server needs a persistent stdio or
Streamable HTTP transport.

## Reporting API actions

All actions are read-only, operate only on the calling project-bound API token,
and require the token owner's Project Design/Setup right.

- `project-overview`, `project-schema`, and `project-structure` provide the
  project, data dictionary, event/arm, repeating-instrument, and DAG context.
- `list-reports`, `get-report`, and `summarize-report` support saved reports.
- `export-records` exports selected `fields[]` or `forms[]` only, with optional
  record, event, DAG, and filter-logic scoping.
- `summarize-fields` returns missingness, distinct counts, and up to 50 value
  frequencies per selected field.

Row-producing actions are bounded: report/export pages allow at most 1,000
rows; aggregate actions analyze at most 5,000 rows. Use `offset` and `limit`
to page through an export. No gateway action can create, update, delete, or
upload REDCap data.

## Identifier export control

Each enabled project has an **Allow REDCap Identifier fields to be exported
through MCP** checkbox in its External Module configuration. It is unchecked
by default. When unchecked, every data-returning action removes fields marked
as **Identifier?** in the REDCap data dictionary (`field_phi = 1`) and fields
whose variable name or label matches REDCap's configured identifier keywords
(for example DOB, name, email, address, phone, and MRN). This includes checkbox
option columns derived from those fields and the survey identifier.

This is enforced inside REDCap before an MCP response is returned, including
for saved reports. Responses state `identifiers_allowed` and list any
`excluded_identifier_fields`. If a request contains only blocked fields, the
gateway returns a 403 instead of an empty result. Enable the checkbox only for
projects that have approved identifier disclosure through the MCP client.

## Privacy-protected identifier aggregates

`aggregate-identifier-fields` is the sole exception to the identifier export
block. It can read protected fields in REDCap but only returns aggregates:

- means, minimums, and maximums for numeric fields;
- age statistics from date fields (for example a DOB), using `as_of_date`;
- counts for coded categorical fields such as ethnicity.

It never returns rows, dates, names, record IDs, or other source values. Any
numeric aggregate with fewer than the configured minimum cell size is
suppressed, as are rare category values. The per-project minimum defaults to
five and is configured in the External Module settings.

For the same reason, `filter_logic` may not reference an Identifier or
identifier-keyword field in this aggregate action. Otherwise an aggregate on a
different field could disclose a protected subgroup's exact size.

For a full-project race/ethnicity or other coded-category table, use
`aggregate-categorical-counts`. It returns each known category individually:
categories at or above the effective minimum cell size include a count, while
the rest return `suppressed (n<minimum)`. This action intentionally rejects all
record selection, custom filtering, event/DAG scoping, offsets, and limits.

Other full-project-only aggregate actions provide the same per-cell protection
for checkbox options, validated numeric distributions, fixed age bands,
date-year distributions, form completion statuses, and missingness. They also
reject caller-defined cohorts and pagination.

Example request:

```sh
curl -F token=YOUR_PROJECT_TOKEN \
  -F content=externalModule \
  -F prefix=redcap_mcp_gateway \
  -F action=project-schema \
  -F returnFormat=json \
  https://redcap.example.edu/redcap/api/
```

For `project-schema`, optional `fields[]` and `forms[]` restrict the returned
dictionary. For `export-records`, at least one of them is required.

## Enablement

1. Enable the module in Control Center → External Modules.
2. Enable it for each project that should be available to the MCP server.
3. Configure the standalone MCP server with that project's API token.

Do not add write actions until their REDCap authorization, validation, audit
logging, and scope have been designed and reviewed.
