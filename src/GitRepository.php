<?php

namespace Drupal\instance;

/**
 * Reads git information directly from the .git directory.
 *
 * This avoids executing the git binary (shell_exec), which is often disabled
 * on shared hosting. Every getter is best effort and returns null if the
 * information can not be determined.
 */
class GitRepository {

  protected ?string $gitDir;

  protected ?string $commonDir;

  public function __construct(string $startPath) {
    $this->gitDir = $this->findGitDir($startPath);
    $this->commonDir = $this->gitDir ? $this->findCommonDir($this->gitDir) : null;
  }

  /**
   * Returns a line similar to the first line of `git status`,
   * e.g. "On branch main" or "HEAD detached at 1.2.3".
   */
  public function getHead(): ?string {
    $head = $this->readFile($this->gitDir, 'HEAD');
    if ($head === null) return null;

    if (preg_match('/^ref: refs\/heads\/(.+)$/', $head, $matches)) {
      return 'On branch ' . $matches[1];
    }

    $commitId = $this->getCommitId(true);
    if ($commitId === null) return null;

    //like git status, use the reflog to find out what was checked out
    $target = $this->getLastCheckoutTarget();
    if ($target !== null) {
      $targetCommitId = $this->resolve($target);
      if ($targetCommitId === $commitId || str_starts_with($commitId, $target)) {
        return 'HEAD detached at ' . $target;
      }
    }

    return 'HEAD detached at ' . substr($commitId, 0, 7);
  }

  /**
   * Returns the tag HEAD points to, or null if HEAD is not tagged.
   */
  public function getHeadTag(): ?string {
    $commitId = $this->getCommitId(true);
    if ($commitId === null) return null;

    //prefer the name used for the checkout
    $target = $this->getLastCheckoutTarget();
    if ($target !== null && $this->resolveRef('refs/tags/' . $target) === $commitId) {
      return $target;
    }

    foreach ($this->getRefs('refs/tags/') as $ref => $refCommitId) {
      if ($refCommitId === $commitId) {
        return substr($ref, strlen('refs/tags/'));
      }
    }

    return null;
  }

  public function getCommitId(bool $long = false): ?string {
    $commitId = $this->resolve('HEAD');
    if ($commitId === null) return null;
    return $long ? $commitId : substr($commitId, 0, 7);
  }

  /**
   * Returns the commit date (Y-m-d) and whether it is the real commit date.
   *
   * Reads the commit object if it is stored as loose object. Packed objects
   * are not supported, in that case the date HEAD was last changed (from the
   * reflog, i.e. the checkout) is returned instead.
   *
   * @return array{date: string, isCommitDate: bool}|null
   */
  public function getCommitDate(): ?array {
    $commitId = $this->getCommitId(true);
    if ($commitId === null) return null;

    $object = $this->readFile($this->commonDir, 'objects/' . substr($commitId, 0, 2) . '/' . substr($commitId, 2), false);
    if ($object !== null) {
      $object = @gzuncompress($object);
      if ($object !== false && preg_match('/^committer .* (\d+) ([+-]\d{4})$/m', $object, $matches)) {
        return ['date' => $this->formatDate((int) $matches[1], $matches[2]), 'isCommitDate' => true];
      }
    }

    $lines = $this->readLines($this->gitDir, 'logs/HEAD');
    $lastLine = end($lines);
    if ($lastLine && preg_match('/^\S+ (\S+) .*> (\d+) ([+-]\d{4})\t/', $lastLine, $matches) && $matches[1] === $commitId) {
      return ['date' => $this->formatDate((int) $matches[2], $matches[3]), 'isCommitDate' => false];
    }

    return null;
  }

  public function getRemoteUrl(string $remote = 'origin'): ?string {
    $section = null;
    foreach ($this->readLines($this->commonDir, 'config') as $line) {
      $line = trim($line);
      if (preg_match('/^\[(.+)\]$/', $line, $matches)) {
        $section = $matches[1];
      }
      elseif ($section === 'remote "' . $remote . '"' && preg_match('/^url\s*=\s*(.+)$/', $line, $matches)) {
        return trim($matches[1], " \t\"");
      }
    }
    return null;
  }

  /**
   * Resolves HEAD, a full ref, a branch/tag name or a commit id prefix
   * to a full commit id.
   */
  protected function resolve(string $name): ?string {
    if ($name === 'HEAD') {
      $head = $this->readFile($this->gitDir, 'HEAD');
      if ($head === null) return null;
      if (preg_match('/^ref: (.+)$/', $head, $matches)) {
        return $this->resolveRef($matches[1]);
      }
      return preg_match('/^[0-9a-f]{40}$/', $head) ? $head : null;
    }

    foreach ([$name, 'refs/tags/' . $name, 'refs/heads/' . $name] as $ref) {
      $commitId = $this->resolveRef($ref);
      if ($commitId !== null) return $commitId;
    }

    return null;
  }

