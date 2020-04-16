<?php
// @codingStandardsIgnoreFile
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types = 1);

namespace Magento\FunctionalTestingFramework\Console;

use Magento\FunctionalTestingFramework\StaticCheck\StaticCheckInterface;
use Magento\FunctionalTestingFramework\StaticCheck\StaticChecksList;
use Magento\FunctionalTestingFramework\Util\Logger\LoggingUtil;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Exception;
use Symfony\Component\Console\Style\SymfonyStyle;

class StaticChecksCommand extends Command
{
    /**
     * Pool of all existing static check objects
     *
     * @var StaticCheckInterface[]
     */
    private $allStaticCheckObjects;

    /**
     * Static checks to run
     *
     * @var StaticCheckInterface[]
     */
    private $staticCheckObjects;

    /**
     * Console output style
     *
     * @var SymfonyStyle
     */
    protected $ioStyle;

    /**
     * Configures the current command.
     *
     * @return void
     */
    protected function configure()
    {
        $list = new StaticChecksList();
        $this->allStaticCheckObjects = $list->getStaticChecks();
        $staticCheckNames = implode(', ', array_keys($this->allStaticCheckObjects));
        $description = 'This command will run all static checks on xml test materials. '
            . 'Available static check scripts are:' . PHP_EOL . $staticCheckNames;
        $this->setName('static-checks')
            ->setDescription($description)
            ->addArgument(
                'names',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'name(s) of specific static check script(s) to run'
            )->addOption(
                'path',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Path to a MFTF test module to run "deprecatedEntityUsage" static check script. ' . PHP_EOL
                . 'Option is ignored by other static check scripts.' . PHP_EOL
            );
    }

    /**
     * Run required static check scripts
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->ioStyle = new SymfonyStyle($input, $output);
        try {
            $this->validateInput($input);
        } catch (InvalidArgumentException $e) {
            LoggingUtil::getInstance()->getLogger(StaticChecksCommand::class)->error($e->getMessage());
            $this->ioStyle->error($e->getMessage() . ' Please fix input argument(s) or option(s) and rerun.');
            return 1;
        }

        $cmdFailed = false;
        $errors = [];
        foreach ($this->staticCheckObjects as $name => $staticCheck) {
            LoggingUtil::getInstance()->getLogger(get_class($staticCheck))->info(
                'Running static check script for: ' . $name . PHP_EOL
            );

            $this->ioStyle->text(PHP_EOL . 'Running static check script for: ' . $name . PHP_EOL);
            $start = microtime(true);
            try {
                $staticCheck->execute($input);
            } catch (Exception $e) {
                $cmdFailed = true;
                LoggingUtil::getInstance()->getLogger(get_class($staticCheck))->error($e->getMessage() . PHP_EOL);
                $this->ioStyle->error($e->getMessage());
            }
            $end = microtime(true);
            $errors += $staticCheck->getErrors();

            $staticOutput = $staticCheck->getOutput();
            LoggingUtil::getInstance()->getLogger(get_class($staticCheck))->info($staticOutput);
            $this->ioStyle->text($staticOutput);

            $this->ioStyle->text('Total execution time is ' . (string)($end - $start) . ' seconds.' . PHP_EOL);
        }
        if (!$cmdFailed && empty($errors)) {
            return 0;
        } else {
            return 1;
        }
    }

    /**
     * Validate input arguments
     *
     * @param InputInterface $input
     * @return void
     * @throws InvalidArgumentException
     */
    private function validateInput(InputInterface $input)
    {
        $this->staticCheckObjects = [];
        $requiredChecksNames = $input->getArgument('names');
        $invalidCheckNames = [];
        // Found user required static check script(s) to run,
        // If no static check name is supplied, run all static check scripts
        if (empty($requiredChecksNames)) {
            $this->staticCheckObjects = $this->allStaticCheckObjects;
        } else {
            for ($index = 0; $index < count($requiredChecksNames); $index++) {
                if (in_array($requiredChecksNames[$index], array_keys($this->allStaticCheckObjects))) {
                    $this->staticCheckObjects[$requiredChecksNames[$index]] =
                        $this->allStaticCheckObjects[$requiredChecksNames[$index]];
                } else {
                    $invalidCheckNames[] = $requiredChecksNames[$index];
                }
            }
        }

        if (!empty($invalidCheckNames)) {
            throw new InvalidArgumentException(
                'Invalid static check script(s): ' . implode(', ', $invalidCheckNames) . '.'
            );
        }

        if ($input->getOption('path')) {
            if ( (count($this->staticCheckObjects) !== 1)
                || array_keys($this->staticCheckObjects)[0] !== StaticChecksList::DEPRECATED_ENTITY_USAGE_CHECK_NAME )
                throw new InvalidArgumentException(
                    '--path option can only be used for "'
                    . StaticChecksList::DEPRECATED_ENTITY_USAGE_CHECK_NAME
                    . '".'
                );
        }
    }
}
