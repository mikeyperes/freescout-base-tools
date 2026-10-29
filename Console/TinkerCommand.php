<?php

namespace Modules\HexawebBaseTools\Console;

use Laravel\Tinker\ClassAliasAutoloader;
use Laravel\Tinker\Console\TinkerCommand as BaseTinkerCommand;
use Psy\Configuration;
use Psy\Shell;
use Symfony\Component\Console\Input\InputOption;

class TinkerCommand extends BaseTinkerCommand
{
    protected function getOptions()
    {
        return array_merge(parent::getOptions(), [
            ['execute', null, InputOption::VALUE_REQUIRED, 'Execute PHP code without opening an interactive shell'],
        ]);
    }

    public function handle()
    {
        $code = $this->option('execute');
        if ($code === null) {
            return parent::handle();
        }

        $config = new Configuration(['updateCheck' => 'never', 'historySize' => 0]);
        $config->getPresenter()->addCasters($this->getCasters());
        $shell = new Shell($config);
        $shell->setOutput($config->getOutput());
        $loader = ClassAliasAutoloader::register(
            $shell,
            $this->getLaravel()->basePath('vendor/composer/autoload_classmap.php')
        );

        try {
            foreach ($this->argument('include') as $file) {
                $shell->execute('require '.var_export($file, true).';', true);
            }
            $shell->execute($code, true);
        } finally {
            $loader->unregister();
        }

        return 0;
    }
}