  protected function resolveRef(string $ref): ?string {
    $commitId = $this->readFile($this->gitDir, $ref) ?? $this->readFile($this->commonDir, $ref);
    if ($commitId !== null && preg_match('/^ref: (.+)$/', $commitId, $matches)) {
      return $this->resolveRef($matches[1]);
    }
    if ($commitId !== null && preg_match('/^[0-9a-f]{40}$/', $commitId)) {
      return $this->peel($ref, $commitId);
    }

    return $this->getPackedRefs()[$ref] ?? null;
  }

  /**
   * Returns all refs with the given prefix, mapped to their commit ids.
   */
  protected function getRefs(string $prefix): array {
    $refs = array_filter(
      $this->getPackedRefs(),
      fn($ref) => str_starts_with($ref, $prefix),
      ARRAY_FILTER_USE_KEY
    );

    $directory = $this->commonDir . '/' . $prefix;
    if (is_dir($directory)) {
      $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
      foreach ($iterator as $file) {
        $ref = $prefix . substr($file->getPathname(), strlen($directory));
        $commitId = $this->resolveRef($ref);
        if ($commitId !== null) {
          $refs[$ref] = $commitId;
        }
      }
    }

    return $refs;
  }

  /**
   * Returns the packed refs. Annotated tags are peeled to their commit id.
   */
  protected function getPackedRefs(): array {
    $refs = [];
    $lastRef = null;
    foreach ($this->readLines($this->commonDir, 'packed-refs') as $line) {
      if (preg_match('/^([0-9a-f]{40}) (.+)$/', $line, $matches)) {
        $refs[$matches[2]] = $matches[1];
        $lastRef = $matches[2];
      }
      elseif ($lastRef !== null && preg_match('/^\^([0-9a-f]{40})$/', $line, $matches)) {
        $refs[$lastRef] = $matches[1];
      }
    }
    return $refs;
  }

  /**
   * Peels a loose annotated tag to the commit id it points to.
   */
  protected function peel(string $ref, string $objectId): string {
    if (!str_starts_with($ref, 'refs/tags/')) return $objectId;

    $object = $this->readFile($this->commonDir, 'objects/' . substr($objectId, 0, 2) . '/' . substr($objectId, 2), false);
    $object = $object !== null ? @gzuncompress($object) : false;
    if ($object !== false && preg_match('/^tag \d+\0object ([0-9a-f]{40})\ntype commit\n/', $object, $matches)) {
      return $matches[1];
    }

    return $objectId;
  }

  /**
   * Returns the target of the last "checkout: moving from X to Y" reflog entry.
   */
  protected function getLastCheckoutTarget(): ?string {
    foreach (array_reverse($this->readLines($this->gitDir, 'logs/HEAD')) as $line) {
      if (preg_match('/\tcheckout: moving from .+ to (\S+)$/', $line, $matches)) {
        return $matches[1];
      }
    }
    return null;
  }

  protected function formatDate(int $timestamp, string $timezone): string {
    return (new \DateTime('@' . $timestamp))
      ->setTimezone(new \DateTimeZone($timezone))
      ->format('Y-m-d');
  }

  protected function findGitDir(string $path): ?string {
    $path = realpath($path) ?: $path;
    while (true) {
      $git = $path . '/.git';
      if (@is_dir($git)) {
        return $git;
      }
      //worktrees and submodules use a .git file pointing to the git dir
      if (@is_file($git) && preg_match('/^gitdir: (.+)$/', trim((string) @file_get_contents($git)), $matches)) {
        $gitDir = str_starts_with($matches[1], '/') ? $matches[1] : $path . '/' . $matches[1];
        return realpath($gitDir) ?: null;
      }

      $parent = dirname($path);
      if ($parent === $path) return null;
      $path = $parent;
    }
  }

  protected function findCommonDir(string $gitDir): string {
    $commonDir = $this->readFile($gitDir, 'commondir');
    if ($commonDir === null) return $gitDir;
    $commonDir = str_starts_with($commonDir, '/') ? $commonDir : $gitDir . '/' . $commonDir;
    return realpath($commonDir) ?: $gitDir;
  }

  protected function readFile(?string $directory, string $file, bool $trim = true): ?string {
    if ($directory === null) return null;
    $path = $directory . '/' . $file;
    if (!@is_file($path) || !@is_readable($path)) return null;
    $content = @file_get_contents($path);
    if ($content === false) return null;
    return $trim ? trim($content) : $content;
  }

  protected function readLines(?string $directory, string $file): array {
    $content = $this->readFile($directory, $file);
    return $content === null || $content === '' ? [] : explode("\n", $content);
  }

}
