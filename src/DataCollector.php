<?php

namespace Drupal\instance;

use Drupal\Core\Site\Settings;

class DataCollector {

  protected string $project;

  protected string $environment;

  public function __construct() {
    //get settings
    $settings = Settings::get('instance');
    $this->project = $settings['project'];
    $this->environment = $settings['environment'];

    //check for missing settings
    if (!$this->project || $this->environment) {
      throw new \Exception('Add an identifier and environment in your settings.php. Check README.md');
    }
  }

  public function getData(): array {
    return [
      'project' => $this->project,
      'environment' => $this->environment,
      'host' => \Drupal::request()->getHost(),
      'data' => [
        'drupal' => [
          'version' => $this->getDrupalCoreVersion(),
        ],
        'php' => [
          'version' => $this->getPHPVersion(),
        ],
        'git' => [
          'head' => $this->getGitHead(),
          'headUrl' => $this->getGitHeadUrl(),
          'commit' => $this->getGitCommitId(),
          'commitUrl' => $this->getGitCommitUrl(),
        ],
        'node' => [
          'version' => $this->getNodeVersion(),
        ],
        'timestamp' => time()
      ]
    ];
  }

  private function getGitHead(): string {
    return shell_exec('git status | head -n 1');
  }

  /**
   * Will return the link to the release notes.
   * If the head does not point to a version, it returns null.
   *
   * @return string|null
   */
  private function getGitHeadUrl(): string|null {
    //if head does not point to a version, return null
    if(!preg_match('/^HEAD detached at (.+)$/', $this->getGitHead())) return null;

    $tag = shell_exec('git describe --tags --abbrev=0');
    return $this->getGitUrl() . '/releases/tag/' . $tag;
  }

  private function getGitCommitUrl(): string {
    return $this->getGitUrl() . '/commit/' . $this->getGitCommitId();
  }

  private function getGitCommitId(): string {
    return shell_exec('git rev-parse --short HEAD');
  }

  private function getGitUrl(): string|null {
    $remote = shell_exec('git config --get remote.origin.url');

    //if no origin detected, return null
    if (!preg_match('/^(https?:\/\/|git@)(.+)(\/|:)(.+)\/(.+).git$/', $remote, $matches)) return null;

    $host = $matches[2];
    $organization = $matches[4];
    $repository = $matches[5];
    return "$host/$organization/$repository";
  }

  private function getDrupalCoreVersion(): string {
    return \Drupal::VERSION;
  }

  private function getPHPVersion(): string {
    return phpversion();
  }

  private function getNodeVersion(): string {
    return shell_exec('node -v');
  }
}