# Create pull request

Create a PR for the current changes.

1. Run `git status` and `git diff` to see staged and unstaged changes.
2. Write a clear commit message following conventional commits (feat:, fix:, refactor:, docs:, test:).
3. Stage, commit, and push: `git add .`, `git commit -m "message"`, `git push`.
4. Open PR: `gh pr create` with title and description derived from the changes (or use the GitHub web UI if gh is not configured).
5. Return the PR URL when done.
