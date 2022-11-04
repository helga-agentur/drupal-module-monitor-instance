<?php

namespace Drupal\instance;

use Drupal\Core\Site\Settings;
use GuzzleHttp\Client;

class DataTransmitter {

  protected Client $client;

  protected string $monitor;

  protected string $user;

  protected string $password;

  /**
   * Constructs a new DataTransmitter
   *
   * @throws \Exception
   */
  public function __construct() {
    //get settings
    $settings = Settings::get('instance');
    $this->monitor = $settings['monitor'];
    $this->user = $settings['user'];
    $this->password = $settings['password'];

    //check for missing settings
    if (!$this->monitor) {
      throw new \Exception('Monitor is disabled. Enable it via settings.php. Check README.md');
    }

    //create client
    $this->client = new Client([
      'base_uri' => $this->monitor,
      'timeout'  => 300000,
    ]);
  }

  /**
   * Sends the data to monitor
   *
   * @param array $data
   * @return bool
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function transmit(array $data): bool {
    $response = $this->client->request('POST', '/monitor/instance', [
      'headers' => [
        'Content-Type' => 'application/json',
      ],
      'auth' => [$this->user, $this->password],
      'body' => json_encode($data)
    ]);

    return $response->getStatusCode() == 201;
  }
}
