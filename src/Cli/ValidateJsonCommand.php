<?php

declare(strict_types=1);

namespace Scedel\Validator\Cli;

use Scedel\Parser\ParseException;
use Scedel\Parser\ParserService;
use Scedel\Schema\Exception\SchemaBuildException;
use Scedel\Schema\Infrastructure\FilesystemIncludeResolver;
use Scedel\Schema\Infrastructure\FilesystemSourceLoader;
use Scedel\Schema\RepositoryBuilder;
use Scedel\Validator\JsonValidator;
use Throwable;

final class ValidateJsonCommand
{
    /**
     * @param string[] $argv
     */
    public function run(array $argv): int
    {
        [$type, $positionals, $ok] = $this->parseArgs($argv);

        if (!$ok || count($positionals) !== 2) {
            $this->writeStderr(
                "Usage:\n" .
                "  validate-json [--type RootType] <json-or-json-file> <schema.scedel>\n",
            );

            return 2;
        }

        [$jsonInput, $schemaPath] = $positionals;
        $json = $this->loadJsonInput($jsonInput);

        try {
            $builder = new RepositoryBuilder(
                new ParserService(),
                new FilesystemIncludeResolver(),
                new FilesystemSourceLoader(),
            );

            $repository = $builder->buildFromFile($schemaPath);
            $validator = new JsonValidator();
            $errors = $validator->validate($json, $repository, $type);

            if ($errors === []) {
                fwrite(STDOUT, "JSON is valid.\n");
                return 0;
            }

            fwrite(STDERR, "Validation failed:\n");
            foreach ($errors as $error) {
                fwrite(STDERR, sprintf("- %s: %s\n", $error->path, $error->message));
            }

            return 1;
        } catch (Throwable $exception) {
            $this->writeStderr("Failed to validate JSON:\n");
            foreach ($this->formatExceptionDetails($exception) as $line) {
                $this->writeStderr('- ' . $line . "\n");
            }
            return 2;
        }
    }

    private function loadJsonInput(string $input): string
    {
        if (is_file($input)) {
            $contents = @file_get_contents($input);
            if ($contents !== false) {
                return $contents;
            }
        }

        return $input;
    }

    /**
     * @param string[] $argv
     * @return array{0: ?string, 1: array<int, string>, 2: bool}
     */
    private function parseArgs(array $argv): array
    {
        $args = $argv;
        array_shift($args);

        $type = null;
        $positionals = [];

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];

            if (str_starts_with($arg, '--type=')) {
                $type = substr($arg, strlen('--type='));
                continue;
            }

            if ($arg === '--type') {
                if (!isset($args[$i + 1])) {
                    return [null, [], false];
                }

                $type = $args[$i + 1];
                $i++;
                continue;
            }

            $positionals[] = $arg;
        }

        return [$type, $positionals, true];
    }

    private function writeStderr(string $message): void
    {
        fwrite(STDERR, $message);
    }

    /**
     * @return string[]
     */
    private function formatExceptionDetails(Throwable $exception): array
    {
        $lines = [];
        $lines[] = $exception->getMessage();

        if ($exception instanceof SchemaBuildException) {
            if ($exception->source !== null) {
                $lines[] = 'Source: ' . $exception->source->displayName;
            }

            if (count($exception->includeChain) > 1) {
                $chain = array_map(
                    static fn ($source): string => $source->displayName,
                    $exception->includeChain,
                );
                $lines[] = 'Include chain: ' . implode(' -> ', $chain);
            }
        }

        $parseException = $this->findCause($exception, ParseException::class);
        if ($parseException instanceof ParseException) {
            $location = $parseException->sourceName ?? 'unknown source';
            $line = $parseException->getParseLine();
            $column = $parseException->getParseColumn();

            if ($line !== null && $column !== null) {
                $location .= sprintf(' at %d:%d', $line, $column);
            }

            $lines[] = sprintf('Parse error in %s: %s', $location, $parseException->getMessage());
        }

        $previous = $exception->getPrevious();
        while ($previous !== null) {
            if ($parseException instanceof ParseException && $previous instanceof ParseException) {
                $previous = $previous->getPrevious();
                continue;
            }

            $message = trim($previous->getMessage());
            if ($message !== '' && $message !== trim($exception->getMessage())) {
                $lines[] = 'Caused by: ' . $message;
            }
            $previous = $previous->getPrevious();
        }

        return array_values(array_unique($lines));
    }

    private function findCause(Throwable $exception, string $className): ?Throwable
    {
        $current = $exception;
        while ($current !== null) {
            if ($current instanceof $className) {
                return $current;
            }

            $current = $current->getPrevious();
        }

        return null;
    }
}
