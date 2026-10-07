# Study Interest Explorer Repository Contract

Study Interest Explorer is a generic Jyavani CMS plugin for flexible,
versioned educational-interest assessments. Tracked source and repository
metadata must remain suitable for universities, training providers, companies,
career services, and other organizations.

## Confidential Inputs

Files under `.notes/` and files ending in `.local.md` or `.private.md` are
ignored working notes. They may describe a downstream implementation and must
never be committed, quoted in tracked documentation, or copied into product
identity. Convert downstream requirements into generic configuration, hooks,
permissions, and workflows.

## Product Boundary

- This plugin owns assessment configuration, immutable versions, public
  sessions, autosaved answers, scoring, result snapshots, flags, and analytics.
- Study Interest is standalone within Jyavani. Never depend on Quiz functions,
  files, permissions, routes, assets, or tables.
- Strict exams, OTP, proctoring, device locks, and test-number workflows remain
  outside this plugin and must not be added here by default.
- Core owns routing, authorization, migrations, CSRF primitives, and plugin
  lifecycle. Do not patch Core when a published contract already exists.

## Safety

- Published versions are immutable. Changes require a new version.
- Hidden scoring configuration never reaches the browser.
- Public sessions use UUID URLs plus an independent bearer cookie.
- Contact consent is separate from assessment consent.
- Result, contact, export, configuration, and publication permissions remain
  separate.
- Results are exploratory, not diagnoses, aptitude tests, probabilities, or
  academic-ability judgments.

## Verification

Lint every PHP file, run every test in `tests/`, validate migrations against the
supported Core, and search tracked files for downstream identity before a
release. Do not commit or push unless explicitly requested.
