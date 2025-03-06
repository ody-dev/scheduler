<?php

namespace Ody\Scheduler\Commands;

use Ody\Core\Foundation\Console\Style;
use Ody\Core\Server\Dependencies;
use Ody\Scheduler\Crontab;
use Ody\Scheduler\SchedulerServerState;
use Ody\Server\ServerManager;
use Ody\Server\ServerType;
use Ody\Server\State\HttpServerState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'scheduler:start',
    description: 'Start scheduler instance'
)]
class StartCommand extends Command
{
    private HttpServerState $serverState;
    private SymfonyStyle $io;

    protected function configure(): void
    {
        $this->addOption(
            'daemonize',
            'd',
            InputOption::VALUE_NONE,
            'The program works in the background'
        )->addOption(
            'watch',
            'w',
            InputOption::VALUE_NONE,
            'Enable a file watcher. Set directories to be watched in your server.php config file.'
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Ody\Scheduler\Exception\Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $serverState = SchedulerServerState::getInstance();
        $this->io = new Style($input, $output);

        if (!$this->canDaemonRun($input) ||
            !$this->checkSslCertificate() ||
            !Dependencies::check($this->io)
        ) {
            return Command::FAILURE;
        }

        if ($serverState->schedulerServerIsRunning()) {
            $this->handleRunningServer($input, $output, $serverState);
        }

        $crontab = new Crontab();
        $jobs = config('scheduler.jobs', []);
        array_walk($jobs, fn ($job) => $crontab->register(new $job()));

        $server = ServerManager::init(ServerType::HTTP_SERVER, SchedulerServerState::getInstance())
            ->createServer(config('scheduler'))
            ->setServerConfig(config('scheduler.additional'))
            ->registerCallbacks(config("scheduler.callbacks"))
            ->getServerInstance();

        $crontab->attachToServer($server);
        $server->start();

        return Command::SUCCESS;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param $serverState
     * @return void
     *
     * TODO: Put this in a reusable trait/class (HasRunningServer))
     */
    private function handleRunningServer(InputInterface $input, OutputInterface $output, $serverState): void
    {
        $this->io->error('failed to listen server port[' . config('server.host') . ':' . config('server.port') . '], Error: Address already', true);

        $helper = $this->getHelper('question');
        $question = new ChoiceQuestion(
            'Do you want the scheduler to terminate? (defaults to no)',
            ['no', 'yes'],
            0
        );
        $question->setErrorMessage('Your selection is invalid.');

        if ($helper->ask($input, $output, $question) !== 'yes') {
            return;
        }

        $serverState->killProcesses([
            $serverState->getMasterProcessId(),
            $serverState->getManagerProcessId(),
            $serverState->getWatcherProcessId(),
            ...$serverState->getWorkerProcessIds()
        ]);

        $serverState->clearProcessIds();

        sleep(2);
    }

    /**
     * @param InputInterface $input
     * @return bool
     */
    private function canDaemonRun(InputInterface $input): bool
    {
        if ($input->getOption('daemonize') && $input->getOption('watch')) {
            $this->io->error('Cannot use watcher in daemonize mode', true);

            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    private function checkSslCertificate(): bool
    {
        if (!is_null(config('server.ssl.ssl_cert_file')) && !file_exists(config('server.ssl.ssl_cert_file'))) {
            $this->io->error("ssl certificate file is not found", true);
            return false;
        }

        if (!is_null(config('server.ssl.ssl_cert_file')) && !file_exists(config('server.ssl.ssl_cert_file'))) {
            $this->io->error("ssl key file is not found", true);
            return false;
        }

        return true;
    }
}
