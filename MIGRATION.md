# Migration note: reducing `ephpm/switchboard` to a daemon

What moved to `ephpm/switchboard-api`, what stays in `ephpm/switchboard`, and
what has to be built. Written so the daemon can be reduced without guesswork.

Sizes are from `ephpm/switchboard` at the time of the split.

## Summary

| `src/` file | Size | Disposition |
|---|---|---|
| `webhook.rs` | 11.9 KB | **Mostly moved.** Keep only `preview_host` — and change it to read the label from the job. |
| `main.rs` | 10 KB | **Split.** HTTP server deleted; GitHub App token minting stays. |
| `config.rs` | 5 KB | **Trimmed.** Drop the HTTP/webhook flags, add the queue directory. |
| `github.rs` | 11.6 KB | **Stays, and grows.** Deployment statuses become the primary interface. |
| `deployer.rs` | 33 KB | **Stays unchanged.** |
| `manifest.rs` | 20 KB | **Stays unchanged.** |
| `secrets.rs` | 11 KB | **Stays unchanged.** |

The daemon stops being an HTTP server and becomes a queue worker. It loses
`axum`; it keeps `reqwest`, `tokio`, `serde`, and everything under
`deployer.rs`.

---

## Moved to switchboard-api — delete from the daemon

### `webhook.rs` — nearly all of it

| Item | Notes |
|---|---|
| `verify_signature` | Now `Switchboard\Webhook\SignatureVerifier`. The daemon never sees a signature. |
| `PullRequestEvent` and friends (`PullRequest`, `PullRequestHead`, `PullRequestBase`, `PullRequestRepo`, `Repository`, `RepoOwner`, `Installation`) | Replaced by deserializing the job file. **Do not** re-derive these from a webhook payload — the daemon no longer receives one. |
| `PullRequestEvent::should_deploy` / `should_teardown` | The API has already made this decision; read the job's `intent` field (`"deploy"` \| `"teardown"`). |
| `PullRequestEvent::clone_url` | The job carries `pull_request.head.clone_url` with the same fork fallback already applied. |
| `preview_label`, `sanitize_label`, `identity_hash`, `MAX_LABEL`, `HASH_LEN` | Ported to PHP. **The daemon must not recompute the label** — use `preview.label` from the job verbatim. Two producers of the primary key is how the API and daemon end up disagreeing about which directory a PR maps to. |

**Keep `preview_host`**, reduced to what it now is — one concatenation:

```rust
/// The preview hostname for a job. The label is authoritative and comes from
/// the API; the daemon only supplies the domain.
fn preview_host(label: &str, preview_domain: &str) -> String {
    format!("{label}.{preview_domain}")
}
```

The existing `webhook.rs` tests for `preview_label` are worth keeping as a
**cross-implementation guard**: `switchboard-api`'s `PreviewLabelTest` asserts
the same expected values, so if either side ever changes the algorithm, one of
the two suites fails.

### `main.rs` — the HTTP half

Delete: the `axum` router, `/webhook` and `/health` routes, `handle_webhook`,
`AppState`, `axum::serve`, the `TcpListener` bind, and the `axum` dependency.

### `config.rs` — webhook fields

Delete `listen` and `webhook_secret`. Nothing in the daemon verifies signatures
any more.

---

## Stays in the daemon — unchanged

- **`deployer.rs`** in full. Clone, framework detection, `build:`, `materialize_env`,
  the atomic swap into `sites_dir`, `seed:`, and the health gate. Its inputs now
  come from a job file instead of a `PullRequestEvent`; the pipeline is identical.
- **`manifest.rs`** in full. The `ephpm.yaml` schema is a fixed contract and
  switchboard-api does not parse it — **confirmed, not assumed**: nothing in the
  API reads a repository's contents, because it never checks one out. The
  manifest is read from the checkout, which only the daemon has.
- **`secrets.rs`** in full, including `${secret.NAME}` resolution. The API has no
  secret store and no knowledge of one. Note the interaction with the fork gate
  below.
- **`main.rs::get_installation_token`** and `base64_url_encode`. The daemon is
  the only side that authenticates to GitHub.
- **`config.rs`**: `app_key`, `app_id`, `sites_dir`, `preview_domain`,
  `composer`, `secrets_file`, `health_timeout_secs`, `health_interval_secs`.

---

## Stays, but changes: `github.rs`

The interface is now GitHub's **Deployments API**, not PR comments — GitHub
renders a native "View deployment" box in the PR, which is where the developer
already is.

`create_deployment_status` already exists and is the foundation. What it needs:

