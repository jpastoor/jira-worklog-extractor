<?php


namespace Jpastoor\JiraWorklogExtractor\Command;

use chobie\Jira\Api;
use chobie\Jira\Issue;
use Jpastoor\JiraWorklogExtractor\CachedHttpClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XLSXWriter;

/**
 * Class WorkedHoursPerTicketPerAuthorCommand
 *
 * Days on the rows
 * - columns: authors
 *
 * @package Jpastoor\JiraWorklogExtractor
 * @author Joost Pastoor <joost.pastoor@munisense.com>
 * @copyright Copyright (c) 2016, Munisense BV
 */
class ReworkCommand extends Command
{
    const MAX_ISSUES_PER_QUERY = 100;

    protected function configure()
    {
        $this
            ->setName('rework')
            ->setDescription('Tickets on the rows, Rework/Total on the columns, worked hours in the cells')
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
                'clear_cache', "c",
                InputOption::VALUE_NONE,
                'Whether or not to clear the cache before starting'
            )->addOption(
                'output-file', "o",
                InputOption::VALUE_REQUIRED,
                'Path to Excel file',
                __DIR__ . "/../../output/output_" . date("YmdHis") . ".xlsx"
            )->addOption(
                'authors-whitelist', null,
                InputOption::VALUE_OPTIONAL,
                'Whitelist of authors (comma separated)'
            )->addOption(
                'labels-whitelist', null,
                InputOption::VALUE_OPTIONAL,
                'Whitelist of labels (comma separated)'
            )->addOption(
                'config-file', null,
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

        if (!file_exists($input->getOption("config-file"))) {
            $output->writeln("<error>Could not find config file at " . $input->getOption("config-file") . "</error>");
            die();
        }

        $config = json_decode(file_get_contents($input->getOption("config-file")));

        $cached_client = new CachedHttpClient(new Api\Client\CurlClient());
        $jira = new Api(
            $config->jira->endpoint,
            new Api\Authentication\Basic($config->jira->user, $config->jira->password),
            $cached_client
        );

        if ($input->getOption("clear_cache")) {
            $cached_client->clear();
        }

        $progress = null;
        $offset = 0;

        $issue_descriptions = [];
        $issue_rework_of = [];
        $issue_rework_time = [];
        $issue_total_time = [];

        do {

            $jql = "worklogDate <= " . $end_time . " and worklogDate >= " . $start_time . " and timespent > 0  and timeSpent < " . rand(1000000, 9000000) . " ";

            if ($input->getOption("labels-whitelist")) {
                $jql .= " and labels in (" . $input->getOption("labels-whitelist") . ")";
            }

            if ($input->getOption("authors-whitelist")) {
                $jql .= " and worklogAuthor in (" . $input->getOption("authors-whitelist") . ")";
            }

            $search_result = $jira->search($jql, $offset, self::MAX_ISSUES_PER_QUERY, "key,project,labels,summary,issuelinks");

            if ($progress == null) {
                /** @var ProgressBar $progress */
                $progress = new ProgressBar($output, $search_result->getTotal());
                $progress->start();
            }

            // For each issue in the result, fetch the full worklog
            /** @var Issue[] $issues */
            $issues = $search_result->getIssues();

            foreach ($issues as $issue) {

                $issue_key = $issue->getKey();
                $is_rework_of = null;

                // Check if this is rework
                $linked_isses = $issue->get("Linked Issues");
                if (is_array($linked_isses)) {
                    foreach ($linked_isses as $linked_iss) {
                        if (isset($linked_iss["inwardIssue"]) && $linked_iss["type"]["inward"] === "is caused by") {
                            $is_rework_of = $linked_iss["inwardIssue"]["key"];
                            echo $issue_key . " is " . $linked_iss["type"]["inward"] . " " . $linked_iss["inwardIssue"]["key"] . PHP_EOL;
                        }
                    }
                }

                $issue_descriptions[$issue_key] = $issue->getSummary();
                $issue_labels[$issue_key] = implode(",", $issue->getLabels());
                $issue_rework_of[$issue_key] = $is_rework_of;
                $issue_rework_time[$issue_key] = 0;
                $issue_total_time[$issue_key] = 0;

                $worklog_result = $jira->getWorklogs($issue_key, []);

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

                        if ($is_rework_of) {
                            $issue_rework_time[$issue_key] += $entry["timeSpentSeconds"] / 60;
                        } else {
                            $issue_total_time[$issue_key] += $entry["timeSpentSeconds"] / 60;
                        }
                    }
                }
                $progress->advance();
            }

            $offset += count($issues);
        } while ($search_result && $offset < $search_result->getTotal());

        $progress->finish();
        $progress->clear();

        if (empty($issue_total_time)) {
            throw new \Exception("No matching issues found");
        }

        $writer = new XLSXWriter();
        $writer->setAuthor("Munisense BV");

        list($sheet_headers, $sheet_data_by_date) = $this->convertWorkedTimeOfLabelToSheetFormat(array_keys($issue_descriptions), $issue_descriptions, $issue_rework_of, $issue_rework_time, $issue_total_time);

        $writer->writeSheetHeader("sheet1", $sheet_headers);

        $totals_row = [""];
        for ($i = 1, $iMax = count($sheet_headers); $i < $iMax; $i++) {
            $totals_row[] = "=ROUND(SUM(" . XLSXWriter::xlsCell(2, $i) . ":" . XLSXWriter::xlsCell(10000, $i) . ")/60,0)";
        }
        $writer->writeSheetRow("sheet1", $totals_row);

        foreach ($sheet_data_by_date as $row) {
            $writer->writeSheetRow("sheet1", $row);
        }


        $writer->writeToFile($input->getOption("output-file"));
    }

    /**
     * @param $worked_time_label
     *
     * @return array
     */
    protected function convertWorkedTimeOfLabelToSheetFormat($issue_keys, $issue_descriptions, $issue_rework_of, $issue_rework_time, $issue_total_time)
    {
        // Find unique authors per label
        $sheet_headers = ["Ticket" => "string", "Description" => "string", "CausedBy" => "string", "Rework"  => "string", "Total"  => "string"];

        $sheet_data_by_date = [];
        foreach ($issue_keys as $key) {
            $sheet_data_by_date[$key] = [$key, $issue_descriptions[$key], $issue_rework_of[$key], $issue_rework_time[$key], $issue_total_time[$key]];
        }

        ksort($sheet_data_by_date);

        return [$sheet_headers, $sheet_data_by_date];
    }
}
