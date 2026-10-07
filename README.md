# Study Interest Explorer

Flexible, versioned study-interest assessment plugin for Jyavani CMS.

This plugin is intentionally separate from strict exam and proctoring workflows.
It owns its own assessment configuration, sessions, answers, scoring snapshots,
and audit records. It requires the Quiz plugin only for the published extension
contract and does not reuse Quiz attempt tables or internal request handlers.

## Current status

The `0.1.0` foundation provides the plugin contract, isolated schema, consent
entry point, and session creation endpoint. Questionnaire authoring, publishing,
scoring, result interpretation, and analytics are not enabled until a reviewed
assessment configuration exists.

## Development notes

Private planning notes belong in `.notes/` and are intentionally ignored. Do not
put credentials, participant data, database dumps, or unpublished question banks
in the repository.
