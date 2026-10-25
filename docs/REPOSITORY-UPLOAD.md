# Private repository — publication authorized

Destination: **[`screenartsl/aws-engagement-bridge`](https://github.com/screenartsl/aws-engagement-bridge)**, **PRIVATE**. On **2026-10-25 (Europe/Paris)** the user explicitly authorized creating this private repository and uploading the module with its original history. This supersedes the publication hold from phases 3/4. No public visibility, judge invitations, Core installation or infrastructure activation is authorized by that instruction.

GitHub authenticated the server's existing Git credential as `screenartsl`. The repository was created through the official REST API with `private:true`, `auto_init:false`, then verified as owner `screenartsl`, visibility `private`, default branch `main`. The remote was empty before upload. The credentials were supplied by Git's existing protected credential helper; no key was printed, copied to another file or embedded in a URL. GitHub CLI installation was not necessary.

The initial upload used `codex/aws-engagement-bridge-phase4`. The latest development branch is now `codex/aws-engagement-bridge-core-integration`, preserving that ancestry and the original phase branches. Upload reviewed updates to `main` and this named branch without force-push, squash or rewritten dates. Verify remote refs against local commit IDs after the push. The first phases' original dates/ancestry remain part of that history. The installed PHP release remains pinned to `01e14f26a5db24cdaf1d9b6b5d6e5e1d009380e2`; later documentation commits do not change its installed file hashes.

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

## Publication and later updates

Before each upload verify the exact owner and PRIVATE visibility, inspect remote refs/content and repeat the history/working-tree review after final commits. Reconcile any existing remote content without overwriting history. Push reviewed module branches through the authenticated credential helper; preserve all original commits and do not upload the external Core checkout, runtime, secrets or `.env` files. Judge access and making the repository public remain separate actions. Do not run the old phase 1-to-main command as a way to publish the complete phase 4 implementation.

A local `git bundle` can preserve all local refs/history without publishing to any service. Phase 3's report records its path and verification when created. Keep that artifact private and repeat the secret scan before any later upload. A prepared local branch/bundle is not a GitHub backup until an authorized push is actually verified.
