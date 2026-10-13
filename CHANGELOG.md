# Changelog

## Read this before creating a tag

**The version in `composer.json` and the git tag must be the same number.**

A tag whose name disagrees with the declared version is a *stray tag*. It costs
nothing to create and a great deal to be confused by: Packagist sorts by
version, so a stray with a high number becomes what `composer require`
installs, and the repository then claims to be at a version that does not
exist. One has already happened here — see [Stray tags](#stray-tags).

So, before tagging:

1. **Read this file.** It is the record of what has actually been released, and
   of what the next number is.
2. Set that number in `composer.json`.
3. Commit the version bump on its own, so the tag points at a commit that is
   only the release.
4. Tag the same number with a `v` prefix — `0.2.1` in `composer.json` means
   `v0.2.1` as the tag, never anything else.
5. Push the branch, then the tag.
6. Confirm Packagist picked it up:
   <https://packagist.org/packages/jatimprovcsirt/panda-php>
7. Add the release to the table below.

Only two numbers matter, and they must match. Nothing else — not the version of
another PANDA SDK, not a date, not the branch — decides what this release is
called.

## Released

Dates are the tag dates. The entries record what the release was *for*; the
commit history has the detail.

| Version | Date | What it was |
|---|---|---|
| `0.2.1` | 2026-10-13 | Blind index normalisation now follows `panda-spec/blind-index-format.md` instead of PHP's own `trim()` and `strtolower()`. Both were ASCII-only, so a value with a trailing non-breaking space — what arrives when text is pasted from a word processor — produced a different index here than in panda-go, panda-node and panda-py. A lookup through another implementation returned nothing, with no error. Adds `ext-mbstring`. |
| `0.2.0` | 2026-08-16 | Version bump; CLI non-interactive mode documentation. |
| `0.1.6` | 2026-08-14 | README corrected PANDA CLI workflow. |
| `0.1.5` | 2026-08-14 | Author name correction. |
| `0.1.4` | 2026-08-13 | Vault AppRole auto-renewal and token TTL configuration. |
| `0.1.3` | 2026-08-10 | PANDA portal URL added to documentation. |
| `0.1.2` | 2026-07-21 | Laravel migration stub fix. |
| `0.1.1` | 2026-07-18 | Laravel integration; 48/64 hex token support. |
| `0.1.0` | 2026-07-12 | First tag. |

## A stray tag that was removed

`v0.5.0` was tagged on the commit that bumped `composer.json` to `0.2.0`. The
tag name and the declared version were never the same number — apparently an
attempt to line this SDK up with `panda-go` and `panda-node`, both of which are
at `0.5.0`, made without raising `composer.json` to match.

It was **never published to Packagist**, so nothing depended on it and no
release went missing. It has since been deleted, from the remote and locally.
That is why the release after `0.1.6` is `0.2.1` and not `0.5.1`.

It is recorded here rather than forgotten because the deletion is not the
interesting part — the reason it could happen is. Nothing in the repository
said what the next version should be, so a number was chosen from outside it.
That is what this file now answers.

**If a stray is ever published by accident, the fix is to delete it on
Packagist, not to release a matching version.** Releasing to match a wrong
number makes it permanent.
