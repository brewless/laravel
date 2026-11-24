<?php

declare(strict_types=1);

namespace Brewless\Laravel\Console;

use Illuminate\Support\Env;
use Laravel\Tinker\ClassAliasAutoloader;
use Laravel\Tinker\Console\TinkerCommand;
use Psy\Configuration;
use Psy\Shell;
use Psy\VersionUpdater\Checker;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Tinker for one line, as `brewless tinker` runs it over the command route:
 * the code of `--execute` runs and the value of its last expression is
 * printed (`= 42`), as tinker prints it at the prompt. Laravel's own
 * `tinker --execute` prints only what the code echoes. Anything else, and
 * every shell, is Laravel's tinker as it is.
 *
 * Only registered when laravel/tinker is installed.
 */
final class TinkerLineCommand extends TinkerCommand
{
    protected $name = 'brewless:tinker';

    protected $description = 'Run a line of PHP with tinker and print the value of its last expression';

    protected $hidden = true;

    public function handle()
    {
        $code = $this->option('execute');

        if (! is_string($code) || trim($code) === '') {
            return parent::handle();
        }

        $this->getApplication()?->setCatchExceptions(false);

        $config = Configuration::fromInput($this->input);
        $config->setUpdateCheck(Checker::NEVER);
        $config->setColorMode(Configuration::COLOR_MODE_DISABLED);

        $appConfig = $this->getLaravel()->make('config');

        if (method_exists($config, 'setTrustProject')) {
            $config->setTrustProject($appConfig->get('tinker.trust_project'));
        }

        $config->getPresenter()->addCasters($this->getCasters());

        $shell = new Shell($config);
        $shell->addCommands($this->getCommands());
        $shell->setOutput($this->output);

        $loader = ClassAliasAutoloader::register(
            $shell,
            $this->vendorPath().'/composer/autoload_classmap.php',
            $appConfig->get('tinker.alias', []),
            $appConfig->get('tinker.dont_alias', []),
        );

        try {
            $shell->writeReturnValue($shell->execute($code, true));
        } catch (Throwable $exception) {
            $shell->writeException($exception);

            return self::FAILURE;
        } finally {
            $loader->unregister();
        }

        return self::SUCCESS;
    }

    protected function getOptions()
    {
        return [
            ['execute', null, InputOption::VALUE_OPTIONAL, 'Execute the given code using Tinker'],
        ];
    }

    private function vendorPath(): string
    {
        return (string) Env::get('COMPOSER_VENDOR_DIR', $this->getLaravel()->basePath().DIRECTORY_SEPARATOR.'vendor');
    }
}
