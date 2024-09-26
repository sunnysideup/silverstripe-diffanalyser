<?php

namespace Sunnysideup\DiffAnalyser;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class GitDiffAnalyser
{
    protected bool $debug = false;
    protected string $directory = '.';
    protected string|null $date = null;
    protected int|null $days = 1;
    protected string $branch = 'develop';
    protected string $filter = '';
    protected float $minutesForFirstLineChange = 3;
    protected float $instantiationCostInMinutes = 10;
    protected int $verbosity = 2;
    protected bool $showFullDiff = false;
    protected string|null $currentDir = null;
    protected array $branchesToCheck = ['develop', 'main', 'master'];

    protected $totalChangesPerDayPerRepo = [];
    protected float $decreaseFactor = 0.9;

    protected $fileTypes = [
        ['extension' => '\.php', 'type' => 'PHP'],
        ['extension' => '\.js', 'type' => 'JavaScript'],
        // ['extension' => '\.css', 'type' => 'CSS'], see SASS / LESS / SCSS
        ['extension' => '\.(yml|yaml)', 'type' => 'YML/YAML'],
        ['extension' => '\.ss', 'type' => 'SilverStripe (SS)'],
        ['extension' => '\.html', 'type' => 'HTML'],
        ['extension' => '\.json', 'type' => 'JSON'],
        ['extension' => '\.xml', 'type' => 'XML'],
        ['extension' => '\.md', 'type' => 'Markdown'],
        // ['extension' => '\.png', 'type' => 'Image'],
        // ['extension' => '\.jpg', 'type' => 'Image'],
        // ['extension' => '\.jpeg', 'type' => 'Image'],
        // ['extension' => '\.gif', 'type' => 'Image'],
        ['extension' => '\.svg', 'type' => 'SVG'],
        // ['extension' => '\.sql', 'type' => 'SQL'],
        ['extension' => '\.sh', 'type' => 'Shell Script'],
        ['extension' => 'composer\.json', 'type' => 'Composer'],
        // ['extension' => 'composer\.lock', 'type' => 'Composer'],
        // ['extension' => '\.env', 'type' => 'Environment Variables'],
        ['extension' => '\.twig', 'type' => 'Twig Template'],
        ['extension' => '\.blade\.php', 'type' => 'Blade Template'],
        ['extension' => '\.test\.php', 'type' => 'PHP Test'],
        ['extension' => '\.spec\.php', 'type' => 'PHP Spec Test'],
        ['extension' => '\.scss', 'type' => 'SASS/SCSS'],
        ['extension' => '\.sass', 'type' => 'SASS'],
        ['extension' => '\.less', 'type' => 'LESS'],
        ['extension' => '\.ini', 'type' => 'INI Config'],
        ['extension' => '\.conf', 'type' => 'Config File']
    ];

    public function setDebug(bool $debug): static
    {
        $this->debug = $debug;
        return $this;
    }

    public function setDirectory(string $directory): static
    {
        $this->directory = realpath($directory);
        return $this;
    }

    public function setDaysOrDate(int|string $daysOrDate): static
    {
        if (is_int($daysOrDate)) {
            $this->setDays($daysOrDate);
        } elseif (strtotime($daysOrDate) > 0) {
            $this->setDate($daysOrDate);
        } else {
            user_error('You need to set a valid number of days or a valid date.');
        }
        return $this;
    }

    public function setDate(string $date): static
    {
        $this->days = null;
        if (strtotime($date) > 0) {
            $this->date = $date;
        } else {
            user_error('Invalid date format. Use a valid date string.');
        }
        return $this;
    }

    public function setDays(int $days): static
    {
        $this->date = null;
        $this->days = $days;
        return $this;
    }


    public function setBranch(string $branch): static
    {
        $this->branch = $branch;
        return $this;
    }

    public function setFilter(string $filter): static
    {
        $this->filter = $filter;
        return $this;
    }

    public function setMinutesForFirstLineChange(float $minutes): static
    {
        $this->minutesForFirstLineChange = $minutes;
        return $this;
    }

    public function setInstantiationCostInMinutes(float $instantiationCostInMinutes): static
    {
        $this->instantiationCostInMinutes = $instantiationCostInMinutes;
        return $this;
    }

