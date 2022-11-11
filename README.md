# Monitor-Instance
The Joinbox Monitoring tool consists of the following two parts.
- [Monitor](https://github.com/joinbox/d9-module-monitor)
- [Instance](https://github.com/joinbox/d9-module-monitor-instance)

When instance is enabled it sends data to the monitor as soon as the Drupal Cron hook is triggered.

## Activation
Add the following lines to your settings.php file to enable functionality.

```php
$settings['instance'] = [
  'monitor' => 'https://monitor.joinbox.com',
  'user' => 'monitor',
  'password' => 'monitor',
];
```
