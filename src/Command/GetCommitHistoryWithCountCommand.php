<?php

namespace App\Command;

use DateTime;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:get-commit-history-with-count',
    description: 'Get commit history for a file',
)]
class GetCommitHistoryWithCountCommand extends Command
{
    private const GITLAB_API_URL = '%s/api/v4/projects/%s/repository/commits?path=%s&per_page=100&page=1';
    private const JIRA_API_URL = '%s/rest/api/2/issue/%s';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $gitlabUrl,
        private string $privateToken,
        private string $jiraUrl,
        private string $jiraUser,
        private string $jiraPassword,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Gitlab project id')
            ->addOption('file-path', 'f', InputOption::VALUE_REQUIRED, 'File path')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectId = $input->getOption('project');
        $filepath = $input->getOption('file-path');

        //get commits from API
        $url = sprintf(self::GITLAB_API_URL, $this->gitlabUrl, $projectId, $filepath);

        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'PRIVATE-TOKEN' => $this->privateToken,
            ],
        ]);

        // count commits
        $commits = json_decode($response->getContent(), true);
        $commitsCount = count($commits);

        $firstCommitDate = (new DateTime($commits[$commitsCount - 1]['committed_date']))->format('Y-m-d');
        $lastCommitDate = (new DateTime($commits[0]['committed_date']))->format('Y-m-d');
        $datesRange = sprintf('%s - %s', $firstCommitDate, $lastCommitDate);

        $io->writeln([$commitsCount, $datesRange, PHP_EOL]);

        // get commit messages
        $jiraTickets = [];
        $jiraMessages = [];
        foreach ($commits as $commit) {
            $matches = [];
            if (
                preg_match('/[a-zA-Z]+-\d+/', $commit['title'], $matches) &&
                !in_array($matches[0], $jiraTickets, true)
            ) {
                $url = sprintf(self::JIRA_API_URL, $this->jiraUrl, $matches[0]);
                $basicAuth = base64_encode(sprintf('%s:%s', $this->jiraUser, $this->jiraPassword));
                try {
                    $response = $this->httpClient->request('GET', $url, [
                        'headers' => [
                            'Authorization' => sprintf('Basic %s', $basicAuth),
                        ],
                    ]);
                    $content = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
                    $summary = $content['fields']['summary'] ?? null;
                    $storyPoints = $content['fields']['customfield_10063'] ?? null;

                    $jiraTickets[] = $matches[0];
                    $jiraMessages[] = sprintf('%s - %s [SP: %s]', $matches[0], $summary, $storyPoints);
                } catch (\Exception $e) {
                }
            }
        }

        $io->writeln(array_unique($jiraMessages));

        $io->success('FIN!');

        return Command::SUCCESS;
    }
}
