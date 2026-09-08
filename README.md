# switchboard-api

GitHub webhook receiver for ePHPm PR previews. A PSR-15 PHP application that
runs as a **confined virtual host inside ePHPm itself**, verifies incoming
webhook deliveries, and hands work to the switchboard daemon as job files on
disk.

It is deliberately small and deliberately unable to do very much.

## Why this exists

`ephpm/switchboard` was one Rust binary that received webhooks *and* provisioned
previews. It was split in two:

| Component | Language | Runs as | Responsibility |
|---|---|---|---|
| **switchboard-api** (this repo) | PHP | a vhost inside ePHPm | receive webhooks, verify, write jobs |
| **switchboard daemon** (`ephpm/switchboard`, reduced) | Rust | systemd unit beside ePHPm | pick up jobs, check out repos, provision previews, report to GitHub |

The split is forced by a real constraint and turns it into a property worth
having. In `sites_dir` mode ePHPm applies `open_basedir` per request, confining
a vhost to its own directory. **switchboard-api therefore cannot touch
`sites_dir`, and does not try.** That is the design: the component exposed to
the public internet, accepting unauthenticated POSTs, provably cannot reach the
filesystem outside its own directory, cannot run the deploy, and holds no
credential that can write to a GitHub repository.

It is also dogfooding — the control plane runs on the product.

## How a webhook becomes a preview (cluster mode)

