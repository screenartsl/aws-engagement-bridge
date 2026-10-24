# Private repository preparation — publication on hold

Destination: **`screenartsl/aws-engagement-bridge`**, **PRIVATE**. The latest phase 3 instruction explicitly says **not to publish without specific authorization**. This supersedes the earlier permission to upload. No create/push/merge/invitation/publication operation was performed during phase 3.

The local repository has an `origin` pointing to `https://github.com/screenartsl/aws-engagement-bridge.git`. This alone does not prove that the remote exists, is private or has received commits. Those properties have not been authenticated in this development session. Do not request or paste tokens into chat or embed them in remote URLs. Root's credentials, if available, are separate from this session.

## Read-only review

```sh
cd /var/www/vhosts/muserelay.com/modules-develop/aws-engagement-bridge
git status --short --branch
git remote -v
git log --all --format='%h %cI %s'
python3 scripts/review-history.py --self-test
python3 scripts/review-history.py
```

The scanner examines every locally stored Git object, including unreachable content and commit/tag/tree metadata, plus tracked/non-ignored working files and remote-URL credential exposure. It redacts findings. This is heuristic evidence, not a formal no-secret guarantee; ignored runtime files, external LFS content and remote-only history are not certified. The runtime directory contains only synthetic data but must still remain outside the upload.

A human review should check `git ls-files`, the three-file `core-hooks.patch`, dependencies/lockfiles, documentation and intended organization ownership. The complete proprietary MuseRelay Core is not part of this repository. Keep the original commits intact; do not reinitialize, squash, rewrite dates, rebase or force-push to claim older code as new hackathon work.

In an authenticated operator shell, `gh auth status` and `gh repo view screenartsl/aws-engagement-bridge --json nameWithOwner,visibility,defaultBranchRef` can verify identity/visibility read-only. Do not assume that permission to push MuseRelay's other repository grants creation or access here.

## After a future specific publication authorization

Verify the exact owner and PRIVATE visibility, inspect any existing remote refs/content and repeat the history/working-tree review after final commits. If absent, create an empty private repository through the authorized GitHub identity. If present, reconcile its contents without overwriting history. Push the reviewed local development branch through the authenticated credential helper; preserve all original commits and do not upload the external Core checkout, runtime, secrets or `.env` files. Judge access and making the repository public remain separate actions.

A local `git bundle` can preserve all local refs/history without publishing to any service. Phase 3's report records its path and verification when created. Keep that artifact private and repeat the secret scan before any later upload. A prepared local branch/bundle is not a GitHub backup until an authorized push is actually verified.
