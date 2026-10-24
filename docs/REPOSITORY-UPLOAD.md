# Private GitHub repository upload from an authenticated root shell

Requested owner/repository: **`screenartsl/aws-engagement-bridge`**, visibility **PRIVATE**. Creation and pushing this new module are authorized. Granting judge access is a separate action and has not been performed. No public SaaS source upload is authorized.

The development session could not create the remote: its execution user has no `gh` command or GitHub authentication configured. Root may have different credentials (for example, those used by Claude). Access to push an existing repository does not necessarily grant permission to create a new private repository.

## With GitHub CLI already authenticated as an authorized identity

First check `gh auth status`. If CLI is absent or not authenticated, use the existing root workflow or create the private repository in GitHub's UI; do not paste a token into chat or into Git remote URLs.

For a **new, empty** repository (do not initialize it with a README/license):

```sh
cd /var/www/vhosts/muserelay.com/modules-develop/aws-engagement-bridge
gh auth status
gh repo create screenartsl/aws-engagement-bridge --private
git -c safe.directory="$PWD" remote add origin https://github.com/screenartsl/aws-engagement-bridge.git
git -c safe.directory="$PWD" -c credential.helper='!gh auth git-credential' push origin codex/aws-engagement-bridge:main
git -c safe.directory="$PWD" -c credential.helper='!gh auth git-credential' push -u origin codex/aws-engagement-bridge-phase2
gh repo view screenartsl/aws-engagement-bridge --json nameWithOwner,visibility,defaultBranchRef
```

The per-command `safe.directory` trust is only for this known local repository when root runs Git against files owned by the application user; it does not disable ownership checks globally. The helper is also per command. Do not run `git init`, rebase, squash or force-push: the original commit objects are already present and must stay intact.

This publishes phase 1 as `main` and the tested-but-not-staging-accepted phase 2 as a development branch. Before final judging, review and merge the completed, honestly documented submission into `main`; the organizer evaluates that branch. Do not invite judges until the intended source/evaluation access is reviewed.

If the repository already exists, inspect its owner, visibility and contents instead of repeating creation. If `origin` already exists, inspect it rather than overwriting another remote. If using GitHub UI plus the existing Claude/root Git authentication, use the same remote-add and ordinary push commands without the `gh` helper override; ensure that authentication has access to the new repository.

## Verify preserved history

```sh
git -c safe.directory="$PWD" log --all --format='%h %cI %s'
git -c safe.directory="$PWD" ls-remote origin refs/heads/main refs/heads/codex/aws-engagement-bridge-phase2
```

The ancestry must still include original commits `2d02e31` and `f2b5d07`. Their full hashes and timestamps do not change on an ordinary push. No production Core history or full application copy belongs in this repository; the three-file reviewed integration patch is included instead.
