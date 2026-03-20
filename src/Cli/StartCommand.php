<?php
namespace Gt\Server\Cli;

use Gt\Cli\Argument\ArgumentValueList;
use Gt\Cli\Command\Command;
use Gt\Cli\Parameter\NamedParameter;
use Gt\Cli\Parameter\Parameter;
use Gt\Cli\Stream;
use Gt\Daemon\Process;

class StartCommand extends Command {
	const DEFAULT_BIND_HOST = "0.0.0.0";
	const DEFAULT_PORT = 8080;
	const IGNORE_REGEX = "/(127\.0\.0\.1|localhost|\[[\d:]+\])"
		.":\d+ (Accepted|Closing)/";
	const DEFAULT_THREADS = 8;
	private bool $logStaticRequests = true;
	private bool $log404ToErrorLog = true;

	// phpcs:disable Generic.Metrics.CyclomaticComplexity
	public function run(?ArgumentValueList $arguments = null):void {
		$goPath = implode(DIRECTORY_SEPARATOR, [
			"vendor",
			"phpgt",
			"webengine",
			"go.php",
		]);
		if(!file_exists($goPath)) {
			$this->writeLine(
				"Error: Current directory is not a WebEngine project",
				Stream::ERROR
			);
			return;
		}
		$this->loadLoggerOutputConfig();

		$defaultThreads = self::DEFAULT_THREADS;
		$bind = $arguments?->get("bind") ?? self::DEFAULT_BIND_HOST;
		$port = $arguments?->get("port") ?? (string)self::DEFAULT_PORT;
		$threads = $arguments?->get("threads") ?? $defaultThreads;
		$debug = $arguments?->contains("debug") ?? false;

		$docRoot = "www";
		if(!is_dir($docRoot)) {
			mkdir($docRoot);
		}

		$cmd = $this->buildCommand(
			(string)$bind,
			(string)$port,
			$docRoot,
			$goPath,
			$debug,
		);
		$this->writeLine("Executing: " . implode(" ", $cmd));

		$process = new Process(...$cmd);
		$process->setEnv("PHP_CLI_SERVER_WORKERS", (string)$threads);
		$process->exec();

		$time = microtime(true);
		do {
			$dt = microtime(true) - $time;
		}
		while($process->isRunning() && $dt < 0.5);

		do {
			$output = $process->getOutput();
			$error = $process->getErrorOutput();
			$error = $this->filterErrorOutput($error);

			if(!empty($output)) {
				$this->write($output);
			}

			if(!empty($error)) {
				$this->write($error, Stream::ERROR);
			}

			usleep(250000); // 1/4 second
		}
		while($process->isRunning());
	}

	public function getName():string {
		return "start";
	}

	public function getDescription():string {
		return "Start the inbuilt web server";
	}

	/** @return  NamedParameter[] */
	public function getRequiredNamedParameterList():array {
		return [];
	}

	/** @return  NamedParameter[] */
	public function getOptionalNamedParameterList():array {
		return [];
	}

	/** @return  Parameter[] */
	public function getRequiredParameterList():array {
		return [];
	}

	/** @return  Parameter[] */
	public function getOptionalParameterList():array {
		return [
			new Parameter(
				true,
				"port",
				"p",
			),
			new Parameter(
				true,
				"bind",
				"b",
			),
			new Parameter(
				true,
				"threads",
				"t",
			),
			new Parameter(
				false,
				"debug",
				"d",
			),
		];
	}

	/** @return array<string> */
	private function buildCommand(
		string $bind,
		string $port,
		string $docRoot,
		string $goPath,
		bool $debug,
	):array {
		$cmd = ["php"];

		if($debug) {
			array_push($cmd, "-dzend_extension=xdebug.so");
			array_push($cmd, "-dxdebug.mode=debug,profile");
		}

		array_push($cmd, "-S", "$bind:$port", "-t", $docRoot, $goPath);
		return $cmd;
	}

	private function filterErrorOutput(string $errorOutput):string {
		if($errorOutput === "") {
			return "";
		}

		$lineArray = preg_split("/\\R/", $errorOutput);
		if($lineArray === false) {
			return $errorOutput;
		}

		$filteredLineArray = [];
		foreach($lineArray as $line) {
			if($line === "") {
				continue;
			}

			if(preg_match(self::IGNORE_REGEX, $line)) {
				continue;
			}

			$requestLogDetails = $this->extractRequestLogDetails($line);
			if($requestLogDetails) {
				[$statusCode, $path] = $requestLogDetails;
				if(!$this->log404ToErrorLog && $statusCode === 404) {
					continue;
				}

				if(!$this->logStaticRequests && $this->isStaticPath($path)) {
					continue;
				}
			}

			$filteredLineArray[] = $line;
		}

		if(empty($filteredLineArray)) {
			return "";
		}

		return implode(PHP_EOL, $filteredLineArray) . PHP_EOL;
	}

	private function loadLoggerOutputConfig():void {
		$rootPath = getcwd() ?: ".";
		$defaultConfigPath = $rootPath . DIRECTORY_SEPARATOR . "vendor"
			. DIRECTORY_SEPARATOR . "phpgt"
			. DIRECTORY_SEPARATOR . "webengine"
			. DIRECTORY_SEPARATOR . "config.default.ini";
		$projectConfigPath = $rootPath . DIRECTORY_SEPARATOR . "config.ini";

		$defaultConfig = [];
		if(file_exists($defaultConfigPath)) {
			$defaultConfig = parse_ini_file(
				$defaultConfigPath,
				true,
				INI_SCANNER_TYPED
			) ?: [];
		}

		$projectConfig = [];
		if(file_exists($projectConfigPath)) {
			$projectConfig = parse_ini_file(
				$projectConfigPath,
				true,
				INI_SCANNER_TYPED
			) ?: [];
		}

		$this->logStaticRequests = $this->readLoggerBoolean(
			$projectConfig,
			$defaultConfig,
			"log_static_requests",
			true,
		);
		$this->log404ToErrorLog = $this->readLoggerBoolean(
			$projectConfig,
			$defaultConfig,
			"log_404_to_error_log",
			true,
		);
	}

	/**
	 * @param array<string, mixed> $projectConfig
	 * @param array<string, mixed> $defaultConfig
	 */
	private function readLoggerBoolean(
		array $projectConfig,
		array $defaultConfig,
		string $key,
		bool $fallback,
	):bool {
		$projectLogger = $projectConfig["logger"] ?? [];
		if(is_array($projectLogger) && array_key_exists($key, $projectLogger)) {
			return (bool)$projectLogger[$key];
		}

		$defaultLogger = $defaultConfig["logger"] ?? [];
		if(is_array($defaultLogger) && array_key_exists($key, $defaultLogger)) {
			return (bool)$defaultLogger[$key];
		}

		return $fallback;
	}

	/** @return null|array{int, string} */
	private function extractRequestLogDetails(string $line):?array {
		$matched = preg_match(
			'/\[(\d{3})\]:\s+[A-Z]+\s+(\S+)/',
			$line,
			$matches
		);
		if(!$matched) {
			return null;
		}

		$statusCode = (int)$matches[1];
		$path = $matches[2];
		return [$statusCode, $path];
	}

	private function isStaticPath(string $path):bool {
		$parsedPath = parse_url($path, PHP_URL_PATH);
		if(!is_string($parsedPath) || $parsedPath === "") {
			return false;
		}

		$basename = basename($parsedPath);
		return str_contains($basename, ".");
	}
}
