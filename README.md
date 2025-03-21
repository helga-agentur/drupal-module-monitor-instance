# Monitor-Instance
The Helga monitor tooling consists of the following two parts.
- [Monitor](https://github.com/helga-agentur/drupal-module-monitor)
- [Instance](https://github.com/helga-agentur/drupal-module-monitor-instance)

When instance is enabled it sends:
- Instance Data like Drupal Version, Git Repository, latest commit, branch etc. to the monitor as soon as the Drupal Cron hook is triggered.
- Log Data with severity ERROR, CRITICAL, ALERT or EMERGENCY to the monitor

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
Make sure the "shell_exec" PHP function is enabled on your server. (In cyon go to "Erweitert" -> "PHP-Einstellungen" and enable "shell_exec")
