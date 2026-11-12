# Milestone 9 ticket 5 — submission evidence

Local implementation adds schema 0.12.0 and separate logical/attempt correlation,
one-time normalized outcomes, mandatory pre-submission evidence for recovery,
and the conservative retry proof/delay contract (ADR 0035).

New schema is additive and runs after existing Milestone 8 migrations. Current
integration hooks, version changes and fixture updates overlap preserved, previously
uncommitted Milestone 8 work. They remain uncommitted pending integration isolation;
the focused ticket checkpoint is not a standalone deployable PR snapshot.

Automated tests exercise default refusal, proof/category gates, ambiguity,
acceptance, delays, bounded numbers and metadata-only evidence. Integrated schema,
worker and disposable database validation are included in the final Milestone 9
QA. No actual mail or live settings were changed for these checks.
