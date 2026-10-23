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
   * Reads the default version of NVM or the version of the node installation
   * found in the PATH (headers, version marker or the binary itself), as executing node is not possible without shell_exec.
   *
   * @return string|null
   */
  private function findNodeVersion(): ?string {
    foreach ($this->getNvmDirs() as $nvmDir) {
      $version = $this->getNvmDefaultVersion($nvmDir);
      if ($version !== null) return $version;
    }

    //the binary is often a symlink, e.g. /usr/local/bin/node -> /opt/alt/alt-nodejs24/root/usr/bin/node
    foreach ($this->getBinDirs() as $binDir) {
      $node = realpath($binDir . '/node');
      if (!$node) continue;

      $prefix = dirname($node, 2);
      $version = $this->readNodeVersionHeader($prefix . '/include/node/node_version.h')
        ?? $this->readNodeVersionMarker($prefix . '/share/node')
        ?? $this->scanNodeBinaries($node, $prefix);
      if ($version !== null) return $version;
    }

    return null;
  }

  /**
   * Fallback for installations without headers or marker: every node build
   * contains the url of its headers (process.release.headersUrl),
   * e.g. ".../release/v24.13.0/node-v24.13.0-headers.tar.gz".
   *
   * The binary is either self-contained or links a shared libnode. The result
   * is cached until the file changes, as these files are around 100 MB.
   */
  private function scanNodeBinaries(string $node, string $prefix): ?string {
    $files = array_merge(
      [$node],
      glob($prefix . '/lib/libnode*') ?: [],
      glob($prefix . '/lib64/libnode*') ?: [],
      glob('/usr/lib/*/libnode*') ?: [],
    );

    foreach (array_unique(array_filter(array_map('realpath', $files))) as $file) {
      $stat = @stat($file);
      if (!$stat) continue;

      $cacheId = 'instance:node_version:' . hash('sha256', $file . ':' . $stat['size'] . ':' . $stat['mtime']);
      $cache = \Drupal::cache()->get($cacheId);
      if ($cache) {
        if ($cache->data !== null) return $cache->data;
        continue;
      }

      $version = $this->scanFile($file, '/node-v(\d+\.\d+\.\d+)-headers\.tar\.gz/');
      $version = $version !== null ? 'v' . $version : null;
      \Drupal::cache()->set($cacheId, $version);
      if ($version !== null) return $version;
    }

    return null;
  }

  /**
   * Searches a (binary) file chunk by chunk and returns the first match group.
   */
  private function scanFile(string $file, string $pattern): ?string {
    $handle = @fopen($file, 'rb');
    if (!$handle) return null;

    $chunkSize = 1024 * 1024;
    $overlap = 256;
    $buffer = '';
    $result = null;
    while (!feof($handle)) {
      $chunk = fread($handle, $chunkSize);
      if ($chunk === false) break;
      $buffer .= $chunk;
      if (preg_match($pattern, $buffer, $matches)) {
        $result = $matches[1];
        break;
      }
      //keep the end of the buffer, the match could be split between two chunks
      $buffer = substr($buffer, -$overlap);
    }

    fclose($handle);
    return $result;
  }

  /**
   * Some packages (e.g. CloudLinux alt-nodejs on Cyon) ship no headers,
   * but a marker file like share/node/node-version-24.13.0.
   */
  private function readNodeVersionMarker(string $directory): ?string {
    $versions = [];
    foreach (glob($directory . '/node-version-*') ?: [] as $file) {
      if (preg_match('/node-version-(\d+\.\d+\.\d+)$/', $file, $matches)) {
        $versions[] = $matches[1];
      }
    }
    if (!$versions) return null;

    usort($versions, fn($a, $b) => version_compare($b, $a));
    return 'v' . $versions[0];
  }

  private function readNodeVersionHeader(string $path): ?string {
    $header = @is_readable($path) ? @file_get_contents($path) : false;
    if (!$header || !preg_match_all('/#define NODE_(MAJOR|MINOR|PATCH)_VERSION (\d+)/', $header, $matches)) return null;

    $parts = array_combine($matches[1], $matches[2]);
    if (count($parts) !== 3) return null;

    return 'v' . $parts['MAJOR'] . '.' . $parts['MINOR'] . '.' . $parts['PATCH'];
  }

  /**
   * Returns the directories a login shell would search for binaries.
   */
  private function getBinDirs(): array {
    $binDirs = explode(PATH_SEPARATOR, (string) getenv('PATH'));
    foreach ($this->getHomeDirs() as $home) {
      $binDirs[] = $home . '/bin';
      $binDirs[] = $home . '/.local/bin';
    }
    $binDirs[] = '/usr/local/bin';
    $binDirs[] = '/usr/bin';

    return array_unique(array_filter($binDirs));
  }

  private function getHomeDirs(): array {
    $homes = [getenv('HOME') ?: null];
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
      $homes[] = posix_getpwuid(posix_geteuid())['dir'] ?? null;
    }
    return array_unique(array_filter($homes));
  }

  private function getNvmDirs(): array {
    $nvmDirs = [getenv('NVM_DIR') ?: null];
    foreach ($this->getHomeDirs() as $home) {
      $nvmDirs[] = $home . '/.nvm';
    }

    return array_unique(array_filter($nvmDirs, fn($nvmDir) => $nvmDir && @is_dir($nvmDir . '/versions/node')));
  }

  /**
   * Resolves the NVM default alias to an installed version.
   * Falls back to the highest installed version. Returns null if the alias
   * is "system", the node in the PATH is used then.
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
    if ($alias === 'system') return null;

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
