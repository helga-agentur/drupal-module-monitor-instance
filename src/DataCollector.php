<?php

namespace Drupal\instance;

use Drupal\Core\Site\Settings;

class DataCollector {

  protected string $project;

  protected string $environment;

  protected ?GitRepository $gitRepository = null;

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

  private function getGitHead(): ?string {
    return $this->toShellOutput($this->getGitRepository()->getHead());
  }

  /**
   * Will return the link to the release notes.
   * If the head does not point to a version, it returns null.
   *
   * @return string|null
   */
  private function getGitHeadUrl(): ?string {
    //if head does not point to a version, return null
    if (!preg_match('/HEAD detached at (.+)$/', (string) $this->getGitHead())) return null;

    $tag = $this->toShellOutput($this->getGitRepository()->getHeadTag());
    return $this->getGitUrl() . '/releases/tag/' . $tag;
  }

  private function getGitCommitUrl(): string {
    return $this->getGitUrl() . '/commit/' . $this->getGitCommitId();
  }

  private function getGitCommitId(): ?string {
    return $this->toShellOutput($this->getGitRepository()->getCommitId());
  }

  /**
   * Returns the date of the commit id, followed by its source:
   * "(commit)" for the real commit date, "(checkout)" if only the date of the
   * last checkout is available.
   *
   * @TODO
   * later we should change the format to a CH specific one.
   *
   * @return string|null
   */
  private function getGitCommitDate(): ?string {
    $commitDate = $this->getGitRepository()->getCommitDate();
    if ($commitDate === null) return null;

    $source = $commitDate['isCommitDate'] ? 'commit' : 'checkout';
    return $this->toShellOutput($commitDate['date'] . ' (' . $source . ')');
  }

  private function getGitUrl(): ?string {
    $remote = $this->getGitRepository()->getRemoteUrl();

    //if no origin detected, return null
    if (!$remote) return null;
    if (!preg_match('/^(https?:\/\/|git@)(.+)(\/|:)(.+)\/(.+).git$/', $remote, $matches)) return null;

    $host = $matches[2];
    $organization = $matches[4];
    $repository = $matches[5];
    return "https://$host/$organization/$repository";
  }

  /**
   * The monitor expects the values in the same format as the former
   * shell_exec output, which ended with a line break.
   */
  private function toShellOutput(?string $value): ?string {
    return $value === null ? null : $value . "\n";
  }

  private function getGitRepository(): GitRepository {
    return $this->gitRepository ??= new GitRepository(DRUPAL_ROOT);
  }

  private function getDrupalCoreVersion(): string {
    return \Drupal::VERSION;
  }

  private function getPHPVersion(): string {
    return phpversion();
  }

  private function getNodeVersion(): ?string {
    return $this->toShellOutput($this->findNodeVersion());
  }

  /**
   * Returns the node version installed on the server, e.g. "v20.11.0".
   *
   * Reads the default version of NVM or the headers of a system wide
   * installation, as executing node is not possible without shell_exec.
   *
   * @return string|null
   */
  private function findNodeVersion(): ?string {
    foreach ($this->getNvmDirs() as $nvmDir) {
      $version = $this->getNvmDefaultVersion($nvmDir);
      if ($version !== null) return $version;
    }

    foreach (['/usr/include/node', '/usr/local/include/node'] as $includeDir) {
      $header = @is_readable($includeDir . '/node_version.h') ? @file_get_contents($includeDir . '/node_version.h') : false;
      if ($header && preg_match_all('/#define NODE_(MAJOR|MINOR|PATCH)_VERSION (\d+)/', $header, $matches)) {
        $parts = array_combine($matches[1], $matches[2]);
        if (count($parts) === 3) {
          return 'v' . $parts['MAJOR'] . '.' . $parts['MINOR'] . '.' . $parts['PATCH'];
        }
      }
    }

    return null;
  }

  private function getNvmDirs(): array {
    $homes = [getenv('HOME') ?: null];
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
      $homes[] = posix_getpwuid(posix_geteuid())['dir'] ?? null;
    }

