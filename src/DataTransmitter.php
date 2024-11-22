<?php

namespace Drupal\instance;

use Drupal\Core\Site\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class DataTransmitter {

  protected Client $client;

  protected string $monitor;

  protected string $user;

  protected string $password;

  private string $instanceEndpoint = '/monitor/instance';

  private string $logEndpoint = '/monitor/log';

  /**
   * Constructs a new DataTransmitter
   *
   * @throws \Exception
   */
  public function __construct() {
    //get settings
    $settings = Settings::get('instance');

    //check for missing settings
    if (!isset($settings['monitor']) || !isset($settings['user']) || !isset($settings['password'])) {
      throw new \Exception('Monitor Instance: Add a monitor, username and password in your settings.php. Check README.md');
    }

    $this->monitor = $settings['monitor'];
    $this->user = $settings['user'];
    $this->password = $settings['password'];

    //create client
    $this->client = \Drupal::httpClient();
  }

  /**
   * @param array $data
   *
   * @return bool
   * @throws GuzzleException
   */
  public function transmitInstanceData(array $data): bool {
    return $this->transmit($data, $this->instanceEndpoint);
  }

  /**
   * @param array $data
   *
   * @return bool
   * @throws GuzzleException
   */
  public function transmitLogData(array $data): bool {
    return $this->transmit($data, $this->logEndpoint);
  }

  /**
   * Sends the data to monitor
   *
   * @param array $data
   * @return bool
   * @throws GuzzleException
   */
  public function transmit(array $data, string $endpoint): bool {
    $response = $this->client->request('POST', $this->monitor . $endpoint, [
      'headers' => [
        'Content-Type' => 'application/json',
      ],
      'auth' => [$this->user, $this->password],
      'body' => json_encode($data)
    ]);

    return $response->getStatusCode() == 201;
  }
}
