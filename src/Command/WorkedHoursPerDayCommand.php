<?php

namespace Jpastoor\JiraWorklogExtractor\Command;

use chobie\Jira\Api;
use DateTime;
use Exception;
use Jpastoor\JiraWorklogExtractor\CachedHttpClient;
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
    public const MAX_ISSUES_PER_QUERY = 100;

    protected function configure(): void
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
                date('Y-m-d')
            )->addOption(
                'clear_cache', 'c',
                InputOption::VALUE_NONE,
                'Whether or not to clear the cache before starting'
            )->addOption(
                'output-file', 'o',
                InputOption::VALUE_REQUIRED,
                'Path to Excel file',
                __DIR__ . '/../../output/output_' . date('YmdHis') . '.xlsx'
            )->addOption(
                'authors-whitelist', null,
                InputOption::VALUE_OPTIONAL,
                'Whitelist of authors (comma separated)'
            )->addOption(
                'labels-whitelist', null,
                InputOption::VALUE_OPTIONAL,
                'Whitelist of labels (comma separated)'
            )->addOption(
                'labels-blacklist', null,
                InputOption::VALUE_OPTIONAL,
                'Blacklist of labels (comma separated)'
            )->addOption(
                'config-file', null,
                InputOption::VALUE_OPTIONAL,
                'Path to config file',
                __DIR__ . '/../../config.json'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $start_time = $input->getArgument('start_time');
        $end_time = $input->getArgument('end_time');
        $start_time_obj = DateTime::createFromFormat('Y-m-d', $start_time);
        $end_time_obj = DateTime::createFromFormat('Y-m-d', $end_time);
        $start_timestamp = mktime(0, 0, 0, $start_time_obj->format('m'), $start_time_obj->format('d'), $start_time_obj->format('Y'));
        $end_timestamp = mktime(23, 59, 59, $end_time_obj->format('m'), $end_time_obj->format('d'), $end_time_obj->format('Y'));

        if (!file_exists($input->getOption('config-file'))) {
            $output->writeln('<error>Could not find config file at ' . $input->getOption('config-file') . '</error>');
            die();
        }

        $config = json_decode(file_get_contents($input->getOption('config-file')), false, 512, JSON_THROW_ON_ERROR);

        $cached_client = new CachedHttpClient(new Api\Client\CurlClient());
        $jira = new Api(
            $config->jira->endpoint,
            new Api\Authentication\Basic($config->jira->user, $config->jira->password),
            $cached_client
        );

        if ($input->getOption('clear_cache')) {
            $cached_client->clear();
        }

        // Fetch all users and store them in handy lookup lists
        $users = $jira->api("GET", '/rest/api/2/users', [
            "maxResults" => 10000,
        ]);
        $display_name_by_account_id = [];
        $account_id_by_display_name = [];
        foreach ($users->getResult() as $user) {
            $display_name_by_account_id[$user["accountId"]] = $user["displayName"];
            $account_id_by_display_name[$user["displayName"]] = $user["accountId"];
        }

        // Convert the whitelist if it contains displayNames to only contain accountIds
        $authors_whitelist = [];
        if ($input->getOption('authors-whitelist')) {
            $authors_whitelist = explode(',', $input->getOption('authors-whitelist'));
            foreach ($authors_whitelist as $i => $elem) {
                if (array_key_exists($elem, $account_id_by_display_name)) {
                    $authors_whitelist[$i] = $account_id_by_display_name[$elem];
                } else if (!array_key_exists($elem, $display_name_by_account_id)) {
                    throw new Exception("Could not find displayname for user " . $elem);
                }
            }
        }

        $progress = null;
        $offset = 0;

        $worked_time = [];

        do {

            $jql = 'worklogDate <= ' . $end_time . ' and worklogDate >= ' . $start_time . ' and timespent > 0  and timeSpent < ' . random_int(1000000, 9000000) . ' ';

            if ($input->getOption('labels-whitelist')) {
                $jql .= ' and labels in (' . $input->getOption('labels-whitelist') . ')';
                $labels_whitelist = explode(',', $input->getOption('labels-whitelist'));
            }

            if ($input->getOption('labels-blacklist')) {
                $jql .= ' and labels not in (' . $input->getOption('labels-blacklist') . ')';
            }

            if (!empty($authors_whitelist)) {
                $jql .= ' and worklogAuthor in (' . implode(',', $authors_whitelist) . ')';
            }

            $search_result = $jira->search($jql, $offset, self::MAX_ISSUES_PER_QUERY, 'key,project,labels');

            if ($progress === null) {
                /** @var ProgressBar $progress */
                $progress = new ProgressBar($output, $search_result->getTotal());
                $progress->start();
            }

            // For each issue in the result, fetch the full worklog
            $issues = $search_result->getIssues();
            foreach ($issues as $issue) {

                $labels = $issue->getFields()['Labels'];
                if (isset($labels_whitelist)) {
                    $labels = array_intersect($labels, $labels_whitelist);
                }

                if (count($labels) > 1) {
                    $output->write('<error>' . $issue . ' has multiple labels: ' . implode(', ', $labels) . '</error>');
                }

                $worklog_result = $jira->getWorklogs($issue->getKey(), []);

                $worklog_array = $worklog_result->getResult();
                if (isset($worklog_array['worklogs']) && !empty($worklog_array['worklogs'])) {
                    foreach ($worklog_array['worklogs'] as $entry) {
                        $author_account_id = $entry['author']['accountId'];
                        $author_display_name = $display_name_by_account_id[$author_account_id];

                        // Filter on author
                        if (!empty($authors_whitelist) && !in_array($author_account_id, $authors_whitelist, true)) {
                            continue;
                        }

                        // Filter on time
                        $worklog_date = DateTime::createFromFormat('Y-m-d', substr($entry['started'], 0, 10));
                        $worklog_timestamp = $worklog_date->getTimestamp();

                        if ($worklog_timestamp < $start_timestamp || $worklog_timestamp > $end_timestamp) {
                            continue;
                        }

                        foreach ($labels as $label) {
                            @$worked_time[$label][$author_display_name][$worklog_date->format('Y-m-d')] += $entry['timeSpentSeconds'] / 60;
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
            throw new Exception('No matching issues found');
        }

        $writer = new XLSXWriter();
        $writer->setAuthor('Munisense BV');

        ksort($worked_time);

        foreach ($worked_time as $label => $worked_time_label) {

            ksort($worked_time_label);

            list($sheet_headers, $sheet_data_by_date) = $this->convertWorkedTimeOfLabelToSheetFormat($worked_time_label);

            $writer->writeSheetHeader($label, $sheet_headers);

            $totals_row = [''];
            $sheet_header_count = count($sheet_headers);
            for ($i = 1; $i < $sheet_header_count; $i++) {
                $totals_row[] = '=ROUND(SUM(' . XLSXWriter::xlsCell(2, $i) . ':' . XLSXWriter::xlsCell(10000, $i) . ')/60,0)';
            }
            $writer->writeSheetRow($label, $totals_row);

            foreach ($sheet_data_by_date as $row) {
                $writer->writeSheetRow($label, $row);
            }
        }

        $writer->writeToFile($input->getOption('output-file'));
    }

    /**
     * @param $worked_time_label
     *
     * @return array
     */
    protected function convertWorkedTimeOfLabelToSheetFormat($worked_time_label): array
    {
        // Find unique authors per label
        $unique_authors = array_keys($worked_time_label);
        $sheet_headers = ['Date' => 'date'];
        foreach ($unique_authors as $unique_author) {
            $sheet_headers[$unique_author] = 'integer';
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

        ksort($sheet_data_by_date);

        return [$sheet_headers, $sheet_data_by_date];
    }
}
