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


## Licence: MIT to Apache-2.0

Every repository now carries a verbatim Apache License 2.0 and a NOTICE file
naming the copyright holder. Four of the six had no licence file at all, and
`panda-node` had no `license` field in its manifest either — it declared
nothing while listing a `LICENSE` in `files` that did not exist, so what npm
actually published was unlicensed.

Apache-2.0 over MIT for one reason: an express patent grant. MIT is silent on
patents, and this is cryptographic software that other agencies and vendors are
expected to embed in their own systems — the grant, and the retaliation clause
that comes with it, is the part that matters when a third party deploys it.
Apache-2.0 is no less permissive, so nothing about adoption gets harder.

GPL was considered and rejected: copyleft would block exactly the cross-agency
adoption this project exists for, and sit badly with public procurement.

**What is already published does not change.** A licence cannot be altered
retroactively on an artifact that has been released, so panda-py 0.2.1-0.2.3 on
PyPI and panda-php 0.2.1-0.2.2 on Packagist stay MIT, panda-node 0.5.0 on npm
stays unlicensed, and the Go tags through v0.5.1 carry no licence text
either. This applies from the next release of each.

The `LICENSE` file is the canonical text byte-for-byte — verified against its
published SHA-256, `cfc7749b...523d30` — so it stays comparable to every other
copy of it.

## Released

Dates are the tag dates. The entries record what the release was *for*; the
commit history has the detail.

| Version | Date | What it was |
|---|---|---|
| `0.2.2` | 2026-10-13 | The portal call had never executed; `{"valid": "false"}` was read as approval. |
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

## What 0.2.2 fixes

### `validateTokenFormat`, and a test that passed for the wrong reason

`TokenValidator` gains a public `validateTokenFormat()`, so the format check is
reachable without going through `validateToken()` and its network call. The
cross-language token vectors need exactly that, and so does any caller that
wants to answer "is this shaped like a token" without asking the portal.

The PHP pattern was already `48|64` and already lowercase-only, so this
implementation needed no correction — it was the reference the other three were
compared against. Two test-side fixes:

- The case-sensitivity test built a 50-character token, not 48. It asserted
  uppercase was rejected, which passed, but it would also have passed after
  somebody made the pattern case-insensitive. It is 48 now, with the length
  asserted directly so it cannot drift again unnoticed.
- The file's header comment still said `[a-f0-9]{48}`. It now points at
  `panda-spec/token-format.md`, which is where the rule lives.

194 tests pass.

### The portal call had never run

Three defects, one behind the other. The first is the reason the other two
survived.

### `CURLOPT_CONNECT_TIMEOUT` does not exist

The portal call set `CURLOPT_CONNECT_TIMEOUT`. There is no such constant; the
real one is `CURLOPT_CONNECTTIMEOUT`, with no underscore between CONNECT and
TIMEOUT. On PHP 8 an undefined constant is a fatal `Error` rather than a
warning and a string, so `validateTokenOnline()` threw at that line and **never
made a request**. This SDK's portal gate has never once run.

Nothing noticed because every test sets `PANDA_SKIP_ONLINE_VALIDATION=1` in
`setUp` and returns before reaching curl. The one code path that mattered was
the one path no test entered.

### `{"valid": "false"}` was read as approval

`if (!$result['valid'])`. A non-empty string is truthy in PHP, so a response
explicitly saying the token is not valid was read as consent and a key was
generated. Same for `{"valid": "no"}` and `{"valid": 1}`. `panda-go` refused
all of them, because decoding a JSON string into a Go `bool` fails, and that
disagreement is how it was found.

Now `if ($result['valid'] !== true)`. Only the JSON boolean `true` is consent.

### A trailing newline was a valid token here and not in Node or Go

PCRE's `$` also matches immediately before a newline at the end of the subject,
so `panda_live_<48 hex>\n` matched here and in `panda-py` while `panda-node`
and `panda-go` rejected it — the same bytes, two answers. A token read from a
file carries one routinely.

The pattern now carries the `D` modifier, which anchors at the end of the
string and nothing else.

### Also

- `composer.json` declares `ext-curl`. `TokenValidator` calls curl
  unconditionally and the extension was not a declared dependency.
- `tests/Unit/Security/TokenPortalValidationTest.php` is new: 28 tests,
  intercepting curl by defining it in the `Panda\Crypto` namespace. The mocks
  delegate to the real extension unless a test switches them on — defining
  them unconditionally broke `InfisicalKeyProviderTest`, whose key fetch also
  calls curl and suddenly received a `stdClass` where it expected a
  `CurlHandle`.

222 tests pass.
