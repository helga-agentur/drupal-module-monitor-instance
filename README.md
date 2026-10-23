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
No shell access is needed (`shell_exec` can stay disabled):
- Git information is read directly from the `.git` directory. The commit date is read from the commit object if it is stored loose, otherwise the date of the last checkout (reflog) is used. The source is appended to the date, e.g. `2025-08-19 (commit)` or `2026-10-23 (checkout)`.
- The node version is taken from the NVM default alias (`~/.nvm`) or from the node binary found in the `PATH` (following symlinks to its installation and reading `include/node/node_version.h` or `share/node/node-version-*`).
- Outdated npm packages of the default theme are determined from its `package.json` and `node_modules`; the latest versions are fetched from registry.npmjs.org (cached for a day).
