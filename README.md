# Monitor-Instance
The Helga monitor tooling consists of the following two parts.
- [Monitor](https://github.com/helga-agentur/drupal-module-monitor)
- [Instance](https://github.com/helga-agentur/drupal-module-monitor-instance)

When instance is enabled it sends data to the monitor as soon as the Drupal Cron hook is triggered.

## Activation
Add the following lines to your settings.php file to enable functionality.
<br/>
- **Project**: This also is the identifier for the monitor. It should be the same project wide.
- **Environment**: Make sure to use _Live_, _Integration_ or _Stage_
```php
$settings['instance'] = [
  'project' => 'Instance',
  'environment' => 'Live',
  'monitor' => 'https://www.helga.ch',
  'user' => 'monitor',
  'password' => 'monitor',
];
```

Make sure the "shell_exec" PHP function is enabled on your server. (In cyon go to "Erweitert" -> "PHP-Einstellungen" and enable "shell_exec")
