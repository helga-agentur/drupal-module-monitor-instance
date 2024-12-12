<?php

declare(strict_types=1);

namespace Drupal\instance\Logger;

use Drupal\Core\Logger\LogMessageParserInterface;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\instance\DataCollector;
use Drupal\instance\DataTransmitter;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Transmits logs to the Monitor Rest Rescource
 */
final class InstanceLogger implements LoggerInterface {

  use RfcLoggerTrait;

  /**
   * Constructs an InstanceLogger object.
   */
  public function __construct(
    private readonly DataCollector $dataCollector,
    private readonly DataTransmitter $dataTransmitter,
    private readonly LogMessageParserInterface $parser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
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
      $this->dataTransmitter->transmitLogData($logData);
    } catch (\Exception|GuzzleException $e) {
      \Drupal::messenger()->addError($e->getMessage());
      \Drupal::logger('monitor')->error($e->getMessage());
    }
  }
}
