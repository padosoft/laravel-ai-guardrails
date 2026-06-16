---
name: copilot-review-loop
description: Use when closing any sub-task/PR in this repo — runs the local Copilot review gate, then the GitHub PR + Copilot reviewer + CI loop until green.
---

# Copilot Review Loop

The Definition of Done for every sub-task. Do not skip; do not fake.

## 1. Local gate (before push)

> **Shell note:** Run from PowerShell (or adapt to Git Bash). Commands below are PowerShell.

```powershell
# from repo root
git fetch origin

# run tests + mutation coverage (must both pass). Herd PHP is not on PATH → call it explicitly:
$php = "$env:USERPROFILE\.config\herd\bin\php85.bat"
& $php vendor/bin/phpunit
& $php vendor/bin/infection --min-msi=80

# generate FULL branch diff (not just uncommitted changes) and pipe to Copilot
git diff origin/main...HEAD | Out-File "$env:TEMP\branch.diff" -Encoding utf8
copilot --autopilot --yolo -p "/review the changes in $env:TEMP\branch.diff for the laravel-ai-guardrails package. Focus on security posture, untrusted-input handling, append-only invariants, and test coverage."
```

**Git Bash equivalent:**
```bash
DIFF="${TMPDIR:-/tmp}/branch.diff"
git diff origin/main...HEAD > "$DIFF"
copilot --autopilot --yolo -p "/review the changes in $DIFF ..."
```

Resolve EVERY comment. Re-run until zero comments. Only then `git push`.

## 2. Open PR (toward the branch you are working on / macro branch)
```bash
gh pr create --base <target-branch> --head <current-branch> --title "..." --body "..."
```

## 3. Request the Copilot reviewer (GraphQL — the reliable way)
`gh pr edit --add-reviewer @copilot` and the REST endpoint are unreliable. Use:
```bash
PRID=$(gh pr view <PR> --json id -q .id)
gh api graphql -f query='mutation($pid:ID!,$bots:[String!],$u:Boolean!){requestReviewsByLogin(input:{pullRequestId:$pid,botLogins:$bots,union:$u}){clientMutationId}}' \
  -F pid="$PRID" -F bots[]='copilot-pull-request-reviewer[bot]' -F u=true
gh api repos/padosoft/laravel-ai-guardrails/pulls/<PR>/requested_reviewers   # confirm it started
```

## 4. Poll until resolved
```bash
gh pr checks <PR> --watch            # CI green?
gh pr view <PR> --comments           # Copilot comments?
```
Fix broken tests + every Copilot comment → update `docs/LESSON.md` with what you learned → push → the review re-runs. Repeat.

## 5. Merge only when CI green AND all Copilot threads resolved.
If GitHub/Copilot is unavailable, record local status + next remote step in `docs/PROGRESS.md`. Never fake green.