| Change | Why |
|---|---|
| Create the Deployment on **job claim**, before cloning | It is the only signal the PR gets that anything is happening. Currently it is created after a successful deploy. |
| Post `in_progress` when the build starts | — |
| Post `failure` on any failed step | Currently a failed deploy posts nothing at all — `handle_deploy` logs the error and returns. |
| Post `inactive` on teardown | Replaces `post_teardown_comment`. |
| Keep `environment_url` on `success` | Already present; it is what makes the box a link. |

`post_preview_comment` / `find_existing_comment` / `update_comment` and the
`**ePHPm Preview**` marker become **failure-only**: a `failure` deployment state
carries no log, so a build failure should additionally post a comment or a check
run with the output. The success-path comment is redundant once the deployment
box renders.

---

## New work in the daemon

### 1. Queue watcher

Replaces the webhook handler as the entry point. Watch
`<vhost>/.switchboard/queue/` — configure the path explicitly; do not derive it
from `sites_dir`, since switchboard-api's vhost is not a preview.

```
loop:
  entries = readdir(queue/) filtered to *.json, sorted ascending
    ^ filename is <13-digit millis>-<16 hex>.json, so lexicographic == chronological
  for each entry:
    claim: link(queue/<f>, queue/claimed/<f>); on EEXIST skip; then unlink(queue/<f>)
    parse; reject unknown "schema"
    dispatch on "intent": deploy | teardown
    on success: unlink(claimed/<f>)
    on failure: leave in claimed/ (or move aside) for inspection
```

**Claim with `link()`, not `rename()`.** `rename()` overwrites silently, so two
workers would both believe they won. `link()` fails with `EEXIST`.

Polling on a short interval is sufficient — inotify is optional. Note the
directory is only ever written by `rename()` into it, so a reader never sees a
partial file.

### 2. Job deserialization

Mirror the schema in `README.md`. **Reject an unrecognised `schema` value**
rather than deserializing optimistically.

Fields that did not exist before:

- `intent` — act on this instead of re-deriving from `action`.
- `preview.label` — use verbatim.
- `pull_request.head.pull_ref` (`refs/pull/<n>/head`) — the recommended fetch
  path. It resolves the head from the *base* repository, so it works for forks
  without trusting a third-party clone URL and works when the fork has been
  deleted (`head.repo_full_name` is then `null`).
- `pull_request.fork` — see below.

### 3. Coalescing

Rapid pushes produce several `synchronize` jobs for the same `preview.label`.
The daemon should take the newest per label and discard older ones. The API
deliberately does not do this: superseding a queued job races with the daemon's
claim.

### 4. Deployment lifecycle

Per the `github.rs` section above.

---

## Behaviour changes to be aware of

**Fork pull requests are refused by default.** The current Rust deploys any fork
PR with no gate, and `materialize_env` resolves `${secret.NAME}` from the
operator's store into the preview environment — so today, an outside
contributor's `build:`/`seed:` commands run with your secrets available. The API
now refuses fork *deploys* unless `SWITCHBOARD_ALLOW_FORKS=true`; fork
*teardowns* are always allowed. The daemon still gets `pull_request.fork` on
every job and can apply its own policy on top (for example, resolving no secrets
for a fork even when builds are enabled). Worth deciding explicitly rather than
inheriting.

**Payload values are validated before they reach the daemon.** `head.ref` (no
leading `-`, no `..`, conservative character set), `head.sha` (40/64 hex),
owner/repo names, and clone URLs (`https://` on the configured host) are all
checked at the API boundary. The daemon can rely on that, though defence in
depth is still cheap.

**Signature verification is gone from the daemon**, so the job queue is now the
trust boundary. Anything that can write into `queue/` can cause a deploy. Both
processes run as the same uid by design — the API writes the directory the
daemon reads — so filesystem permissions cannot separate them; the protection is
that only switchboard-api can write there, and it only does so for an
HMAC-verified delivery.

**GitHub is no longer reachable from the webhook path**, so a GitHub API outage
can no longer cause webhook timeouts and redeliveries. It now delays deployment
statuses instead.

---

## Deletion checklist

- [ ] `src/webhook.rs` — delete everything except a reduced `preview_host`; keep
      the `preview_label` tests as a cross-implementation guard, or delete them
      together with the function if you prefer the API to own that entirely.
- [ ] `src/main.rs` — delete `AppState`, `handle_webhook`, the router, `axum::serve`.
- [ ] `src/config.rs` — delete `listen`, `webhook_secret`; add `queue_dir`.
- [ ] `Cargo.toml` — drop `axum`; `hmac`/`sha2`/`hex` only if nothing else uses
      them (`webhook.rs::identity_hash` used `sha2`, so check before removing).
- [ ] Add the queue watcher, job deserialization, coalescing, and the deployment
      lifecycle.
- [ ] Update the daemon's README: it is no longer an HTTP service and no longer
      holds a webhook secret.
