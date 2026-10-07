# Study Interest Explorer

Flexible, versioned study-interest assessment plugin for Jyavani CMS.

This plugin is intentionally separate from strict exam and proctoring workflows.
It owns its own assessment configuration, sessions, answers, scoring snapshots,
and audit records. It requires the Quiz plugin only for the published extension
contract and does not reuse Quiz attempt tables or internal request handlers.

## Current status

Version `0.7.0` provides append-only migrations, a packaged 45-question draft,
publication validation, immutable configuration snapshots, consent, private
browser sessions, autosave/resume, server-side scoring, recommendations,
response-quality flags, normalized result storage, and participant results.
The public start flow collects bounded minimal identity fields and keeps optional
contact details behind a separate contact-consent choice.
The dashboard includes assessment and question authoring, participant editing,
session operations, response-level review, cohort analytics, an activity log,
and audited filtered CSV or Excel exports. A separate anonymous result export
excludes contact data. Completed results can be corrected only by changing
effective answers and recalculating against the original immutable version;
every correction creates a numbered snapshot revision with a mandatory reason
and activity event. Result views always surface the closest study directions,
even when no direction passes the configured dominant-recommendation threshold.
An audited Result page policy can expose the complete result, replace the page
with a managed-access notice, or hide it. When the complete page is enabled,
each result section can independently be shown, server-side masked, or omitted;
masking does not send the protected scores or labels in participant HTML.

The packaged baseline is never published automatically. An authorized reviewer
must import it as a draft and explicitly publish it after reviewing the
questionnaire and compatibility weights.

## Routes

- Public assessment: `/study-interest/`
- Dashboard: `admin/tools/study-interest`

Published scoring data is never sent to the browser. Public URLs use UUIDs and
also require the matching HTTP-only session token.

## Development notes

Private planning notes belong in `.notes/` and are intentionally ignored. Do not
put credentials, participant data, database dumps, or unpublished question banks
in the repository.
