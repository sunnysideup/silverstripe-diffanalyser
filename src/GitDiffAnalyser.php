<?php

namespace Sunnysideup\DiffAnalyser;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class GitDiffAnalyser
{
    protected $minutesForFirstLineChange;
    protected $branch;
    protected $days;
    protected $filter;
    protected $verbosity;
    protected $date;

    protected $fileTypes = [
        ['extension' => '\.php', 'type' => 'PHP'],
        ['extension' => '\.js', 'type' => 'JavaScript'],
        // ['extension' => '\.css', 'type' => 'CSS'], see SASS / LESS / SCSS
        ['extension' => '\.(yml|yaml)', 'type' => 'YML/YAML'],
        ['extension' => '\.ss', 'type' => 'SilverStripe (SS)'],
        ['extension' => '\.html', 'type' => 'HTML'],
        ['extension' => '\.htm', 'type' => 'HTML'],
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

    public function __construct(
        int $daysOrDate = 1,
        string $branch = 'develop',
        string $filter = 'sunnysideup',
        float $minutesForFirstLineChange = 20, // 1 line change per 3 minutes
        int $verbosity = 2,
        string $date = null
    ) {
        $this->minutesForFirstLineChange = $minutesForFirstLineChange;
        $this->branch = $branch;
        if (is_int($daysOrDate)) {
            $this->days = $daysOrDate;
        } elseif (strtotime($daysOrDate) > 0) {
            $this->date = $daysOrDate;
        } else {
            user_error('You need to set a valid number of days or a valid date. ');
        }
        $this->filter = $filter;
        $this->verbosity = $verbosity;
    }

    /**
     * Helper function to extract and list the number of changes for each file type.
     */
    protected function extractChanges(string $diffOutput, string $filePattern, string $fileType, int &$totalChanges): bool
    {
        preg_match_all('#diff --git a/(.*' . $filePattern . ')#', $diffOutput, $matches);
        $files = $matches[1];
        $hasChanges = false;

        if (!empty($files)) {
            if ($this->verbosity >= 2) {
                echo "\n### $fileType Files ###\n";
            }
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
                    if ($this->verbosity === 3) {
                        echo "$fileName: $fileChanges changes\n";
                    }
                }
            }
        }

        return $hasChanges;
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
        $commitMessages = array_filter(array_map('trim', explode("\n", $commitLog)));
        return array_unique($commitMessages);
    }

    /**
     * Analyze Git diffs for the given day.
     */
    protected function gitDiffForDay(string $date): void
    {
        // Check which branch to use: develop, main, or master
        $branch = $this->getAvailableBranch();

        if ($branch === null) {
            if ($this->verbosity >= 2) {
                echo "\n[INFO] No develop, main, or master branch found for repository. Skipping.\n";
            }
            return;
        }

        // Get the start and end commits for the day
        $startOfDayCommit = trim(shell_exec("git rev-list -n 1 --before='$date 00:00' $branch"));
        $endOfDayCommit = trim(shell_exec("git rev-list -n 1 --before='$date 23:59' $branch"));

        // Check if valid commits are found for the day
        if (empty($startOfDayCommit) || empty($endOfDayCommit)) {
            return; // Skip if no commits found for the date
        }

        // Fetch the diff for the day
        $diffOutput = shell_exec("git diff $startOfDayCommit $endOfDayCommit | grep -v '/dist/'");
        $diffOutput = implode("\n", array_filter(explode("\n", $diffOutput), function ($line) {
            return strlen($line) < 1000;
        }));

        // If no changes are found
        if (empty($diffOutput)) {
            return; // Skip if no changes are found
        }

        $totalChanges = 0;
        $hasChanges = false;

        // Show commit messages if verbosity >= 2
        if ($this->verbosity >= 2) {
            $commitMessages = $this->getCommitMessages($startOfDayCommit, $endOfDayCommit);
            if (!empty($commitMessages)) {
                echo "\n### Commit Messages ###\n";
                foreach ($commitMessages as $message) {
                    echo "- $message\n";
                }
            }
        }



        // Loop through the file types and process changes
        foreach ($this->fileTypes as $fileType) {
            $hasChangesForType = $this->extractChanges($diffOutput, $fileType['extension'], $fileType['type'], $totalChanges);
            $hasChanges = $hasChanges || $hasChangesForType;
        }

        // If no changes in any file types, skip output for this repository
        if (!$hasChanges) {
            return;
        }

        // Convert total changes to time in minutes and hours
        $timeInMinutes = 0;
        $decreaseFactor = 0.9;  // 10% less for each additional change
        $initialMinutes = $this->minutesForFirstLineChange;

        for ($i = 0; $i < $totalChanges; $i++) {
            $timeInMinutes += $initialMinutes * pow($decreaseFactor, $i);
        }
        $hours = floor($timeInMinutes / 60);
        $minutes = round($timeInMinutes % 60);

        // Display total changes and estimated time
        if ($this->verbosity >= 1) {
            echo "\n===== Total Changes for the Day: $totalChanges =====\n";
            if ($hours > 0) {
                echo "Estimated Time: $hours hours and $minutes minutes\n";
            } else {
                echo "Estimated Time: $minutes minutes\n";
            }
        }
    }

    /**
     * Determine the branch to use by checking for develop, main, or master.
     *
     * @return string|null The branch to use, or null if no valid branch is found.
     */
    protected function getAvailableBranch(): ?string
    {
        // Check for develop branch
        $developBranch = trim(shell_exec("git branch --list develop"));
        if (!empty($developBranch)) {
            return 'develop';
        }

        // Check for main branch
        $mainBranch = trim(shell_exec("git branch --list main"));
        if (!empty($mainBranch)) {
            return 'main';
        }

        // Check for master branch
        $masterBranch = trim(shell_exec("git branch --list master"));
        if (!empty($masterBranch)) {
            return 'master';
        }

        // No valid branch found
        return null;
    }

    /**
     * Run the Git diff analysis for the specified number of days or a specific date.
     */
    public function run(): void
    {
        // Find all Git repositories that match the filter
        $repos = $this->findGitRepos();
        foreach ($repos as $repo) {
            if ($this->verbosity > 3) {
                echo "\n===== Analyzing repository: $repo =====\n";
            }
            chdir($repo);
            if ($this->date) {
                $this->gitDiffForDay($this->date);
            } else {
                for ($i = 0; $i < $this->days; $i++) {
                    $date = date('Y-m-d', strtotime("-$i days"));
                    $this->gitDiffForDay($date);
                }
            }
            chdir('..'); // Go back to the parent directory
        }
    }

    /**
     * Find all Git repositories in the current directory (including root and subdirectories) where the remote URL contains the specified filter.
     *
     * @return array
     */
    protected function findGitRepos(): array
    {
        $repos = [];

        // Check if the root directory is a Git repository
        $rootGitDir = realpath('.') . '/.git';
        if (file_exists($rootGitDir) && is_dir($rootGitDir)) {
            $remoteUrl = shell_exec("git -C " . escapeshellarg(realpath('.')) . " remote -v");

            // Check if the URL contains the filter (e.g., 'sunnysideup')
            if (stripos($remoteUrl, $this->filter) !== false) {
                $repos[] = realpath('.');
            }
        }

        // Use FilesystemIterator to recursively scan directories for .git folders
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                '.',
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

        return $repos;
    }
}