Single-node, a webhook writes straight to `.switchboard/queue/` and the local
daemon picks it up — end of story, see [The job file contract](#the-job-file-contract).
Behind a NodeBalancer fronting a cluster, a webhook still lands on exactly
**one** node, but every node's daemon needs the job. This is how it reaches
all of them: the node that received it publishes *desired state* to ePHPm's
gossip-replicated KV instead of enqueuing locally, and every node's own
`GET /drain` — kicked by its local daemon on a timer — reconciles that shared
state back into its own `queue/`.

```
════════════ 1. WEBHOOK ARRIVES (any node) ════════════

   GitHub PR event (opened / synchronize / closed)
        │  POST /webhook  (HMAC-signed)
        ▼
   ┌──────────────────┐
   │  NodeBalancer    │   forwards → lands on ANY one node
   └────────┬─────────┘
            ▼
   ╔═══════════ node N ════════════════╗
   ║  ePHPm                            ║
   ║  ┌──────────────────────────────┐ ║   confined vhost: open_basedir,
   ║  │ switchboard-api               │ ║   no shell, no GitHub App key,
   ║  │  verify HMAC                  │ ║   write-only
   ║  │  validate payload             │ ║
   ║  │  ephpm_kv_set(                │ ║
   ║  │    switchboard:preview:<label>)│─╫──▶ KV: desired state
   ║  │  switchboard:index += <label> │ ║        │  gossip-replicated —
   ║  │  ephpm_kv_incr(switchboard:gen)│ ║        │  see ePHPm's cluster docs
   ║  └──────────────────────────────┘ ║        ▼
   ╚════════════════════════════════════╝  every node's KV now has the job

════════════ 2. EVERY NODE DRAINS, THE DAEMON BUILDS ════════════

   ╔═══════════ each node ══════════════════════════════════════╗
   ║  switchboard daemon (Rust, systemd unit, this repo's sibling) ║
   ║   on a configurable interval:                                ║
   ║     GET 127.0.0.1:8080/drain                                 ║
   ║       Host: <switchboard-api vhost>                          ║
   ║       X-Drain-Token: <.switchboard/drain_secret>              ║
   ║          │                                                    ║
   ║          ▼                                                    ║
   ║   /drain (inside the confined vhost):                         ║
   ║     REMOTE_ADDR == 127.0.0.1?          else 404                ║
   ║     hash_equals(token, drain_secret)?  else 404                ║
   ║     walk switchboard:index EVERY kick (content, not the gen cursor) ║
   ║     diff KV desired state vs .switchboard/applied/<label>       ║
   ║     → write a job file into THIS node's .switchboard/queue/     ║
   ║          │                                                      ║
   ║          ▼                                                      ║
   ║   daemon claims job (link()) → git fetch                        ║
   ║     refs/pull/N/head → build → sites_dir/<label>                 ║
   ╚═══════════════════════════════════════════════════════════════╝
```

A few properties worth calling out:

- **Files, not the KV, still cross the API/daemon boundary on any one node.**
  The KV only ever carries the *what* between nodes; `/drain` turns it back
  into the exact same queue-file format the daemon already reads — see
  [The job file contract](#the-job-file-contract) — so the daemon needs no
  KV awareness at all.
- **Desired state, not an event log.** A GitHub redelivery, a drain that
  crashes partway, or a node rebooting all reconcile against the same
  `switchboard:preview:<label>` key rather than replaying a queue of
  individual webhook events, so nothing double-deploys because a drain
  happened to run twice.
- **The KV RESP listener stays off.** Every read and write above goes
  through the in-process `ephpm_kv_*` SAPI functions, exactly like
  `ephpm/cache` and the other in-process KV consumers — nothing here needs a
  network client or credential to reach the KV.
- **Single-node is the same code, minus the detour.** With no `ephpm_kv_*`
  bridge present, the webhook writes to `queue/` directly, exactly as
  before cluster mode existed, and `/drain` answers
  `{"ok":true,"mode":"single-node","queued":0}` without touching the KV.

See [Cluster mode](#cluster-mode) below for the exact key layout, the
`/drain` response shapes, and how to provision `drain_secret`.

## HTTP surface

Two public endpoints, plus a third that only ever answers loopback traffic.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `POST` | `/webhook` | HMAC signature | receive a GitHub delivery, enqueue (or, in cluster mode, publish) a job |
| `GET` | `/healthz` | none | liveness; reports whether a secret is configured |
| `GET` | `/drain` | loopback + bearer token | cluster mode only: reconcile shared desired state into this node's local queue — see [Cluster mode](#cluster-mode) |

There is **no dashboard and no public read API**. GitHub is the interface: the
daemon creates a Deployment when it picks up a job and posts `in_progress` →
`success`/`failure` with the preview URL as `environment_url`, so the native
"View deployment" box renders in the pull request itself. A developer looking at
a preview is already on the PR page.

A consequence worth stating plainly: **nothing flows back into this service
from the outside.** It is write-only to the public internet. There is no
status directory for the daemon to write into and no endpoint that reads one,
which is what keeps it safe to expose. `/drain` does not change that: it
never returns preview state to its caller (only counts), it only ever answers
loopback, and everything it reads is state this same service published
itself.

## Webhook handling

The order of operations is the contract. Each step is there because skipping it
breaks something specific.

1. **Shape checks** — method, `Content-Type`, declared `Content-Length`.
2. **Read the raw body**, capped at 25 MiB (GitHub's own payload ceiling), in
   bounded chunks rather than one unbounded buffer.
3. **Verify `X-Hub-Signature-256`** — HMAC-SHA256 over the raw body, compared
   with `hash_equals()`. Before parsing, before dispatching on event type,
   before touching the disk.
4. **Filter the event type** — still without decoding JSON.
5. **Decode and validate** the payload.
6. **Apply policy** — repository allowlist, fork gate.
7. **Claim the delivery** for deduplication, then **write the job atomically**.

Some specifics that are easy to get subtly wrong:

- **The signature is computed over the raw bytes**, never over a re-encoded
  payload. `json_decode` followed by `json_encode` does not round-trip
  byte-for-byte, so a re-serialized body fails against a signature GitHub
  computed correctly. There is a test for exactly this.
- **`hash_equals()`, never `==`.** PHP string comparison short-circuits on the
  first differing byte, leaking the length of the matching prefix through
  timing.
- **Fail closed.** No configured secret means every delivery is rejected with a
  `500` and a loud log line. There is no "unsigned is acceptable" mode.
- **Signature verification precedes event filtering**, so an unauthenticated
  prober gets an identical `401` for every event type and cannot map which
  events we act on.
- **The webhook content type must be `application/json`.** With
  `application/x-www-form-urlencoded` PHP consumes `php://input` to populate
  `$_POST` and the exact signed bytes are gone. This is detected and answered
  `415` with an actionable message rather than surfacing as a confusing
  signature mismatch.
- **Unwanted events get a `2xx`.** A non-2xx would make GitHub retry the event
  forever and show the App as failing.

### Deduplication

GitHub redelivers — automatically on a non-2xx or timeout, manually from the
App's delivery UI — and a redelivery carries the **same** `X-GitHub-Delivery`
GUID. That header is the only durable signal that two requests are the same
event.

The primitive is `fopen($path, 'x')` (`O_CREAT|O_EXCL`): it succeeds for exactly
one caller and fails for every other, atomically, in the kernel. It needs no
lock file and it survives a restart — which an in-memory or KV-backed check
would not. (ePHPm's KV store is a `DashMap`; a restart empties it, and dedup
state that evaporates is not dedup state.)

The GUID arrives in a request header, so it is validated against a UUID shape
*and* stored under its SHA-256. Even if the shape check were wrong, the value
reaching the filesystem is 64 hex characters.

**The crash window, and what is done about it.** The marker is claimed *before*
the job is written, so a duplicate can never race past the claim. The cost is
that a process dying between claiming and writing would leave a marker with no
job, permanently suppressing redelivery — the PR would silently never deploy. So
the marker records a state: written `claimed`, promoted to `queued` once the job
is in the queue. A marker still `claimed` after 120 seconds can only have come
from a crashed request and is re-claimable. A healthy request promotes in about
a millisecond.

Residual race, stated rather than hidden: two redeliveries of the *same*
abandoned marker arriving simultaneously, more than two minutes after a crash,
can both re-claim and produce two jobs. Closing it needs a lock the rest of this
design does not require, and a duplicate deploy of an already-crashed delivery
is a better failure than a preview that never appears.

### Responding fast

GitHub abandons a webhook at 10 seconds. This handler makes **no network calls
at all** and touches the disk a handful of times: one HMAC, one JSON decode, one
exclusive create, one write plus rename.

Everything expensive belongs to the daemon — including every call to the GitHub
API. See [Who talks to GitHub](#who-talks-to-github).

## The job file contract

**This is the interface the daemon implements against.** Schema version 1.

### Location and naming

```
<vhost>/.switchboard/
  tmp/                 staging for atomic writes (same filesystem — see below)
  queue/               jobs the daemon consumes          API → daemon
  queue/claimed/       jobs the daemon is working on
  deliveries/          dedup markers, one per delivery GUID
  webhook_secret       operator-provided, never committed
```

Job filenames are `<millis>-<16 hex>.json` — a 13-digit zero-padded Unix
millisecond timestamp, then 16 hex characters derived from the delivery GUID.
Thirteen digits keeps the timestamp fixed-width until the year 2286, which makes
**lexicographic order equal chronological order**: the daemon can process the
queue in arrival order with `readdir` + `sort`, without opening every file
first.

### Atomicity

Jobs are written to `tmp/` and `rename()`d into `queue/`. `rename()` within a
filesystem is atomic — the destination name resolves to the old inode or the new
one, never to a partial file. `tmp/` is inside the same tree specifically so
that holds; `sys_get_temp_dir()` would be a different mount on most real
deployments, turning the rename into a copy.

`tmp/` is a *sibling* of `queue/`, so a `queue/*.json` scan can never pick up a
file that is still being written.

Durability caveat, stated rather than implied: the file's contents are `fsync`ed
before the rename, but the **directory entry** created by the rename is not —
PHP cannot open a directory to sync it. A power loss immediately after a rename
can lose a job the API already acknowledged. GitHub's redelivery is the recovery
path, which is why the delivery marker is released on any enqueue failure.

### Document

```json
{
  "schema": 1,
  "job_id": "1787456737243-b81167e5b47a38b9",
  "delivery_id": "aaaaaaaa-1111-2222-3333-000000000001",
  "event": "pull_request",
  "action": "opened",
  "intent": "deploy",
  "received_at": "2026-08-23T03:45:37.243Z",
  "received_at_ms": 1787456737243,
  "preview": {
    "label": "ephpm-wordpress-sample-pr-7"
  },
  "repository": {
    "full_name": "ephpm/wordpress-sample",
    "owner": "ephpm",
    "name": "wordpress-sample",
    "clone_url": "https://github.com/ephpm/wordpress-sample.git",
    "default_branch": "main",
    "private": false
  },
  "pull_request": {
    "number": 7,
    "title": "Live test",
    "draft": false,
    "merged": false,
    "fork": false,
    "head": {
      "ref": "feature/live",
      "sha": "0123456789abcdef0123456789abcdef01234567",
      "clone_url": "https://github.com/ephpm/wordpress-sample.git",
      "repo_full_name": "ephpm/wordpress-sample",
      "pull_ref": "refs/pull/7/head"
    },
    "base": { "ref": "main" }
  },
  "sender": "octocat",
  "installation_id": 999
}
```

### Field reference

| Field | Type | Notes |
|---|---|---|
| `schema` | int | Always `1`. A daemon **must** reject an unrecognised value rather than guess. |
| `job_id` | string | `<millis>-<16 hex>`. Unique, sortable, safe as a filename. Matches the filename minus `.json`. |
| `delivery_id` | string | The `X-GitHub-Delivery` GUID. Use for tracing back to the delivery in GitHub's UI. |
| `event` | string | Always `"pull_request"` in schema 1. |
| `action` | string | The raw GitHub action: `opened`, `synchronize`, `reopened`, `closed`. |
| `intent` | string | `"deploy"` or `"teardown"` — the API's interpretation of `action`. **Act on this**, not on `action`. |
| `received_at` | string | RFC 3339, millisecond precision, UTC. |
| `received_at_ms` | int | Same instant as an integer, for arithmetic. |
| `preview.label` | string | **Authoritative.** See below. |
| `repository.*` | | `clone_url` is validated `https://` on the configured GitHub host. |
| `pull_request.number` | int | 1..1e9, and cross-checked against the payload's top-level `number`. |
| `pull_request.fork` | bool | True when the head repo differs from the base repo, **or when the head repo is absent** (deleted fork). |
| `pull_request.head.ref` | string | Validated: no leading `-`, no `..`, `[A-Za-z0-9._/+-]` only, ≤255 chars. |
| `pull_request.head.sha` | string | Validated: 40 or 64 lowercase hex. |
| `pull_request.head.clone_url` | string | The head repo's URL, falling back to the base repo's when the head repo is absent. |
| `pull_request.head.repo_full_name` | string\|null | `null` when the fork was deleted. |
| `pull_request.head.pull_ref` | string | `refs/pull/<n>/head` — see below. |
| `sender` | string\|null | The GitHub login that triggered the event. |
| `installation_id` | int\|null | The GitHub App installation. **The daemon needs this to mint a token**; the API never does. |

Two fields deserve their own note.

**`preview.label` is authoritative and must not be recomputed.** It is the
primary key of a preview: it names the directory under `sites_dir`, the vhost
ePHPm resolves, and the host in the deployment URL. The daemon appends its
configured preview domain (`<label>.<preview_domain>`) and otherwise uses the
value verbatim. One producer for the identity means the API and the daemon
cannot drift into disagreeing about which directory a PR maps to. The generation
rules are ported from `preview_label()` in the Rust and pinned by tests against
the Rust suite's own expected values.

**`head.pull_ref` is the recommended fetch path.** `refs/pull/<n>/head` resolves
the head commit from the *base* repository. For a fork PR that works without
trusting a third-party clone URL, and it works when the fork has been deleted.

### Values are validated, not merely typed

A signed payload is authentic, not harmless — it is still text that becomes an
argument to `git` and a path component in the daemon. `head.ref`, `head.sha`,
owner/repo names and clone URLs are all constrained at this boundary, so the
daemon receives values that have already been checked. A ref like
`--upload-pack=/bin/sh` is rejected with a `400` and never reaches the queue.
(Argument-vector execution already prevents *shell* injection; this prevents
*argument* injection, which it does not.)

### How the daemon should consume the queue

1. `readdir` `queue/`, keep `*.json`, sort ascending — that is arrival order.
2. **Claim atomically**: `link(queue/<f>, queue/claimed/<f>)` then
   `unlink(queue/<f>)`. `link()` fails with `EEXIST` if another worker already
   claimed it, which `rename()` would not — `rename()` overwrites silently and
   both callers would believe they won.
3. Process. Delete from `claimed/` on success; leave it for inspection on
   failure, or move it aside.
4. **Coalescing is the daemon's job.** Rapid pushes produce several
   `synchronize` jobs for the same `preview.label`. Taking the newest per label
   and discarding older ones is safe and desirable; the API does not do it
   because superseding a job races with the daemon's claim.

## Cluster mode

See [How a webhook becomes a preview](#how-a-webhook-becomes-a-preview-cluster-mode)
above for the end-to-end picture. This section is the reference: the exact KV
keys, the `/drain` response shapes, and how to provision `drain_secret`.

Cluster mode activates itself — there is no `SWITCHBOARD_*` flag for it. The
webhook handler checks `function_exists('ephpm_kv_set')` once, at boot: if
ePHPm's KV SAPI bridge is linked in, every subsequent webhook publishes
desired state instead of writing straight to the local queue. On a
single-node install (or any build without the KV bridge) it is exactly the
behaviour this service always had.

### KV key layout

Three keys, all read and written through the in-process `ephpm_kv_*` SAPI
functions — the KV RESP listener stays off; nothing here needs it, and this
vhost is confined to its own per-site KV keyspace the same way it is confined
to its own per-site database (ePHPm scopes both to the resolved vhost in
multi-tenant `sites_dir` mode).

| Key | Value | Written by | Read by |
|---|---|---|---|
| `switchboard:preview:<label>` | The full schema-1 job document (see [The job file contract](#the-job-file-contract)), verbatim. No TTL while live; a 24h TTL once a node has queued its teardown | `/webhook`, on every deploy or teardown | `/drain` |
| `switchboard:index` | JSON array of every label with a `switchboard:preview:<label>` key | `/webhook` (adds), `/drain` (removes, only once the preview key has expired) | `/drain` |
| `switchboard:gen` | A counter (`ephpm_kv_incr`), bumped on every publish | `/webhook` | `/drain` (fast-path comparison) |

There is no scan in the KV's SAPI surface (verified against
`crates/ephpm-php/ephpm_wrapper.c`: get/set/setnx/del/exists/incr/decr/
incr_by/expire/ttl/pttl/flush_all/wait — no `keys` or `scan`), which is why
`switchboard:index` exists at all: it is a hand-rolled enumeration of every
`switchboard:preview:*` key.

**The index read-modify-write race is acknowledged, not closed.** Adding a
label to `switchboard:index` means read the array, append, write it back —
two webhooks for two different labels landing on two different nodes at the
same instant can race, and one addition can be lost. This is bounded and
self-healing rather than silent: `switchboard:preview:<label>` itself is a
single `set()`, so the desired state a lost index update "hid" still exists,
and the next webhook for that label (or an operator-triggered re-drain after
the index is fixed) recovers it. Closing the race properly would need a lock
this design does not otherwise require. See
`Switchboard\Cluster\ClusterState`'s doc comment for the full reasoning.

**Retiring a teardown is not the same as finishing one.** Desired state is
published once and must be consumed independently by *every* node, so nothing
may make it unreadable at the moment a single node is done with it. The
webhook path adds a label to the index on both deploy and teardown and never
removes one. `/drain`, once it has queued a teardown locally, sets a **24-hour
TTL** on `switchboard:preview:<label>` — it does not delete the key, and it
does not touch the index. The index shrinks in exactly one place: when a drain
finds a label whose preview key has already expired, i.e. every node has had a
day to act.

This is the fix for [switchboard#24](https://github.com/ephpm/switchboard/issues/24).
Deleting the key and pruning the index as soon as one node had queued the
teardown turned the shared state into a race with a single winner. Nodes whose
two-second drain tick had not yet fired walked an index the label had already
left, queued nothing, and advanced `last_gen` anyway — recording themselves
current at a generation whose work they had never done. In production that
left a torn-down preview still being served by part of the cluster (round-robin
DNS, so roughly a third of requests) with every health check green, and its
per-tenant database on disk indefinitely.

Re-materializing in the meantime costs nothing: the `applied/<label>` marker
suppresses it. That makes the marker load-bearing, and is why the daemon
**keeps** a `teardown@<sha>` marker instead of reaping it. The two halves are
one contract — **the daemon's change ships first**; see the daemon's
`teardown.rs`.

**Reconcile on content, not on the generation cursor.** `switchboard:gen` is a
single best-effort, gossip-replicated counter, so a lost or lagged increment can
leave a preview key's content changed — a deploy flipped to a teardown — while
`gen` still equals a node's last-seen value. `/drain` therefore walks the index
and compares desired state against the `applied/<label>` markers on **every**
kick; it never lets `gen == last_gen` short-circuit that. An earlier version did,
and a node whose cursor already matched never learned about a teardown it hadn't
received a webhook for, so it kept serving a torn-down preview with every health
check green (the generation-vs-content race one layer under switchboard#24). The
cursor is kept only for observability: when a walk queues work `gen` did not
announce, `/drain` logs it as the signature of a lagged increment. Resetting
`last_gen` to force a re-walk is explicitly **not** the fix — that resurrects
stale deploy intents; the `applied/` markers are what keep the always-on walk
idempotent.

### `GET /drain`

Three independent gates, each answered identically — `404`, the same body
`Router` returns for a genuinely unmatched path — so a probe of this endpoint
learns nothing, the same property this README already claims for the removed
dashboard and read API:

1. **`REMOTE_ADDR` must be `127.0.0.1`.** A NodeBalancer fronting a cluster
   forwards internet traffic to the same listener this vhost answers on, so
   this vhost's ePHPm configuration must **never** put the NodeBalancer in
   `trusted_proxies` — if it did, a client-supplied `X-Forwarded-For` could
   spoof `REMOTE_ADDR` and defeat this check entirely.
2. **`X-Drain-Token`** must match a line in `.switchboard/drain_secret`,
   compared with `hash_equals`. Belt and braces against anything else sharing
   the loopback interface on the same host.
3. **Fail closed.** A missing or empty secret file, a missing header, or a
   wrong token are all `404`.

Response shapes, all `200` once the gates pass:

| Situation | Body |
|---|---|
| No KV bridge (single-node) | `{"ok":true,"mode":"single-node","queued":0}` |
| Reconciled (every cluster-mode kick) | `{"ok":true,"gen":<n>,"checked":<labels examined>,"queued":<jobs written>}` |

`checked` is the number of labels in `switchboard:index` examined this kick and
`queued` the number whose desired state differed from this node's `applied/`
marker; a kick with nothing to do returns `queued: 0` with `checked` equal to
the index size.

Materialization compares, per label in `switchboard:index`, the desired
`<intent>@<sha>` (from `switchboard:preview:<label>`) against a local marker
at `.switchboard/applied/<label>` — the last `<intent>@<sha>` **this node**
has already queued. Unchanged: skipped, which is what makes a re-kick with
nothing new a no-op. Changed: a job file is written using the exact same
atomic-write / `<millis>-<16 hex>.json` code the webhook path uses, with a
**freshly generated `job_id` and filename** — this is a per-node
materialization of shared state, not a redelivery of the original webhook,
so there is nothing to deduplicate against and no reason to preserve the
original job id beyond the `delivery_id` field already inside the document.

`/drain` makes no network calls. A kick with nothing changed is one index read
plus, per indexed label, a preview-key read and a local `applied/` marker
comparison — cheap, and paid every kick precisely so a lagged `switchboard:gen`
can never hide a teardown (issue #4). `gen`/`.switchboard/last_gen` are still
read and recorded, but only for the observability log line, never to skip the
walk.

### Provisioning `drain_secret`

Same shape and rotation story as `webhook_secret` (see [Secrets](#secrets)):

```bash
umask 077
openssl rand -hex 32 > /var/www/sites/switchboard/.switchboard/drain_secret
chmod 600 /var/www/sites/switchboard/.switchboard/drain_secret
```

Configure the same value on the switchboard daemon's side, and set the
daemon's drain-kick interval (an option the daemon owns, not this repo —
see `ephpm/switchboard`'s own documentation). A short interval (a few
seconds) keeps propagation fast without costing anything on a re-kick that
finds nothing changed.

`drain_secret` lives beside `webhook_secret` in `.switchboard/`, so it
inherits the same protection: unreachable over HTTP because ePHPm refuses
any request path with a dot-prefixed segment, and covered by this vhost's
`open_basedir` and no other tenant's.

## Who talks to GitHub

**The daemon does. The API never does.** It holds no GitHub App key and makes no
outbound HTTP request of any kind.

Two reasons, in order of weight:

1. **Credential blast radius.** Minting an installation token requires the
   GitHub App private key, which can write to every repository the App is
   installed on. Putting that key in the internet-facing component that accepts
   unauthenticated POSTs would hand away the confinement this split exists to
   create. The daemon runs unconfined and already holds the key
   (`get_installation_token` in the current `main.rs`).
2. **Latency.** A call to `api.github.com` on the webhook path puts an unbounded
   network round-trip inside a 10-second budget, for no benefit.

The trade-off is honest: if the daemon is down, nothing appears on the PR at
all. Mitigation is for the daemon to create the Deployment as its **first**
action on claiming a job, before cloning — sub-second in the normal case.

Recommended daemon lifecycle:

| When | Deployment state | `environment_url` |
|---|---|---|
| job claimed | create Deployment, status `queued` | — |
| clone/build started | `in_progress` | — |
| preview healthy | `success` | `https://<label>.<preview_domain>` |
| any step failed | `failure` | — |
| `intent: teardown` done | `inactive` on the existing Deployment | — |

Build failures should additionally surface as a check run or a PR comment, since
a `failure` deployment state alone does not carry the log.

## Secrets

This section covers `webhook_secret`, the one every deployment needs. Cluster
deployments also provision `drain_secret`, which follows the identical file
format and rotation story — see [Provisioning drain_secret](#provisioning-drain_secret)
under [Cluster mode](#cluster-mode) rather than duplicating it here.

The webhook secret comes from one of two places, in this order:

1. **`.switchboard/webhook_secret`** — the recommended source.
2. `SWITCHBOARD_WEBHOOK_SECRET` in the environment — a fallback for container
   deployments.

The file wins for a reason specific to ePHPm multi-tenancy: **the process
environment is shared by every vhost in the ePHPm process**, so any other site on
the same instance could read `SWITCHBOARD_WEBHOOK_SECRET` with `getenv()`. A
file inside this vhost's directory is covered by this vhost's `open_basedir` and
no other's, which is the isolation boundary ePHPm actually enforces. Prefer the
file whenever switchboard-api shares an instance with anything else.

```bash
umask 077
openssl rand -hex 32 > /var/www/sites/switchboard/.switchboard/webhook_secret
chmod 600 /var/www/sites/switchboard/.switchboard/webhook_secret
```

Then set the same value as the webhook secret on the GitHub App, with content
type **JSON**.

**Rotation.** The file may hold several secrets, one per line; a signature
matching any of them is accepted. Add the new secret, update the App, remove the
old one — no window of rejected deliveries. Blank lines and `#` comments are
ignored.

`.switchboard/` is gitignored and unreachable over HTTP. Nothing in it is ever
committed.

### A note on `$_SERVER`

Credentials ePHPm injects per request (`DB_*`, `EPHPM_REDIS_*`) arrive through
the SAPI's `register_server_variables` hook and land in **`$_SERVER` only** —
neither `getenv()` nor `$_ENV` sees them. `Config::env()` therefore reads
`$_SERVER` first and falls back to `getenv()`. This service uses no database,
and in cluster mode it *does* use the embedded KV store — via the `ephpm_kv_*`
SAPI functions (`SapiKvClient`), which need no injected credentials — so today
nothing depends on the `$_SERVER` injection path, but any configuration added
later must follow the same order or it will silently find nothing.

## Configuration

All optional except the secret.

| Variable | Default | Purpose |
|---|---|---|
| `SWITCHBOARD_WEBHOOK_SECRET` | — | Fallback if the secret file is absent. |
| `SWITCHBOARD_STATE_DIR` | `<app>/.switchboard` | Where the queue and markers live. |
| `SWITCHBOARD_ALLOW_FORKS` | `false` | Queue deploys for pull requests from forks. |
| `SWITCHBOARD_ALLOWED_REPOS` | unset (all) | Comma-separated `owner/repo` or `owner/*`. |
| `SWITCHBOARD_GITHUB_HOST` | `github.com` | Host clone URLs must belong to. Set for GHES. |
| `SWITCHBOARD_MAX_BODY_BYTES` | `26214400` | Request body ceiling. |

### Forks are refused by default

This is a **behaviour change from the Rust**, and an intentional one. A fork PR's
head is code from outside the organisation, and the daemon runs the checked-out
manifest's `build:` and `seed:` commands with the operator's secrets resolved
into the preview environment (`secrets.rs`). The current Rust deploys fork PRs
with no gate at all. Building untrusted code with your secrets in the
environment should be a decision, so it now requires `SWITCHBOARD_ALLOW_FORKS`.

Teardown of a fork PR is always allowed — removing a preview is safe, and
refusing would leak previews forever.

## Deploying it

switchboard-api is a **flat** application: the directory ePHPm serves *is* the
application directory. There is no `public/`, because ePHPm currently serves the
site container itself as the web root.

```
/var/www/sites/switchboard/
  index.php          front controller — the only file that may execute PHP
  worker.php         opt-in worker entrypoint (not reachable over HTTP)
  src/               application code
  vendor/            Composer dependencies
  .switchboard/      state + secret (unreachable over HTTP)
```

That layout means `vendor/`, `composer.json` and `src/` sit inside the document
root, so **`[server.security] blocked_paths` is not optional**. Without it an
unauthenticated caller can fetch `/composer.lock` — a precise inventory of
dependency versions to check against known CVEs — and read the source.
`.switchboard/` needs no rule: ePHPm refuses any request path containing a
dot-prefixed segment and `hidden_files` defaults to `"deny"`.

See [`examples/ephpm.toml`](examples/ephpm.toml) for the full configuration. The
essential part:

```toml
[server.security]
blocked_paths = [
    "/vendor/*", "/src/*", "/tests/*", "/tools/*", "/examples/*",
    "/composer.json", "/composer.lock", "/worker.php",
    "/README.md", "/MIGRATION.md",
]
allowed_php_paths = ["/index.php", "/webhook", "/healthz", "/drain"]
open_basedir = true
```

**`allowed_php_paths` matches the pre-rewrite URI**, not the resolved script, so
a front-controller application must list its routes rather than just
`/index.php`. That is verified behaviour, not an assumption — listing only
`/index.php` returns `403` for every request. It duplicates the route list in
two places, which for a two-route (three-route in cluster mode) application is
a feature: ePHPm becomes a second, independent allowlist in front of the app's
own routing. Listing `/drain` unconditionally is harmless in single-node
deployments — `DrainHandler`'s own localhost + token gate still applies
underneath, see [Cluster mode](#cluster-mode).

```bash
cd /var/www/sites/switchboard
composer install --no-dev --optimize-autoloader
```

## Worker mode (opt-in)

The application is PSR-15, so it runs unmodified under
[`ephpm/psr15-worker`](https://github.com/ephpm/psr15-worker):

```bash
composer require ephpm/psr15-worker   # plus the vcs repositories — see that README
```

```toml
[php]
mode = "worker"
worker_script = "worker.php"
```

**fpm mode is the default deliberately.** A webhook receiver is low-traffic — a
handful of deliveries per push — so the per-request bootstrap cost worker mode
removes is close to irrelevant here. What worker mode adds is a long-lived
process in which state can leak between requests, and this is security-sensitive
code where that failure mode is at its most expensive. Being PSR-15 gets the
option without betting the webhook path on it.

The object graph holds no request state — configuration and storage objects are
resolved once and read-only thereafter, `Pipeline` walks a fresh index per call,
and nothing caches a request, response, or body — so it is correct under both.
That discipline is followed regardless of which mode is configured.

## Dependencies

Five packages. Four of them contain no runtime code.

| Package | Version | Why |
|---|---|---|
| `laminas/laminas-diactoros` | ^3.8 | PSR-7 + PSR-17 implementation |
| `psr/http-message` | ^2.0 | interfaces only |
| `psr/http-factory` | ^1.1 | interfaces only |
| `psr/http-server-handler` | ^1.0 | interfaces only |
| `psr/http-server-middleware` | ^1.0 | interfaces only |

**Diactoros over the alternatives**, on evidence gathered 2026-08-22:

- `guzzlehttp/psr7` was rejected. It took **four medium CVEs between May and
  July 2026** (CVE-2026-48998, -49214, -55766, -59882), all in URI host handling
  and header serialization, with 304 commits on the default branch in six months
  and work still landing after 3.0.0. Current releases are patched, but that is
  the churn profile you least want on the one code path an anonymous attacker
  reaches. It also carries 4 transitive dependencies.
- `nyholm/psr7` is minimal and clean (2 transitive deps, zero advisories against
  current) but **quiet: 0 commits in six months, 1 in twelve**. Its companion
  `nyholm/psr7-server` has had **no commit since 2023-11-08**.
- `laminas/laminas-diactoros` has the same 2-dependency footprint as nyholm and
  is the most actively maintained of the three: **19 commits in six months, last
  2026-08-18**, latest release 3.8.0 (2025-10-12), no open advisories. It also
  ships `ServerRequestFactory::fromGlobals()`, so no separate
  request-from-globals package is needed at all.

Given "an unmaintained dependency here is the mistake I'd most regret", active
maintenance at equal dependency cost decided it.

**No router package, and no PSR-15 dispatcher package.** The surface is two
endpoints; there is no pattern to compile and no path parameters to extract, so
routing is one `match` in `src/Http/Router.php`. A PSR-15 pipeline is ~25 lines
(`src/Http/Pipeline.php`) because PSR-15 specifies the contract precisely. The
candidates were `relay/relay` (dormant — last commit 2024-10-22) and
`laminas-stratigility` (actively maintained, but 6 transitive deps including
`laminas-escaper`); neither buys anything over 25 lines for this, and third-party
runtime code on an anonymous-POST path is the thing to minimise.

The four FIG interface packages were verified to be interface-only: one to seven
`*Interface.php` files each, no `require` beyond `php` and other `psr/*`
packages, and zero advisories ever.

## Testing

```bash
composer install
php tools/test.php            # everything
php tools/test.php Signature   # filter by class-name substring
```

93 tests, 312 assertions, no test-framework dependency — see
`tests/Support/TestCase.php` for why. Coverage is weighted toward the paths that
matter:

- **`SignatureVerifierTest`** — including GitHub's own documented HMAC vector,
  wrong/missing/malformed signatures, body tampered after signing (five
  mutations, including a single trailing space), a re-serialized payload failing
  against a valid signature, fail-closed with no secret, and rotation.
- **`DeliveryLogTest`** — first claim wins and second is refused, durability
  across instances, hashed marker filenames, the stale-claim reclaim window, a
  `queued` marker never being reclaimed, and an unwritable directory raising
  rather than silently reporting "duplicate".
- **`AtomicWriteTest`** — the destination never existing partially written, a
  queue scan never seeing staging files, staging cleanup on failure, and
  job ids sorting chronologically.
- **`PreviewLabelTest`** — cross-checked against the expected values in the Rust
  suite, including the multi-byte case where the byte-wise PHP port and the
  char-wise Rust must agree.
- **`WebhookEndpointTest`** — the full pipeline end to end, single-node.
- **`ClusterWebhookTest`** — cluster mode: a valid delivery publishes
  `switchboard:preview:<label>`, adds to `switchboard:index`, and bumps
  `switchboard:gen`, while writing **no** local queue file; rejected/duplicate
  deliveries publish nothing; a teardown publishes but does not prune the
  index; two labels accumulate independently in the index without duplicates.
  Injects `Tests\Support\FakeKvClient` — an in-memory `KvClient` — directly
  into `App::build()` rather than relying on `function_exists('ephpm_kv_set')`,
  so cluster-mode and single-node tests can run in either order in the same
  process without leaking into each other (see `Cluster\KvClient`'s doc
  comment for why that matters).
- **`DrainTest`** — the localhost/token/secret-file gates (all answering
  `404`, including an empty or missing secret file); single-node mode as a
  no-op; materializing exactly one job file per changed label; idempotency on
  a re-kick, driven by the per-label `applied/` marker (the drain reconciles
  content on every kick rather than short-circuiting on `switchboard:gen`);
  a teardown being queued and retired on a TTL rather than deleted; a
  corrupted index entry being skipped rather than used as a path component.
  Also two regressions. The switchboard#24 three-node fixture — one shared
  `FakeKvClient`, three independent state dirs, the real topology — where one
  node receives the webhook and all three must queue the teardown. And the
  issue #4 generation-vs-content divergence: a node whose `last_gen` already
  equals the published `gen` (a gossip-lagged increment) still reaps a
  teardown whose content replicated, where the old cursor short-circuit
  queued nothing.

### Verified against a real ePHPm instance

The suite above runs in-process. `tools/live-verify.sh` drives a **real ePHPm
binary** serving this app as a vhost, with `curl`. All 28 checks pass:

- The webhook accepts a valid signature and writes exactly one job; a duplicate
  `X-GitHub-Delivery` returns `200 duplicate` and writes no second job.
- Wrong, missing and tampered signatures are all `401` and queue nothing.
- `ping` is acknowledged, unknown events get `202`, fork deploys are refused.
- **Confinement is real, not assumed**: `/.switchboard/webhook_secret`,
  `/.switchboard/queue/` and `/.switchboard/deliveries/` are all `403`, and so
  are `/composer.json`, `/vendor/autoload.php`, `/src/Config.php` and
  `/worker.php` under the shipped `blocked_paths`.
- The removed dashboard and read API are gone, not dormant.

The script hardcodes paths from the machine it was written on; adjust `EXE`,
`BASE` and `SITE` before running it elsewhere. It predates cluster mode and
does not yet exercise `/drain` against a real ePHPm binary — cluster-mode
coverage today is the in-process `ClusterWebhookTest`/`DrainTest` suite only.

## Known gaps

- Worker mode is **untested**. The app is PSR-15 and holds no request state, but
  it has not been run under `ephpm/psr15-worker`.
- **Cluster mode has not been verified against a real multi-node cluster.**
  The KV desired-state publish and `/drain` reconciliation are covered by the
  in-process `ClusterWebhookTest`/`DrainTest` suite (a fake `KvClient`
  stands in for the gossip-replicated KV), and `App::boot()`'s
  `SapiKvClient::available()` wiring has been smoke-tested against a real
  `ephpm php` process, but no end-to-end run against an actual 3-node ePHPm
  cluster behind a NodeBalancer has happened yet. `tools/live-verify.sh`
  predates cluster mode entirely (see [Testing](#testing)).
- Retention of delivery markers rides on request traffic (one request in fifty
  prunes, bounded). A vhost that receives no traffic never prunes. The same is
  true of `.switchboard/applied/` markers in cluster mode: nothing prunes a
  marker left behind by a label whose preview was torn down and never
  redeployed. As of switchboard#24 that is deliberate rather than an oversight
  — the `teardown@<sha>` marker is this node's receipt, and it is what stops
  `/drain` re-queueing the teardown while the desired state is still published.
  One ~50-byte file per label ever previewed, superseded in place if the PR is
  reopened. Bounded by PR count, not by time, so it is still not *cleaned up*.
- **A node down longer than the 24-hour retired-preview TTL permanently misses
  any teardown retired while it was away.** The TTL bounds how long shared
  desired state stays discoverable; a node absent for longer comes back with no
  way to learn the preview should have come down. The "cursor advanced but
  nothing queued" warning is what surfaces it — there is no automatic
  reconciliation of a node's `sites_dir` against the cluster's live site list.
