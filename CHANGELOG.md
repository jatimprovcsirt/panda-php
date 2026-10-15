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
patents, and this is cryptographic software that other organizations and vendors are
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
| `0.2.6` | 2026-10-15 | The package could not be installed. A dependency on a package that does not exist. |
| `0.2.5` | 2026-10-15 | Telemetry counts instead of calling out per operation; the audit label now matches the path taken. |
| `0.2.4` | 2026-10-14 | `--provider` on `init` and `generate`; Vault and Infisical were unreachable from a pipeline. |
| `0.2.3` | 2026-10-14 | `panda init` never ran — a Symfony shortcut collision. Quick mode ignored `--kid`. |
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


## What 0.2.4 adds

### `--provider` on `init` and `generate`

`panda-node` had this flag and the other three did not, so `init --quick` could
only ever configure the local provider: **Vault and Infisical were unreachable
from a CI pipeline here.** A team provisioning into Vault from a build could do
it in Node.js and not in the language they were actually using.

It is the sixth instance of this project's recurring defect — a shared rule
with more than one definition. Five `init` flags were uniform before it, and
each became uniform the same way: added to all four in turn, each time after
noticing one SDK had it and another did not. The set is now pinned in
`panda-spec/cli-flags.md`, and `verify_interop.js` section 10 runs `--help` in
all four SDKs and asserts every pinned name appears. Reverting this release's
change fails exactly the two checks written for it.

### Provider settings come from the environment, and are required

`--provider` names the provider; it does not carry its settings, and on this
path there is nobody to ask. The variables are the ones `CLAUDE.md` and the
provider classes already use — `PANDA_VAULT_URL` and either
`PANDA_VAULT_ROLE_ID` + `PANDA_VAULT_SECRET_ID` or `PANDA_VAULT_TOKEN` for
Vault; `PANDA_INFISICAL_CLIENT_ID`, `PANDA_INFISICAL_CLIENT_SECRET` and
`PANDA_INFISICAL_PROJECT_ID` for Infisical.

`PANDA_VAULT_AUTH_METHOD` is inferred when it is not set: role and secret means
`approle`, a token means `token`, neither is an error naming the variable.

**A missing required variable exits 1 and writes nothing** — no `.panda`
directory, no `.env`, no key. The wizard may offer a localhost default because
a person is there to see it and disagree; `--quick` cannot ask, and a
credentials file naming a Vault that nothing said to use behaves like a working
configuration until the first decrypt. The refusal names every variable that is
missing, not the first.

### No local key is written for a non-local provider

A key generated "for" a secret manager is a second key that nothing reads, and
it would be the one in `.env` that a later `panda generate` does not know
about. `--skip-key-gen` therefore has nothing to suppress on this path.

### Submodule pin moves to `panda-spec` v4

The tag that carries `cli-flags.md` and the tenth interop section.


## What 0.2.6 fixes

**The package could not be installed by anyone.**

`composer.json` required `infisical/infisical-php-sdk ^1.0.0`. That package is
not on Packagist. The similarly named `infisical/php-sdk` tops out at v0.0.2,
so the constraint could not have been satisfied by it either.

**The code does not use it.** `InfisicalKeyProvider` talks to Infisical over
raw `curl`. The require line was the only mention of that package anywhere in
the repository — which is how it survived: nothing failed at a call site,
because there is no call site.

Three consequences, and the last is the one that matters:

- CI has been red on every push since 13 October, failing at "Install
  dependencies". `composer.lock` is gitignored, so CI does a **full
  resolution** with no lock to lean on — which is why it failed while a local
  `composer install` against the untracked lock did not.
- Every release carried it, back to the commit that introduced the line.
- **A consumer could not install this package at all**, at any version.
  Reproduced from an empty project:

      jatimprovcsirt/panda-php 0.2.5 requires infisical/infisical-php-sdk ^1.0.0
      -> could not be found in any version

  Composer resolves dependencies transitively, so an unresolvable require in
  this package is unresolvable for whoever depends on it.

The line is gone and the lock hash refreshed. `composer update` — the
resolution CI performs — now completes.

A dependency that is declared, unresolvable and unused is worse than a missing
one: a missing one fails at the call site, this one fails before anything can
be installed at all.

## What 0.2.5 changes

### Telemetry no longer runs inside an operation

