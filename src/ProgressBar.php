<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Console\Helper\ProgressBar as SymfonyProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Decorator for the Symfony Console ProgressBar.
 */
class ProgressBar
{
    private SymfonyProgressBar $progressBar;

    public function __construct(OutputInterface $output, int $max = 0)
    {
        $formatDefinition = SymfonyProgressBar::getFormatDefinition(SymfonyProgressBar::FORMAT_NORMAL);
        SymfonyProgressBar::setFormatDefinition('custom', $formatDefinition . '  %message%');

        $this->progressBar = new SymfonyProgressBar($output, $max);
        $this->progressBar->setFormat('custom');
        $this->progressBar->setMessage('');

        $this->progressBar->setBarCharacter('<fg=green>●</>');
        $this->progressBar->setEmptyBarCharacter('<fg=red>●</>');
        $this->progressBar->setProgressCharacter('<fg=white>●</>');

        $this->progressBar->maxSecondsBetweenRedraws(1 / 25);
        $this->progressBar->start();
    }

    public function setMessage(string $message): void
    {
        $this->progressBar->setMessage($message);
    }

    public function advance(): void
    {
        $this->progressBar->advance();
    }

    public function finish(): void
    {
        $this->progressBar->setMessage('');
        $this->progressBar->finish();
    }
}
