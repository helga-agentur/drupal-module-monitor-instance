# Monitor-Instance
The Helga monitor tooling consists of the following two parts.
- [Monitor](https://github.com/helga-agentur/drupal-module-monitor)
- [Instance](https://github.com/helga-agentur/drupal-module-monitor-instance)

When instance is enabled it sends:
- Instance Data like Drupal Version, Git Repository, latest commit, branch etc. to the monitor as soon as the Drupal Cron hook is triggered.
- Log Data with severity ERROR, CRITICAL, ALERT or EMERGENCY to the monitor on Log-Event

## Activation
Add the following lines to your settings.php file to enable functionality.
<br/>
- **Project**: This also is the identifier for the monitor. It should be the same project wide.
- **Environment**: Make sure to use _Live_, _Integration_, _Stage_ or _Local_
```php
$settings['instance'] = [
  'project' => 'Instance',
  'environment' => 'Live',
  'monitor' => 'https://www.helga.ch',
  'user' => 'monitor',
  'password' => 'monitor',
];
```

### Local Environment
The config in settings.php is also required locally, although no logs are sent from there. (`'environment'` needs to be 'Local')

## Deployment

> **Assumed deploy workflow**
> 1. `git fetch && git checkout TAG/BRANCH`
> 2. `composer install && drush deploy`
>
> Everything below (hooks, coverage, risks) is based on this workflow.

Git data is not read at runtime. Git hooks write it to `.git/instance-git-info.json`; cron only reads that small file. This is cheaper than spawning several `git` processes on every cron run, and works without `shell_exec` for git.

Install the hooks **once per environment**, from the project root:
```sh
sh web/modules/custom/instance/scripts/install-git-hook.sh
```
Existing foreign hooks are never overwritten. The script also generates the file once.

Node version and outdated npm packages still need the "shell_exec" PHP function. (In cyon go to "Erweitert" -> "PHP-Einstellungen" and enable "shell_exec")

### Covered by the hooks
| Command | Hook |
|---|---|
| `git checkout <branch/tag/sha>`, `git switch`, `git switch --detach` | `post-checkout` |
| `git clone` | `post-checkout` (not installed yet, the installer's first run covers it) |
| `git pull` / `git merge` (incl. fast-forward) | `post-merge` |
| `git pull --rebase`, `git rebase`, `git commit --amend` | `post-rewrite` |
| `git commit`, `git cherry-pick`, `git revert` | `post-commit` |
| `git worktree add` | `post-checkout` (hooks are shared) |

### Risks
- `git reset --hard` / `--keep` fires no hook: the file is stale. Run `sh web/modules/custom/instance/scripts/git-info.sh` after it.
- Deploys without `.git` on the server (rsync, tarball, CI artifact, image build) or into a fresh clone per release (Deployer, Capistrano): no hook runs, git values are `null`. Generate the file in the build or run the installer after cloning.
- The hooks are not versioned and are not installed automatically: a new environment sends no git data until the installer ran.
- A foreign hook of the same name is skipped by the installer; call `git-info.sh` from it.
- Replacing the module files does not update installed hooks, rerun the installer after changing `scripts/git-info.sh`.
