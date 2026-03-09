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

		$this->writeLine("Server process ended.");
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

			$filteredLineArray[] = $line;
		}

		if(empty($filteredLineArray)) {
			return "";
		}

		return implode(PHP_EOL, $filteredLineArray) . PHP_EOL;
	}
}
