# Releasing

This package is versioned with [Semantic Versioning](https://semver.org) and
distributed via git tags (there is intentionally no `version` field in
`composer.json`).

## 1. Pre-flight

- [ ] All intended changes are merged into `main`.
- [ ] `main` is green in CI (tests matrix + quality job).
- [ ] Locally on an up-to-date `main`, everything passes:
  ```bash
  composer test
  composer analyse
  composer lint
  ```
- [ ] The `isapp/laravel-cashier-support` constraint matches the support
      version this release actually needs (bump the `^` constraint when the
      driver starts using newer support APIs).
- [ ] The `[Unreleased]` section of `CHANGELOG.md` reflects every change since
      the last tag (the PR changelog enforcer keeps this honest).

## 2. Decide the version

Bump according to the content of `[Unreleased]`:

- **major** (`X`.0.0) — any breaking change: `Removed`, backward-incompatible
  `Changed` (changed signatures, dropped a Laravel or support version, changed
  webhook/event semantics).
- **minor** (x.`Y`.0) — new backward-compatible features (`Added`).
- **patch** (x.y.`Z`) — backward-compatible bug fixes only (`Fixed` / `Security`).

## 3. Update the CHANGELOG

In `CHANGELOG.md`:

- [ ] Rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD` (use today's date).
- [ ] Add a fresh empty `## [Unreleased]` above it.
- [ ] Update the reference links at the bottom:
  ```markdown
  [Unreleased]: https://github.com/isap-ou/laravel-cashier-revolut/compare/vX.Y.Z...HEAD
  [X.Y.Z]:      https://github.com/isap-ou/laravel-cashier-revolut/compare/vPREV...vX.Y.Z
  ```
  For the very first release, point `[X.Y.Z]` at
  `.../releases/tag/vX.Y.Z` instead of a compare.

## 4. Commit and tag

```bash
git checkout main
git pull --ff-only origin main
git add CHANGELOG.md
git commit -m "Release X.Y.Z"
git tag -a vX.Y.Z -m "X.Y.Z"
git push origin main
git push origin vX.Y.Z
```

> Tags are the release. Never move or delete a published tag — cut a new patch
> instead.

## 5. Publish & verify

- [ ] Create a GitHub Release from the tag, pasting the `X.Y.Z` CHANGELOG
      section as the body.
- [ ] The repo is private: make sure the private package source (Private
      Packagist / Satis / VCS entry in consuming apps) picks up the new tag.
- [ ] `composer require isapp/laravel-cashier-revolut:^X.Y` resolves the
      release in a consuming application.

## Diffing versions

- Human-readable: the per-version sections of `CHANGELOG.md`.
- Code: `git diff vPREV vX.Y.Z` or GitHub `compare/vPREV...vX.Y.Z`.