    public function setVerbosity(int $verbosity): static
    {
        $this->verbosity = $verbosity;
        return $this;
    }

    public function setShowFullDiff(bool $showFullDiff): static
    {
        $this->showFullDiff = $showFullDiff;
        return $this;
    }

    public function setBranchesToCheck(array $branchesToCheck): static
    {
        $this->branchesToCheck = $branchesToCheck;
        return $this;
    }

    public function setFileTypes(array $fileTypes): static
    {
        $this->fileTypes = $fileTypes;
        return $this;
    }

    public function setFileType(string $extension): static
    {
        $this->fileTypes = array_filter($this->fileTypes, function ($fileType) use ($extension) {
            return $fileType['extension'] === $extension;
        });
        return $this;
    }

    public function addFileType(array $fileType): static
    {
        $this->fileTypes[] = $fileType;
        return $this;
    }

    public function removeFileType(string $extension): static
    {
        $this->fileTypes = array_filter($this->fileTypes, function ($fileType) use ($extension) {
            return $fileType['extension'] !== $extension;
        });
        return $this;
    }


    /**
     * Run the Git diff analysis for the specified number of days or a specific date.
     */
    public function run(): void
    {
        $this->output(PHP_EOL."Starting Git Diff Analysis", 1, 1);
        // Find all Git repositories that match the filter
        if ($this->date) {
            $this->gitDiffForDay($this->date);
        } else {
            for ($i = 0; $i < $this->days; $i++) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $this->gitDiffForDay($date);
            }
        }
        $this->changeDir($this->directory); // Go back to the parent directory
        $this->output(PHP_EOL."End of Git Diff Analysis", 1, 1);
    }

    /**
     * Analyze Git diffs for the given day.
     */
    protected function gitDiffForDay(string $date): void
    {
        $this->output($date, 1, 1);
        $this->totalChangesPerDayPerRepo[$date] = [];
        $repos = $this->findGitRepos();
        foreach ($repos as $repo) {
            $this->outputDebug("Analyzing repository: $repo", 3);
            $this->changeDir($repo);
            $this->totalChangesPerDayPerRepo[$date][$repo] = $this->gitDiffForDayForRepo($date, $repo);
            if ($this->totalChangesPerDayPerRepo[$date][$repo] > 0) {
                $this->outputEffort($repo, $this->totalChangesPerDayPerRepo[$date][$repo], 3, 2);
            }
        }
        $this->outputEffort($date, array_sum($this->totalChangesPerDayPerRepo[$date]), 2, 1);
    }

    /**
        * Analyze Git diffs for the given day.
        */
    protected function gitDiffForDayForRepo(string $date, string $repo): int
    {
        $noOfChanges = 0;
        // Check which branch to use: develop, main, or master
        $branch = $this->getAvailableBranch();

        if ($branch === null) {
            $this->outputDebug("[ERROR] No branch found for repository {$this->currentDir}. Skipping.");
            return 0;
        }

        // Get the start and end commits for the day
        $startOfDayCommit = trim(shell_exec("git rev-list -n 1 --before='$date 00:00' $branch"));
        $endOfDayCommit = trim(shell_exec("git rev-list -n 1 --before='$date 23:59' $branch"));
        $this->outputDebug("Last commit from previous day: $startOfDayCommit", 4);
        $this->outputDebug("Last commit from current day: $endOfDayCommit", 4);
        // Check if valid commits are found for the day
        if (empty($startOfDayCommit) || empty($endOfDayCommit)) {
            return 0; // Skip if no commits found for the date
        }

        // Fetch the diff for the day
        $diffOutput = shell_exec("git diff $startOfDayCommit $endOfDayCommit | grep -v '/dist/'");

        // remove empty lines and lines that are too long
        $diffOutput = implode("".PHP_EOL, array_filter(explode("".PHP_EOL, $diffOutput), function ($line) {
            return strlen($line) < 1000;
        }));


        // If no changes are found
        if (empty($diffOutput)) {
            return 0; // Skip if no changes are found
        }

        $hasChanges = false;

        $commitMessagesArray = [];
        $commitMessages = $this->getCommitMessages($startOfDayCommit, $endOfDayCommit);
        if (!empty($commitMessages)) {
            foreach ($commitMessages as $message) {
                $commitMessagesArray[] = $message ?: 'empty commit';
            }
        }

        // Loop through the file types and process changes
        foreach ($this->fileTypes as $fileType) {
            $numberOfChanges = $this->extractChangesForFileType($diffOutput, $fileType['extension'], $fileType['type']);
            $hasChanges = $hasChanges || $numberOfChanges > 0;
            $noOfChanges += $numberOfChanges;
        }

        // If no changes in any file types, skip output for this repository
        if (!$hasChanges) {
            return 0;
        }


        // Display total changes and estimated time
        $this->outputEffort($repo, $noOfChanges, 3, 3);
        if (count($commitMessagesArray) > 0) {
            $this->output("Commit Messages", 4, 2);
            $this->output($commitMessagesArray, 0, 2);
        }

        return $noOfChanges;
    }

    /**
     * Determine the branch to use by checking for branchesToCheck values
     *
     * @return string|null The branch to use, or null if no valid branch is found.
     */
    public function getAvailableBranch(): ?string
    {
        foreach ($this->branchesToCheck as $branch) {
            $branchCheck = trim(shell_exec("git branch --list $branch"));
            if (!empty($branchCheck)) {
                return $branch;
            }
        }

        return null;
    }



    /**
     * Find all Git repositories in the current directory (including root and subdirectories)
     * where the remote URL contains the specified filter.
     *
     * @return array
     */
    protected function findGitRepos(): array
    {
        $repos = [];

        // Check if the root directory is a Git repository
        $rootGitDir = ($this->directory) . '/.git';
        if (file_exists($rootGitDir) && is_dir($rootGitDir)) {
            $remoteUrl = shell_exec("git -C " . escapeshellarg(($this->directory)) . " remote -v");

            // Check if the URL contains the filter (e.g., 'sunnysideup')
            if (stripos($remoteUrl, $this->filter) !== false) {
                $repos[] = ($this->directory);
            }
        }

        // Use FilesystemIterator to recursively scan directories for .git folders
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->directory,
                RecursiveDirectoryIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $dir) {
            if ($dir->isDir() && basename($dir) === '.git') {
                // Found a .git directory, process its parent directory
                $parentDir = dirname($dir->getRealPath());
                $remoteUrl = shell_exec("git -C " . escapeshellarg($parentDir) . " remote -v");

                // Check if the URL contains the filter (e.g., 'sunnysideup')
                if (stripos($remoteUrl, $this->filter) !== false) {
                    $repos[] = realpath($parentDir);
                }
            }
        }

        return array_filter(array_unique($repos));
    }

    /**
     * Helper function to extract and list the number of changes for each file type.
     */
    protected function extractChangesForFileType(string $diffOutput, string $filePattern, string $fileType): int
    {
        preg_match_all('#diff --git a/(.*' . $filePattern . ')#', $diffOutput, $matches);
        $files = $matches[1];
        $totalChanges = 0;
        $data = [];
        if (!empty($files)) {
            foreach ($files as $file) {
                // Extract changes for each individual file
                $fileDiff = $this->extractFileDiff($diffOutput, $file);
                $linesAdded = substr_count($fileDiff, "\n+");
                $linesRemoved = substr_count($fileDiff, "\n-");

                // Get just the file name without the full path
                $fileName = basename($file);

                // Calculate total changes for this file
                $fileChanges = $linesAdded + $linesRemoved;
                if ($fileChanges > 0) {
                    $hasChanges = true;
                    $totalChanges += $fileChanges;

                    // Print the result in the desired format
                    $data[] = "$fileName: $fileChanges changes";
                }
            }
        }
        if ($totalChanges > 0) {
            $this->output("$fileType Files: $totalChanges changes", 4, 2);
            $this->output($data, 0, 3);
            if ($this->showFullDiff) {
                $this->output("DIFF FOR $fileType", 5, 0);
                $this->output($this->showFirst100AddedLinesFromString($diffOutput), 0, 0);
            }
        }
        return $totalChanges;
    }

    /**
     * Extract the diff for a specific file from the full diff output.
     */
    protected function extractFileDiff(string $diffOutput, string $file): string
    {
        $fileDiffPattern = "#diff --git a/$file.*?(?=diff --git|$)#s";
        preg_match($fileDiffPattern, $diffOutput, $matches);
        return $matches[0] ?? '';
    }

    /**
     * Fetch the commit messages for a given day.
     */
    protected function getCommitMessages(string $startOfDayCommit, string $endOfDayCommit): array
    {
        $commitLog = shell_exec("git log --pretty=format:'%s' $startOfDayCommit..$endOfDayCommit");
        $commitMessages = array_filter(array_map('trim', explode("".PHP_EOL, $commitLog)));
        return array_unique($commitMessages);
    }

    protected function changeDir(string $dir): void
    {
        $this->currentDir = realpath($dir);
        if (!chdir($dir)) {
            user_error("Could not change to directory: $dir");
        }
    }

    protected function outputEffort(string $name, int $numberOfChanges, ?int $headerLevel = 3, ?int $verbosityLevel = null)
    {
        // Convert total changes to time in minutes and hours
        $timeInMinutes = $this->instantiationCostInMinutes;
        // 10% less for each additional change
        $initialMinutes = $this->minutesForFirstLineChange;

        for ($i = 0; $i < $numberOfChanges; $i++) {
            $timeInMinutes += $initialMinutes * pow($this->decreaseFactor, $i);
        }
        $hours = floor($timeInMinutes / 60);
        $minutes = round($timeInMinutes % 60);
        if ($hours > 0) {
            $time = "$hours hours and $minutes minutes";
        } else {
            $time = "$minutes minutes";
        }
        $this->output(
            "Changes for $name:",
            $headerLevel,
            $verbosityLevel
        );
        $this->output($numberOfChanges .' (changes)', 0, $verbosityLevel);
        $this->output($time .' (estimated time)', 0, $verbosityLevel);
    }

    protected function outputDebug(string|array $message, $headerLevel = 0, ?int $verbosityLevel = null)
    {
        if ($this->debug) {
            if ($verbosityLevel === null) {
                $verbosityLevel = 0;
            }
            $this->output($message, $headerLevel, $verbosityLevel);
        }
    }

    protected function output(string|array $message, $headerLevel = 0, ?int $verbosityLevel = null)
    {
        if ($verbosityLevel === null) {
            $verbosityLevel = $this->verbosity;
        }
        if ($verbosityLevel > $this->verbosity) {
            return;
        }
        if (is_array($message)) {
            foreach ($message as $m) {
                $this->output($m, $headerLevel);
            }
        } else {
            $this->outputHeader($headerLevel);
            echo $message;
            $this->outputHeader($headerLevel, false);
        }
    }

    protected function outputHeader($headerLevel = 0, ?bool $isStart = true)
    {
        if ($headerLevel === 0) {
            if (! $isStart) {
                echo PHP_EOL;
            }
            return;
        }
        switch ($headerLevel) {
            case 1:
                if ($isStart) {
                    echo PHP_EOL;
                    echo PHP_EOL;
                    echo PHP_EOL;
                } else {
                    echo PHP_EOL;
                }
                echo "==============================================================".PHP_EOL;
                break;
            case 2:
                if ($isStart) {
                    echo PHP_EOL;
                    echo PHP_EOL;
                } else {
                    echo PHP_EOL;
                }
                echo "**************************************************************".PHP_EOL;
                break;
            case 3:
                if ($isStart) {
                    echo PHP_EOL;
                } else {
                    echo PHP_EOL;
                }
                echo "--------------------------------------------------------------".PHP_EOL;
                break;
            case 4:
                echo "===";
                if (! $isStart) {
                    echo PHP_EOL;
                }
                break;
            case 5:
                echo "---";
                if (! $isStart) {
                    echo PHP_EOL;
                }
                break;
            default:
                //nothing
                break;
        }
    }


    protected function showFirst100AddedLinesFromString(string $diffContent): string
    {
        $lines = explode("\n", $diffContent); // Split the string into lines
        $lineCount = 0;
        $string = '';
        foreach ($lines as $line) {
            // Check if the line starts with a '+'
            if (strpos($line, '+') === 0) {
                $string .= $line . PHP_EOL;
                $lineCount++;

                // Stop after the first 100 lines that start with '+'
                if ($lineCount >= 100) {
                    break;
                }
            }
        }
        return $string;
    }


}
