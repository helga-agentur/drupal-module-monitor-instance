<?php

declare(strict_types=1);

namespace Drupal\instance\Logger;

use Drupal\Core\Logger\LogMessageParserInterface;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\instance\DataCollector;
use Drupal\instance\DataTransmitter;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Transmits logs to the Monitor Rest Rescource
 */
final class InstanceLogger implements LoggerInterface {

  use RfcLoggerTrait;

  private DataTransmitter $dataTransmitter;

  /**
   * Constructs an InstanceLogger object.
   */
  public function __construct(
      private readonly DataCollector $dataCollector,
      private readonly LogMessageParserInterface $parser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    //prevent instance from sending too much traffic, so we restrict it to error (3) or higher
    if($level > RfcLogLevel::ERROR) return;

    // Convert PSR3-style messages to \Drupal\Component\Render\FormattableMarkup
    // style, so they can be translated too.
    $message = (string) $message;
    $placeholders = $this->parser->parseMessagePlaceholders($message, $context);
    // @see \Drupal\Core\Logger\LoggerChannel::log() for all available contexts.
    $renderedMessage = strtr($message, $placeholders);

    $logData = [
        'project' => $this->dataCollector->getProject(),
        'environment' => $this->dataCollector->getEnvironment(),
        'data' => [
            'type' => 'log',
            'level' => $level,
            'channel' => $context['channel'] ?? null,
            'message' => $renderedMessage,
            'timestamp' => time(),
        ],
    ];

    // Transmit the log data.
    try {
      $dataTransmitter = $this->getDataTransmitter();
      $dataTransmitter->transmitLogData($logData);
    } catch (\Exception|GuzzleException $e) {
      /**
       * We do not do anything here at the moment, to prevent endless loops.
       * They could occur, if the monitor rest api is unavailable, it logs, tries to resend the logs, and so on.
       *
       * In the near future we could gather the logs, trying to send them in intervals and stop after a few attempts.
       */
    }
  }

  /**
   * Use a singleton
   * @return DataTransmitter
   */
  private function getDataTransmitter() {
    if (!isset($this->dataTransmitter)) {
      $this->dataTransmitter = new DataTransmitter();
    }
    return $this->dataTransmitter;
  }
}
