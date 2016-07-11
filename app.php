#!/usr/bin/env php
<?php

require __DIR__ . "/vendor/autoload.php";

use Jpastoor\JiraWorklogExtractor\Command\LoadProjectTotalsInTimeperiodCommand;
use Jpastoor\JiraWorklogExtractor\Command\WorkedHoursPerDayCommand;
use Symfony\Component\Console\Application;

$application = new Application();
$application->add(new LoadProjectTotalsInTimeperiodCommand());
$application->add(new WorkedHoursPerDayCommand());
$application->run();
