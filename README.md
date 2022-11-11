# Monitor-Instance
When enabled the instance sends data to the monitor. It uses the Drupal Cron hook to trigger the sending.

## Activation
As soon as you put the following lines in your settings.php file, monitoring functionality is enabled. The instance sends information to your monitor, each time the cron job is run.

```php
$settings['instance'] = [
  'monitor' => 'https://monitor.joinbox.com',
  'user' => 'monitor',
  'password' => 'monitor',
];
```
