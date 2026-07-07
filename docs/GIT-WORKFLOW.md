# Git Workflow

## Branches

- `main` – stable releases
- `develop` – integration branch
- `feature/*` – active development

## Rules

- No direct commits to `main`
- No direct commits to `develop`
- All changes through Pull Request
- Kim reviews before merge
- Pull latest `develop` daily

## Example

```bash
git checkout develop
git pull origin develop
git checkout -b feature/smtp-settings-saturn
```
