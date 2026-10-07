# Study Interest Explorer

Flexible, versioned study-interest assessment plugin for Jyavani CMS.

This plugin is intentionally separate from strict exam and proctoring workflows.
It owns its own assessment configuration, sessions, answers, scoring snapshots,
and audit records. It requires the Quiz plugin only for the published extension
contract and does not reuse Quiz attempt tables or internal request handlers.

## Current status

Version `0.4.0` provides append-only migrations, a packaged 45-question draft,
publication validation, immutable configuration snapshots, consent, private
browser sessions, autosave/resume, server-side scoring, recommendations,
response-quality flags, normalized result storage, and participant results.
The public start flow collects bounded minimal identity fields and keeps optional
contact details behind a separate contact-consent choice.
The dashboard includes aggregate result analytics and an authorized, audited
CSV export that excludes contact data.

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
