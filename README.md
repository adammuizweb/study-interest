# Study Interest Explorer

Flexible, versioned study-interest assessment plugin for Jyavani CMS.

This plugin is intentionally separate from strict exam and proctoring workflows.
It owns its own assessment configuration, sessions, answers, scoring snapshots,
and audit records. It is a standalone Jyavani plugin and does not require or
reuse Quiz functions, routes, permissions, tables, or request handlers.

## Current status

Version `0.8.0` provides append-only migrations, a packaged 45-question draft,
publication validation, immutable configuration snapshots, consent, private
browser sessions, autosave/resume, server-side scoring, recommendations,
response-quality flags, normalized result storage, and participant results.
The public start flow collects bounded minimal identity fields and keeps optional
contact details behind a separate contact-consent choice.
The dashboard can create and delete unpublished assessments, edit test names,
descriptions, scoring configuration, result messages, questions, and answer
options, and publish reviewed immutable versions. It also includes participant
editing, session operations, response-level review, cohort analytics, an
activity log, and audited filtered CSV or Excel exports. A separate anonymous
result export excludes contact data. Admins can edit submitted answers through
controlled corrections that recalculate against the original immutable version;
every correction creates a numbered snapshot revision with a mandatory reason
and activity event. Result views always surface the closest study directions,
even when no direction passes the configured dominant-recommendation threshold.
An audited Result page policy can expose the complete result, show a safe
blurred preview with a managed-access notice, or hide it. When the complete page
is enabled, each result section can independently be shown, safely blurred, or
omitted; masking never sends protected scores or labels in participant HTML.
Only one published assessment is live on the public entry route at a time.

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