    $nvmDirs = [getenv('NVM_DIR') ?: null];
    foreach (array_filter($homes) as $home) {
      $nvmDirs[] = $home . '/.nvm';
    }

    return array_unique(array_filter($nvmDirs, fn($nvmDir) => $nvmDir && @is_dir($nvmDir . '/versions/node')));
  }

  /**
   * Resolves the NVM default alias to an installed version.
   * Falls back to the highest installed version.
   */
  private function getNvmDefaultVersion(string $nvmDir): ?string {
    $installed = array_map('basename', glob($nvmDir . '/versions/node/v*', GLOB_ONLYDIR) ?: []);
    if (!$installed) return null;
    usort($installed, fn($a, $b) => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));

    //aliases can point to other aliases, e.g. default -> lts/* -> lts/iron -> v20.11.0
    $alias = 'default';
    for ($i = 0; $i < 5; $i++) {
      $aliasFile = $nvmDir . '/alias/' . $alias;
      if (!@is_file($aliasFile)) break;
      $alias = trim((string) @file_get_contents($aliasFile));
    }

    $prefix = ltrim($alias, 'v');
    if (preg_match('/^\d+(\.\d+){0,2}$/', $prefix)) {
      foreach ($installed as $version) {
        $versionNumber = ltrim($version, 'v');
        if ($versionNumber === $prefix || str_starts_with($versionNumber, $prefix . '.')) {
          return $version;
        }
      }
    }

    return $installed[0];
  }

  /**
   * Counts the outdated npm packages of the default theme, like `npm outdated`.
   * The latest versions are fetched from the npm registry and cached for a day.
   *
   * @return array
   */
  private function getOutdatedNPMPackages(): array {
    $major = 0;
    $minor = 0;
    $patch = 0;

    /** @var \Drupal\Core\Extension\ThemeHandlerInterface $themeHandler */
    $themeHandler = \Drupal::service('theme_handler');
    $themePath = $themeHandler->getTheme($themeHandler->getDefault())?->getPath();
    $packageJson = $themePath ? $this->readJson(DRUPAL_ROOT . '/' . $themePath . '/package.json') : null;
    if ($packageJson === null) return compact('major', 'minor', 'patch');

    $packages = array_keys(($packageJson['dependencies'] ?? []) + ($packageJson['devDependencies'] ?? []));
    foreach ($packages as $package) {
      $installed = $this->readJson(DRUPAL_ROOT . '/' . $themePath . '/node_modules/' . $package . '/package.json');
      $latest = $this->getLatestNPMVersion($package);
      if (!isset($installed['version']) || $latest === null) continue;

      $current = array_map('intval', explode('.', preg_replace('/[-+].*$/', '', $installed['version'])) + [0, 0, 0]);
      $latest = array_map('intval', explode('.', preg_replace('/[-+].*$/', '', $latest)) + [0, 0, 0]);

      if ($current[0] < $latest[0]) {
        $major++;
      } else if ($current[0] === $latest[0] && $current[1] < $latest[1]) {
        $minor++;
      } else if ($current[0] === $latest[0] && $current[1] === $latest[1] && $current[2] < $latest[2]) {
        $patch++;
      }
    }

    return compact('major', 'minor', 'patch');
  }

  private function getLatestNPMVersion(string $package): ?string {
    $cacheId = 'instance:npm_latest:' . $package;
    $cache = \Drupal::cache()->get($cacheId);
    if ($cache) return $cache->data;

    try {
      $response = \Drupal::httpClient()->get('https://registry.npmjs.org/' . str_replace('/', '%2F', $package) . '/latest', [
        'timeout' => 5,
        'headers' => ['Accept' => 'application/json'],
      ]);
      $version = json_decode((string) $response->getBody(), true)['version'] ?? null;
    } catch (\Throwable $e) {
      return null;
    }

    if (!is_string($version)) return null;
    \Drupal::cache()->set($cacheId, $version, time() + 86400);
    return $version;
  }

  private function readJson(string $path): ?array {
    if (!@is_file($path) || !@is_readable($path)) return null;
    $data = json_decode((string) @file_get_contents($path), true);
    return is_array($data) ? $data : null;
  }
}
