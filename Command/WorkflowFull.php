<?php
/**
 * This file is part of the Onm package.
 *
 * (c)  OpenHost S.L. <developers@openhost.es>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 **/
namespace Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

class WorkflowFull extends Command
{
    protected function configure()
    {
        $this
            ->setName('workflow:full')
            ->setDefinition(
                array(
                    new InputOption('release-set', 'r', InputOption::VALUE_REQUIRED, 'The release set to translate', null),
                    new InputOption('language', 'l', InputOption::VALUE_REQUIRED, 'The language to translate into', null),
                )
            )
            ->setDescription('Extracts and updates the localized strings')
            ->setHelp(
                <<<EOF
The <info>damned:lies</info> checks the GNOME Damned Lies web service to
fetch new translation settings.
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        if (!$this->checkEnvironment()) {
            die();
        }

        $config = $this->getConfig();

        $releaseSet = $this->input->getOption('release-set');
        if (!is_null($releaseSet)) {
            $config['release_set'] = $releaseSet;
        }

        $releaseSet = $this->input->getOption('language');
        if (!is_null($releaseSet)) {
            $config['language'] = $releaseSet;
        }

        $this->output->writeln("<comment>Full workflow for {$config['release_set']} [{$config['language']}]...</comment>");

        $stats = $this->fetchStatsForReleaseAndLang($config['release_set'], $config['language']);
        $untranslatedModules = $this->getUntranslatedModules($stats);

        $dialog = $this->getHelperSet()->get('dialog');

        if (count($untranslatedModules) <= 0) {
            $this->output->writeln('All modules translated! Go to rest!');

            return false;
        }

        $pickModules = '';
        $autoComplete = array();
        foreach ($untranslatedModules as $key => $module) {
            $pickModules .=
                "\t<comment>{$module['name']}</comment> - {$module['branch']} ~ "
                ." {$module['stats']['untranslated']} untranslated, {$module['stats']['fuzzy']} fuzzy\n";
            $autoComplete []= $module['name'];
        }

        while (true) {
            $this->output->writeln("   Modules with translations needed in {$config['language']}/{$config['release_set']}");
            $selection = (string) $dialog->ask(
                $output,
                $pickModules.
                '   Which module do you want to translate [0]: ',
                0,
                $autoComplete
            );

            if (is_string($selection)) {
                $command = $this->getApplication()->find('module:translate');
                $returnCode = $command->run(
                    new ArrayInput(
                        array(
                            'command'  => 'module:translate',
                            'module' => $untranslatedModules[$selection]['name'],
                            '--branch' => $untranslatedModules[$selection]['branch']
                        )
                    ),
                    $output
                );

                $command = $this->getApplication()->find('module:commit');
                $returnCode = $command->run(
                    new ArrayInput(
                        array(
                            'command'  => 'module:commit',
                            'module' => $untranslatedModules[$selection]['name'],
                        )
                    ),
                    $output
                );

                $command = $this->getApplication()->find('module:push');
                $returnCode = $command->run(
                    new ArrayInput(
                        array(
                            'command'  => 'module:push',
                            'module' => $untranslatedModules[$selection]['name'],
                        )
                    ),
                    $output
                );
            }
        }

    }

    protected function getConfig()
    {
        return $this->getApplication()->config;
    }

    protected function fetchStatsForReleaseAndLang($releaseSet, $lang)
    {
        $this->output->write("   Fetching DL stats...");

        $url = "https://l10n.gnome.org/languages/$lang/$releaseSet/xml";
        $serverContents = @simplexml_load_file($url);

        if (!$serverContents) {
            $this->output->writeln("\t<error>Release set '$releaseSet' or language '$lang' not valid</error>");
            die();
        }

        $categories = $serverContents->xpath('category');

        $modules = array();
        foreach ($categories as $category) {
            $rawModules   = $category->module;

            foreach ($rawModules as $module) {
                $modules [(string) $module->attributes()['id']]= array(
                    'name'   => (string) $module->attributes()['id'],
                    'branch' => (string) $module->attributes()['branch'],
                    'stats'  => array(
                        'translated'   => (int) $module->domain->translated,
                        'untranslated' => (int) $module->domain->untranslated,
                        'fuzzy'        => (int) $module->domain->fuzzy,
                    )
                );
            }

        }
        $this->output->writeln("<fg=green;> DONE</fg=green;>");

        return $modules;
    }

    protected function getUntranslatedModules($stats)
    {
        $modules = array_filter(
            $stats,
            function ($module) {
                return (($module['stats']['untranslated'] + $module['stats']['fuzzy']) > 0);
            }
        );

        uasort(
            $modules,
            function ($a, $b) {
                $aNotCompleted = $a['stats']['untranslated'] + $a['stats']['fuzzy'];
                $bNotCompleted = $b['stats']['untranslated'] + $b['stats']['fuzzy'];

                if ($aNotCompleted == $bNotCompleted) {
                    return 0;
                }

                return ($aNotCompleted < $bNotCompleted) ? -1 : 1;
            }
        );

        return $modules;
    }

    protected function checkEnvironment()
    {
        // Checks for configuration
        // if not configured run the setup wizard
        $configFile = __DIR__.'/../config.yaml';
        if (file_exists($configFile)) {
            $configuration = file_get_contents($configFile);
            return true;
        } else {
            $this->output->writeln("\t<error>Not configured... Running Setup Wizard.. TODO</error>");
            return false;
        }
    }
}
