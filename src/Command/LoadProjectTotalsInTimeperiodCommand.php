<?php


namespace Jpastoor\JiraWorklogExtractor\Command;

use chobie\Jira\Api;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class LoadProjectTotalsInTimeperiodCommand
 *
 * @package Jpastoor\JiraWorklogExtractor
 * @author Joost Pastoor <joost.pastoor@munisense.com>
 * @copyright Copyright (c) 2016, Munisense BV
 */
class LoadProjectTotalsInTimeperiodCommand extends Command
{
    const MAX_ISSUES_PER_QUERY = 100;

    protected function configure()
    {
        $this
            ->setName('load-project-totals')
            ->setDescription('Load the total time logged in a timeperiod for every project')
            ->addArgument(
                'start_time',
                InputArgument::REQUIRED,
                'From when do you want to load the worklog totals (YYYY-mm-dd)'
            )
            ->addArgument(
                'end_time',
                InputArgument::OPTIONAL,
                'End time to load the worklog totals (YYYY-mm-dd)',
                date("Y-m-d")
            )->addOption(
                'output_file', null,
                InputArgument::OPTIONAL,
                'Path to CSV file'
            )->addOption(
                'config_file', null,
                InputArgument::OPTIONAL,
                'Path to config file',
                __DIR__ . "/../../config.json"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $start_time = $input->getArgument('start_time');
        $end_time = $input->getArgument('end_time');
        $start_time_obj = \DateTime::createFromFormat("Y-m-d", $start_time);
        $end_time_obj = \DateTime::createFromFormat("Y-m-d", $end_time);
        $start_timestamp = mktime(0, 0, 0, $start_time_obj->format("m"), $start_time_obj->format("d"), $start_time_obj->format("Y"));
        $end_timestamp = mktime(23, 59, 59, $end_time_obj->format("m"), $end_time_obj->format("d"), $end_time_obj->format("Y"));

        if (!file_exists($input->getOption("config_file"))) {
            $output->writeln("<error>Could not find config file at " . $input->getOption("config_file") . "</error>");
            die();
        }

        $config = json_decode(file_get_contents($input->getOption("config_file")));

        $jira = new Api($config->jira->endpoint, new Api\Authentication\Basic($config->jira->user, $config->jira->password));

        $progress = null;
        $offset = 0;

        $worked_time = [];

        do {

            $search_result = $jira->search("worklogDate <= " . $end_time . " and worklogDate >= " . $start_time . " and timespent > 0", $offset, self::MAX_ISSUES_PER_QUERY, "key,project");

            if ($progress == null) {
                /** @var ProgressBar $progress */
                $progress = new ProgressBar($output, $search_result->getTotal());
                $progress->start();
            }

            // For each issue in the result, fetch the full worklog
            $issues = $search_result->getIssues();
            foreach ($issues as $issue) {
                $worklog_result = $jira->getWorklogs($issue->getKey(), []);

                $worklog_array = $worklog_result->getResult();
                if (isset($worklog_array["worklogs"]) && !empty($worklog_array["worklogs"])) {
                    foreach ($worklog_array["worklogs"] as $entry) {

                        // Filter on time
                        $worklog_date = \DateTime::createFromFormat("Y-m-d", substr($entry['started'], 0, 10));
                        $worklog_timestamp = $worklog_date->getTimestamp();

                        if ($worklog_timestamp < $start_timestamp || $worklog_timestamp > $end_timestamp) {
                            continue;
                        }

                        @$worked_time[$issue->getProject()["key"]][$entry["author"]["key"]] += $entry["timeSpentSeconds"];
                    }
                }
                $progress->advance();
            }

            $offset += count($issues);
        } while ($search_result && $offset < $search_result->getTotal());

        $progress->finish();
        $progress->clear();

        // List all projects
        $projects = array_keys($worked_time);

        // List all authors
        $authors = [];
        foreach ($worked_time as $worked_time_per_project) {
            $authors = array_unique(array_merge($authors, array_keys($worked_time_per_project)));
        }

        $output->writeln("");

        $output_lines[] = "project;" . implode(";", $authors);

        foreach ($projects as $project) {


            $hours_per_author = [];
            foreach ($authors as $author) {
                $hours_per_author[$author] = isset($worked_time[$project][$author]) ? round($worked_time[$project][$author] / 60 / 60) : 0;
            }

            $output_lines[] = $project . ";" . implode(";", $hours_per_author);
        }


        if ($input->getOption("output_file")) {
            $output_file = $input->getOption("output_file");

            if (file_put_contents($output_file, implode(PHP_EOL, $output_lines))) {
                $output->writeln("<info>Output written to " . $output_file . "</info>");
            } else {
                $output->writeln("<error>Could not write to " . $output_file . "</error>");
            }
        } else {
            // Default output mode to console
            foreach ($output_lines as $output_line) {
                $output->writeln($output_line);
            }
        }
    }
}
