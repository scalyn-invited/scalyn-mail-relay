# ADR-0001: WordPress modular monolith foundation

**Status:** Accepted

## Context
The MVP must install as one WordPress plugin but preserve boundaries for future centralized/SaaS capabilities.

## Decision
Use a modular monolith with explicit contracts between Core, Mail, Providers, Logging, Diagnostics, REST and Admin UI. Provider-specific behavior stays behind `ProviderInterface`.

## Consequences
Deployment stays simple while module ownership remains clear. Cross-module changes require review and new architecture decisions when contracts change.
