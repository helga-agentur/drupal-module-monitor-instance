<?php

namespace Drupal\instance;

use Drupal\Core\Site\Settings;

class DataCollector {

  protected string $project;

  protected string $environment;

  public function __construct() {
    //get settings
    $settings = Settings::get('instance');

    //check for missing settings
    if (!isset($settings['project']) || !isset($settings['environment'])) {
      throw new \Exception('Monitor Instance: Add an identifier and environment in your settings.php. Check README.md');
    }

    $this->setProject($settings['project']);
    $this->setEnvironment($settings['environment']);
  }

  public function getData(): array {
    return [
        'project' => $this->getProject(),
        'environment' => $this->getEnvironment(),
        'data' => [
            'host' => \Drupal::request()->getSchemeAndHttpHost(),
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
                'commitDate' => $this->getGitCommitDate()
            ],
            'node' => [
                'version' => $this->getNodeVersion(),
            ],
            'npm' => $this->getOutdatedNPMPackages(),
            'timestamp' => time(),
        ]
    ];
  }

  private function setProject(string $project): void {
    $this->project = $project;
  }

  private function setEnvironment(string $environment): void {
    $this->environment = $environment;
  }

  public function getProject(): string {
    return $this->project;
  }

  public function getEnvironment(): string {
    return $this->environment;
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
    if(!preg_match('/HEAD detached at (.+)$/', $this->getGitHead())) return null;

    $tag = shell_exec('git describe --tags --abbrev=0');
    return $this->getGitUrl() . '/releases/tag/' . $tag;
  }

  private function getGitCommitUrl(): string {
    return $this->getGitUrl() . '/commit/' . $this->getGitCommitId();
  }

  private function getGitCommitId(): string|null|false {
    return shell_exec('git rev-parse --short HEAD');
  }

  /**
   * Returns the date of the commit id
   *
   * @TODO
   * later we should change the format to a CH specific one,
   * somehow the git command on the server does not run properly when passing a custom date format.
   *
   * @return string
   */
  private function getGitCommitDate(): false|null|string {
    return shell_exec('git show -s --format=%cd --date=short ' . $this->getGitCommitId());
  }

  private function getGitUrl(): string|null {
    $remote = shell_exec('git config --get remote.origin.url');

    //if no origin detected, return null
    if (!$remote) return null;
    if (!preg_match('/^(https?:\/\/|git@)(.+)(\/|:)(.+)\/(.+).git$/', $remote, $matches)) return null;

    $host = $matches[2];
    $organization = $matches[4];
    $repository = $matches[5];
    return "https://$host/$organization/$repository";
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

  private function getOutdatedNPMPackages(): array {
    $json = shell_exec('cd themes/custom/customer; npm outdated --json');
    $outdatedNPMPackages = json_decode($json, true);

    $major = 0;
    $minor = 0;
    $patch = 0;

    foreach ($outdatedNPMPackages as $package) {
      $current = explode('.', $package['current']);
      $latest = explode('.', $package['latest']);

      if ($current[0] < $latest[0]) {
        $major++;
      } else if ($current[1] < $latest[1]) {
        $minor++;
      } else if ($current[2] < $latest[2]) {
        $patch++;
      }
    }

    return compact('major', 'minor', 'patch');
  }
}
