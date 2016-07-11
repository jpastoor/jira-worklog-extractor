<?php


namespace Jpastoor\JiraWorklogExtractor\Command;

use chobie\Jira\Api;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XLSXWriter;

/**
 * Class WorkedHoursPerDayCommand
 *
 * Days on the rows
 * - columns: authors
 * - tabs: labels
 *
 * @package Jpastoor\JiraWorklogExtractor
 * @author Joost Pastoor <joost.pastoor@munisense.com>
 * @copyright Copyright (c) 2016, Munisense BV
 */
class WorkedHoursPerDayCommand extends Command
{
    const MAX_ISSUES_PER_QUERY = 100;

    protected function configure()
    {
        $this
            ->setName('worked-hours-per-day')
            ->setDescription('Days on the rows, labels on the columns and different tabs per person')
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
                InputOption::VALUE_REQUIRED,
                'Path to Excel file',
                "output.xlsx"
            )->addOption(
                'authors-whitelist', null,
                InputOption::VALUE_OPTIONAL,
                'Whitelist of authors (comma separated)'
            )->addOption(
                'labels-whitelist', null,
                InputOption::VALUE_OPTIONAL,
                'Whitelist of labels (comma separated)'
            )->addOption(
                'config_file', null,
                InputOption::VALUE_OPTIONAL,
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

            $jql = "worklogDate <= " . $end_time . " and worklogDate >= " . $start_time . " and timespent > 0";

            if ($input->getOption("labels-whitelist")) {
                $jql .= " and labels in (" . $input->getOption("labels-whitelist") . ")";
            }

            if ($input->getOption("authors-whitelist")) {
                $jql .= " and worklogAuthor in (" . $input->getOption("authors-whitelist") . ")";
            }

            $search_result = $jira->search($jql, $offset, self::MAX_ISSUES_PER_QUERY, "key,project,labels");

            if ($progress == null) {
                /** @var ProgressBar $progress */
                $progress = new ProgressBar($output, $search_result->getTotal());
                $progress->start();
            }

            // For each issue in the result, fetch the full worklog
            $issues = $search_result->getIssues();
            foreach ($issues as $issue) {

                $labels = $issue->getFields()["Labels"];

                $worklog_result = $jira->getWorklogs($issue->getKey(), []);

                $worklog_array = $worklog_result->getResult();
                if (isset($worklog_array["worklogs"]) && !empty($worklog_array["worklogs"])) {
                    foreach ($worklog_array["worklogs"] as $entry) {
                        $author = $entry["author"]["key"];

                        // Filter on author
                        if ($input->getOption("authors-whitelist")) {
                            $authors_whitelist = explode(",", $input->getOption("authors-whitelist"));
                            if (!in_array($author, $authors_whitelist)) {
                                continue;
                            }
                        }

                        // Filter on time
                        $worklog_date = \DateTime::createFromFormat("Y-m-d", substr($entry['started'], 0, 10));
                        $worklog_timestamp = $worklog_date->getTimestamp();

                        if ($worklog_timestamp < $start_timestamp || $worklog_timestamp > $end_timestamp) {
                            continue;
                        }

                        foreach ($labels as $label) {
                            @$worked_time[$label][$author][$worklog_date->format("Y-m-d")] += $entry["timeSpentSeconds"] / 60;
                        }
                    }
                }
                $progress->advance();
            }

            $offset += count($issues);
        } while ($search_result && $offset < $search_result->getTotal());

        $progress->finish();
        $progress->clear();

        if (empty($worked_time)) {
            throw new \Exception("No matching issues found");
        }

        $writer = new XLSXWriter();
        $writer->setAuthor("Munisense BV");


        foreach ($worked_time as $label => $worked_time_label) {
            list($sheet_headers, $sheet_data_by_date) = $this->convertWorkedTimeOfLabelToSheetFormat($worked_time_label);
            $writer->writeSheet(array_values($sheet_data_by_date), $label, $sheet_headers);
        }

        $writer->writeToFile($input->getOption("output_file"));
    }

    /**
     * @param $worked_time_label
     *
     * @return array
     */
    protected function convertWorkedTimeOfLabelToSheetFormat($worked_time_label)
    {
        // Find unique authors per label
        $unique_authors = array_keys($worked_time_label);
        $sheet_headers = ["Date" => "date"];
        foreach ($unique_authors as $unique_author) {
            $sheet_headers[$unique_author] = "integer";
        }
        $unique_authors_map = array_flip($unique_authors);


        $sheet_data_by_date = [];
        foreach ($worked_time_label as $author => $worked_time_days_of_author) {
            foreach ($worked_time_days_of_author as $date => $value) {
                if (!isset($sheet_data_by_date[$date])) {
                    $sheet_data_by_date[$date] = array_merge([$date], array_fill(1, count($unique_authors), 0));
                }

                $sheet_data_by_date[$date][$unique_authors_map[$author] + 1] += $value;
            }
        }

        return [$sheet_headers, $sheet_data_by_date];
    }
}
