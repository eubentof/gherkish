# Repository instructions

## Release notes

- End every completed change handoff with a concise release note.
- Describe the user-visible behavior and any compatibility impact.
- Keep the release note small and ready to paste into a GitHub release.
- Explicitly state when a change is opt-in, backward compatible, or breaking.
- Do not create a release, tag, commit, or push unless the user explicitly requests it.

## Publishing

- When the user asks to publish, first propose the exact semantic version and wait for explicit confirmation before committing, tagging, or pushing anything.
- Always consider the complete `X.Y.Z` version. Use a patch increment (`Z`) for small backward-compatible fixes, a minor increment (`Y`) for backward-compatible features, and a major increment (`X`) for breaking changes.
- After the version is confirmed, treat the publish request as authorization to commit the release changes, prepare the release note, create an annotated semantic version tag, and push the branch and tag to `origin`.
- Report the commit, version tag, and push result in the final handoff.
- Do not create the GitHub release, publish to Packagist, or perform any other release action; the user will complete the release manually.
