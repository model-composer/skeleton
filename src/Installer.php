<?php namespace Model\Skeleton;

use Composer\IO\IOInterface;
use Composer\Script\Event;

class Installer
{
	private const REPOSITORY = 'https://www.netrails.net/repository/';

	private static IOInterface $io;
	private static string $repository = self::REPOSITORY;
	private static string $key;
	private static array $modules = [];
	private static array $data = [];

	public static function run(Event $event): void
	{
		self::$io = $event->getIO();

		$root = getcwd();
		$skeletonDir = realpath(__DIR__ . '/..');

		self::$io->write('');
		self::$io->write('<info>=== ModEl Framework installer ===</info>');
		self::$io->write('');

		self::promptInputs($root);
		self::fetchModules();
		$selectedModules = self::pickModules();
		$installList = self::fetchInstallList($selectedModules);
		self::downloadFiles($installList, $root);
		self::copyTemplate($skeletonDir . '/template', $root);
		self::writeEnv($root);
		self::runFrameworkSetup($root);
		self::cleanup($skeletonDir, $root);

		self::$io->write('');
		self::$io->write('<info>=== Installation complete ===</info>');
		self::$io->write('Point your web server at this directory to verify.');
		self::$io->write('Add more model/* packages with <comment>composer require model/<package></comment>.');

		if (basename($root) === 'skeleton') {
			self::$io->write('');
			self::$io->write('<warning>Note:</warning> Composer installed into a default <comment>skeleton/</comment> subfolder.');
			self::$io->write('To install directly in the current folder next time, run:');
			self::$io->write('  <comment>composer create-project model/skeleton .</comment>');
		}
	}

	private static function promptInputs(string $root): void
	{
		self::$key = trim((string)self::$io->askAndHideAnswer('  License key: '));
		if (self::$key === '')
			throw new \RuntimeException('License key is required.');

		$defaultName = basename($root);
		self::$data['app_name'] = self::$io->ask('  App name [<comment>' . $defaultName . '</comment>]: ', $defaultName);
		self::$data['path'] = '/';
		self::$data['repository'] = self::$repository;
		self::$data['key'] = self::$key;
	}

	private static function fetchModules(): void
	{
		$response = self::curl(self::$repository . '?act=get-modules&key=' . urlencode(self::$key));
		if ($response === false)
			throw new \RuntimeException("Can't retrieve modules list; wrong address/key or unavailable repository?");

		$modules = json_decode($response, true);
		if (!is_array($modules) or empty($modules))
			throw new \RuntimeException('Error decoding modules list; received corrupted data from repository.');

		self::$modules = $modules;
	}

	private static function pickModules(): array
	{
		$selected = ['Output' => true];
		self::expandDependencies($selected);

		foreach (self::$modules as $mId => $m) {
			if ($mId === 'Core')
				continue;
			self::$data['modulo-' . $mId] = isset($selected[$mId]) ? 1 : 0;
		}

		return array_keys($selected);
	}

	private static function expandDependencies(array &$selected): void
	{
		$changed = true;
		while ($changed) {
			$changed = false;
			foreach (array_keys($selected) as $mId) {
				if (!isset(self::$modules[$mId]))
					continue;

				$deps = self::$modules[$mId]['dependencies'] ?? [];
				foreach (array_keys($deps) as $dep) {
					if ($dep === 'Core')
						continue;
					if (!isset($selected[$dep])) {
						$selected[$dep] = true;
						$changed = true;
					}
				}
			}
		}
	}

	private static function fetchInstallList(array $selectedModules): array
	{
		$response = self::curl(self::$repository . '?act=get-install-list&key=' . urlencode(self::$key) . '&modules=' . urlencode(implode(',', $selectedModules)));
		if ($response === false)
			throw new \RuntimeException("Can't retrieve install list from repository.");

		$list = json_decode($response, true);
		if (!is_array($list) or !isset($list['model'], $list['config']))
			throw new \RuntimeException('Error decoding install list; received corrupted data from repository.');

		return $list;
	}

	private static function downloadFiles(array $installList, string $root): void
	{
		$total = count($installList['model']) + count($installList['config']);
		$done = 0;

		self::$io->write('');
		self::$io->write('Downloading ' . $total . " files...\n\n");

		foreach (['model', 'config'] as $type) {
			foreach ($installList[$type] as $relPath) {
				$done++;
				self::ensureParentDirs($root, $relPath);

				if (substr($relPath, -1) === '/')
					continue;

				$file = self::curl(self::$repository . '?act=get-file&file=' . urlencode($relPath) . '&key=' . urlencode(self::$key) . '&install');
				if ($file === false)
					throw new \RuntimeException('Failed to retrieve file ' . $relPath);

				if ($type === 'config')
					$file = self::elaboraFile($file);

				$absPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
				if (file_put_contents($absPath, $file) === false)
					throw new \RuntimeException('Error writing file ' . $relPath);

				@chmod($absPath, 0755);

				self::$io->overwrite('  [' . $done . '/' . $total . '] ' . $relPath, false);
			}
		}

		self::$io->write('');
	}

	private static function ensureParentDirs(string $root, string $relPath): void
	{
		$parts = explode('/', $relPath);
		$buildingPath = $root;
		foreach ($parts as $f) {
			if ($f === '')
				continue;
			if (stripos($f, '.') !== false)
				break;

			$buildingPath .= DIRECTORY_SEPARATOR . $f;
			if (!is_dir($buildingPath))
				mkdir($buildingPath, 0755, true);
		}
	}

