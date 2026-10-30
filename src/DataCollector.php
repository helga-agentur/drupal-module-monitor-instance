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
                'commitDate' => $this->getGitCommitDate(),
                'generatedAt' => $this->getGitGeneratedAt()
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

  private function getGitHead(): ?string {
    return $this->getGitInfo('head');
  }

  /**
   * Reads a value of the file written by the git hook (scripts/git-info.sh).
   * Returns null if the file or the value is missing.
   * The trailing line break matches the former shell_exec output.
   */
  private function getGitInfo(string $key): ?string {
    static $info = null;

    if ($info === null) {
      $dir = DRUPAL_ROOT;
      while (!is_dir($dir . '/.git') && $dir !== dirname($dir)) $dir = dirname($dir);
      $json = @file_get_contents($dir . '/.git/instance-git-info.json');
      $info = (is_string($json) ? json_decode($json, true) : null) ?: [];
    }

    return ($info[$key] ?? '') !== '' ? $info[$key] . "\n" : null;
  }

  /**
   * Will return the link to the release notes.
   * If the head does not point to a version, it returns null.
   *
   * @return string|null
   */
  private function getGitHeadUrl(): string|null {
    //if head does not point to a version, return null
    if(!preg_match('/HEAD detached at (.+)$/', (string) $this->getGitHead())) return null;

    $tag = $this->getGitInfo('tag');
    return $this->getGitUrl() . '/releases/tag/' . $tag;
  }

  private function getGitCommitUrl(): string {
    return $this->getGitUrl() . '/commit/' . $this->getGitCommitId();
  }

  private function getGitCommitId(): ?string {
    return $this->getGitInfo('commit');
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
  private function getGitCommitDate(): ?string {
    return $this->getGitInfo('commitDate');
  }

  /**
   * Returns when the hook last wrote the git file (ISO 8601, UTC).
   */
  private function getGitGeneratedAt(): ?string {
    $value = $this->getGitInfo('generatedAt');
    return $value === null ? null : trim($value);
  }

  private function getGitUrl(): string|null {
    $remote = $this->getGitInfo('remoteUrl');

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
    return shell_exec('bash -i -c \'node -v\'');
  }

  private function getOutdatedNPMPackages(): array {
    /** @var ThemeHandlerInterface $themeHandler */
    $themeHandler = \Drupal::service('theme_handler');
    $themePath = $themeHandler->getTheme($themeHandler->getDefault())?->getPath();
    $json = shell_exec('cd $themePath; bash -i -c \'npm outdated --json\'');
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