The cipher called an HTTP client directly, from `encrypt()` and from the
full-plaintext decrypt — once per operation. A library whose promise is that it
works without the portal was opening a socket in its hot path, and it had to be
handed a token to do it, taken from `PANDA_PORTAL_TOKEN`, **which nothing in
this project has ever set**. So the calls never fired: two independent reasons
for the same silence.

Counting is now an addition to a counter. Reporting happens on a window
boundary — whichever of 300 seconds or 1000 operations comes first — and the
token comes from the credentials `panda init` wrote.

What is sent is pinned in `panda-spec/telemetry-format.md`: three counters
(encrypts, decrypts, raw decrypts) and the SDK version, and on key generation
a one-way fingerprint. No plaintext, no ciphertext, no key material, no field
name, no field value, no record identifier.

### The endpoint had two definitions and they disagreed

This SDK posted telemetry to `https://portal.panda.gov.id/api/telemetry` — a
hostname that does not resolve — while the token gate next door held the
correct one. Both are now derived from a single base URL constant.

### The audit trail said "decrypt_raw" for operations that were not

`decrypt()` calls `decryptRaw()`. The shared implementation logged
`decrypt_raw` on every outcome, and `decrypt` logged `decrypt_masked` on top.
So every masked read wrote **two** audit entries, one of them asserting a raw
decrypt that never happened — and a *failed* masked read wrote only the false
one, leaving it indistinguishable from a failed raw read.

`decryptRaw` is documented as "explicitly logged as a distinct audit event". It
was not distinct, and the log was dominated by raw-decrypt entries for
operations that never decrypted anything raw. The shared implementation now
takes the label, and each public method logs its own outcome exactly once.

### `verify_interop.js` section 11

Every implementation is given the same counters and must produce the same JSON:
224 checks across all four SDKs, up from 200.

### An API change

The cipher no longer takes a telemetry client or a token — there is no client
to hold. Callers that passed them will get a compile error, which is the
correct outcome for an argument that no longer has a meaning.

### Key generation is reported once per key

Every path that produces a key — quick init, the wizard, `generate` and
`rotate-key` — now goes through one function that generates the key and
reports it. Adding the report at each call site would have repeated the defect
this whole change is about: a rule defined by where somebody remembered to
write it, which is how `decrypt` came to be uncounted in the first place.

### The counters are shared across workers, and say so when they cannot be

PHP-FPM recycles workers, so a counter in a worker's memory sees one slice of
the traffic and dies before it reports. Quiet installations — the government
deployment profile — would lose their counts, and the bias would fall on
exactly the installations most worth counting.

The hot path still increments process memory, which is free. The batch is
merged into a shared store at request shutdown, in one operation: APCu when the
extension is present, otherwise a lock-protected file in `.panda/`, which also
survives a php-fpm restart. When neither is available the SDK falls back to
process memory **and logs that it has done so** — a figure that is quietly low
is worse than one known to be low.

The version reported to the portal comes from Composer's record of the
installed package rather than a constant here, so there is no second definition
of it to drift.

## What 0.2.3 fixes

### `panda init` never ran

Two commands were fine and one was not:

```
panda init       ← exited 1, no output
panda status     Description: ...
panda generate   Description: ...
panda config     Description: ...
```

`InitCommand` declared `->addOption('quick', 'q', ...)`. Symfony's Application
defines `--quiet` as `-q` for **every** command, and the collision is raised
when the command is merged with the application:

    An option with shortcut "q" already exists.

That happens during bootstrap, so `init` died before executing a line of its
own. It is the first command anyone runs, and the one the README, the CLI guide
and the portal all open with.

`panda-node` offers `-q` for the same flag and works, because Commander
reserves nothing. Symfony does. The shortcut is gone here.

**The entire test file passed throughout.** Every test calls
`getDefinition()`, which returns the command's *own* options and never merges
the application's — so nothing saw the collision. One test asserted the
shortcut was `q`, which is to say it was asserting the bug. A new test
registers the command with an Application and calls
`mergeApplicationDefinition()`; it fails against the old code with the message
above.

### Quick mode ignored `--kid`

The option was declared, then not passed to `runQuickInit`, which hardcoded
`'default-key'` for both the config and the generated key. So

    php bin/panda init --quick --token <token> --kid my-app-key-1

wrote `PANDA_KEY_DEFAULT_KEY`. The value now carries through, and `defaultKid`
is written to the credentials as the wizard writes it — previously the
identifier appeared in a project only when the wizard had created it.

223 tests pass.

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