	private static function elaboraFile(string $file): string
	{
		foreach (self::$data as $k => $v) {
			$v = str_replace("'", "\\'", (string)$v);
			$file = str_replace('[zk:' . $k . ']', $v, $file);

			if (strpos($file, '[zkif:' . $k . ']') !== false and strpos($file, '[/zkif:' . $k . ']') !== false) {
				if ($v) {
					$file = str_replace([
						"[zkif:" . $k . "]\n",
						"[/zkif:" . $k . "]\n",
						"[zkif:" . $k . "]",
						"[/zkif:" . $k . "]",
					], '', $file);
				} else {
					$file = preg_replace('/\[zkif:' . preg_quote($k, '/') . '\].+?\[\/zkif:' . preg_quote($k, '/') . '\]\n?\n?/is', '', $file);
				}
			}

			if (strpos($file, '[zkif:!' . $k . ']') !== false and strpos($file, '[/zkif:!' . $k . ']') !== false) {
				if ($v) {
					$file = preg_replace('/\[zkif:!' . preg_quote($k, '/') . '\].+?\[\/zkif:!' . preg_quote($k, '/') . '\]\n?\n?/is', '', $file);
				} else {
					$file = str_replace([
						"[zkif:!" . $k . "]\n",
						"[/zkif:!" . $k . "]\n",
						"[zkif:!" . $k . "]",
						"[/zkif:!" . $k . "]",
					], '', $file);
				}
			}
		}

		return $file;
	}

	private static function copyTemplate(string $templateDir, string $root): void
	{
		if (!is_dir($templateDir))
			return;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($templateDir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST,
		);

		foreach ($iterator as $item) {
			$relPath = substr($item->getPathname(), strlen($templateDir) + 1);
			$dest = $root . DIRECTORY_SEPARATOR . $relPath;

			if ($item->isDir()) {
				if (!is_dir($dest))
					mkdir($dest, 0755, true);
				continue;
			}

			// Skip .gitkeep when its parent dir already has content (downloaded files).
			if (basename($relPath) === '.gitkeep' and is_dir(dirname($dest))) {
				$siblings = array_diff(scandir(dirname($dest)) ?: [], ['.', '..', '.gitkeep']);
				if (!empty($siblings))
					continue;
			}

			copy($item->getPathname(), $dest);
		}
	}

	private static function runFrameworkSetup(string $root): void
	{
		$phpBin = PHP_BINARY;
		$oldCwd = getcwd();
		chdir($root);

		try {
			self::$io->write('');
			self::$io->write('<info>Initializing modules...</info>');

			$exit = 0;
			passthru(escapeshellarg($phpBin) . ' index.php zk/init', $exit);
			if ($exit !== 0)
				throw new \RuntimeException('zk/init failed (exit code ' . $exit . ').');

			self::$io->write('');
			self::$io->write('<info>Building module cache...</info>');

			$exit = 0;
			passthru(escapeshellarg($phpBin) . ' index.php zk/make-cache core_once=1', $exit);
			if ($exit !== 0)
				throw new \RuntimeException('zk/make-cache failed (exit code ' . $exit . ').');
		} finally {
			chdir($oldCwd);
		}
	}

	private static function writeEnv(string $root): void
	{
		$envPath = $root . DIRECTORY_SEPARATOR . '.env';
		if (file_exists($envPath))
			return;

		file_put_contents($envPath, "APP_ENV=development\n");
	}

	private static function cleanup(string $skeletonDir, string $root): void
	{
		// The skeleton's own composer.json sits at $root after composer create-project copied it there.
		// template/composer.json (already moved by copyTemplate) overwrote it with the consumer's one.
		// Now remove the now-irrelevant skeleton bits at the project root.
		self::removeRecursive($root . DIRECTORY_SEPARATOR . 'template');
		self::removeRecursive($root . DIRECTORY_SEPARATOR . 'src');

		// Preserve README.md only if the user already had one; otherwise drop the skeleton's README.
		$readme = $root . DIRECTORY_SEPARATOR . 'README.md';
		if (file_exists($readme) and md5_file($readme) === md5_file($skeletonDir . '/README.md'))
			@unlink($readme);
	}

	private static function removeRecursive(string $path): void
	{
		if (!file_exists($path))
			return;

		if (is_file($path) or is_link($path)) {
			@unlink($path);
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);

		foreach ($iterator as $item) {
			if ($item->isDir())
				@rmdir($item->getPathname());
			else
				@unlink($item->getPathname());
		}

		@rmdir($path);
	}

	private static function curl(string $url, array $post = []): string|false
	{
		$body = json_encode($post);
		$c = curl_init($url);
		curl_setopt($c, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Content-length: ' . strlen($body),
			'Connection: close',
		]);
		curl_setopt($c, CURLOPT_POST, 1);
		curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($c, CURLOPT_HEADER, 1);
		curl_setopt($c, CURLOPT_POSTFIELDS, $body);

		$caBundle = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
		if (is_dir($caBundle))
			curl_setopt($c, CURLOPT_CAPATH, $caBundle);
		else
			curl_setopt($c, CURLOPT_CAINFO, $caBundle);

		$data = curl_exec($c);
		if (curl_errno($c)) {
			$err = curl_error($c);
			curl_close($c);
			throw new \RuntimeException('CURL error: ' . $err);
		}
		curl_close($c);

		$split = explode("\r\n\r\n", $data, 2);
		if (count($split) < 2)
			return false;

		[$header, $responseBody] = $split;
		if (strpos($header, 'HTTP/1.1 200 OK') !== 0 and strpos($header, 'HTTP/2 200') !== 0)
			return false;

		return $responseBody;
	}
}
