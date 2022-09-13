<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Repository;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Console\Style\SymfonyStyle;

(new SingleCommandApplication())
    ->addArgument('path-to-repository', InputArgument::REQUIRED, 'The git repository path')
    ->addArgument('reference-branch', InputArgument::OPTIONAL, 'The name of the branch to check against', 'origin/master')
    ->addOption('skip-branch', 's', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Skip a local branch, for example your <info>master</info> or <info>main</info> branch')
    ->setCode(application(...))
    ->run();

function application(InputInterface $input, OutputInterface $output): int
{
    $tmpBranchName = '__cleaner_tmp__';

    /** @var string $pathToRepository */
    $pathToRepository = $input->getArgument('path-to-repository');

    /** @var string $referenceBranch */
    $referenceBranch = $input->getArgument('reference-branch');

    /** @var string[] $skipBranches */
    $skipBranches = $input->getOption('skip-branch');

    $io = new SymfonyStyle($input, $output);

    $repository = new Repository($pathToRepository);
    $workingCopy = $repository->getWorkingCopy();

    $io->write('Fetching branches... ');
    $repository->run('fetch', ['--all']);
    $io->writeln('<info>✔</info>');

    try {
        $repository->getReferences()->getRemoteBranch($referenceBranch);
    } catch (ReferenceNotFoundException) {
        try {
            $repository->getReferences()->getBranch($referenceBranch);
        } catch (ReferenceNotFoundException) {
            $io->error(sprintf('Invalid reference branch %s', $referenceBranch));
            return 1;
        }

        $io->error('Only remote reference branches are supported at the moment.');
        return 1;
    }

    $io->write('Getting reference branch head commit... ');
    $workingCopy->checkout($referenceBranch);
    $referenceBranchHeadCommit = $repository->getHeadCommit();
    $io->writeln('<info>✔</info>');

    // delete the temporary branch if it was not previously cleaned up
    try {
        $repository->run('branch', ['--delete', '--force', $tmpBranchName]);
    } catch (ProcessException) {
    }

    $io->newLine();
    $io->writeln('Analyzing branches...');

    $formatDefinition = ProgressBar::getFormatDefinition(ProgressBar::FORMAT_NORMAL);
    ProgressBar::setFormatDefinition('custom', $formatDefinition . '  %message%');

    $localBranches = $repository->getReferences()->getLocalBranches();
    $localBranches = array_filter($localBranches, fn (Branch $branch) => !in_array($branch->getName(), $skipBranches));

    usort($localBranches, fn (Branch $a, Branch $b) => $a->getName() <=> $b->getName());

    $progressBar = new ProgressBar($io, count($localBranches));
    $progressBar->setFormat('custom');
    $progressBar->setMessage('');

    $progressBar->setBarCharacter('<fg=green>●</>');
    $progressBar->setEmptyBarCharacter('<fg=red>●</>');
    $progressBar->setProgressCharacter('<fg=white>●</>');

    $progressBar->maxSecondsBetweenRedraws(1 / 25);
    $progressBar->start();

    $upToDateBranches = [];

    foreach ($localBranches as $branch) {
        $progressBar->setMessage($branch->getName());
        $workingCopy->checkout($branch->getName(), $tmpBranchName);

        $success = true;

        try {
            $repository->run('rebase', [$referenceBranch]);
        } catch (ProcessException) {
            $repository->run('rebase', ['--abort']);
            $success = false;
        }

        if ($success) {
            // force reload references, or getHeadCommit() below would fail
            $repository->getReferences(true);

            if ($repository->getHeadCommit()->getHash() === $referenceBranchHeadCommit->getHash()) {
                $upToDateBranches[] = $branch;
            }
        }

        $workingCopy->checkout($referenceBranch);
        $repository->run('branch', ['--delete', '--force', $tmpBranchName]);

        $progressBar->advance();
    }

    $progressBar->setMessage('');
    $progressBar->finish();
    $io->newLine(2);

    if (!$upToDateBranches) {
        $io->success(sprintf('No branch is up-to-date with %s.', $referenceBranch));

        return 0;
    }

    $io->writeln(sprintf('The following local branches are up-to-date with <info>%s</info>:', $referenceBranch));
    $io->newLine();
    $io->listing(array_map(fn (Branch $branch) => $branch->getName(), $upToDateBranches));

    $question = new ChoiceQuestion(
        'Do you want to delete these branches?',
        [
            'Y' => '<options=bold>Yes</>, delete these branches',
            'N' => '<options=bold>No</>, don\'t delete any branch',
            'A' => '<options=bold>Ask</> for each branch',
        ],
    );

    $question->setNormalizer(strtoupper(...));
    $answer = $io->askQuestion($question);

    $branchesToDelete = match ($answer) {
        'N' => [],
        'Y' => $upToDateBranches,
        'A' => array_filter(
            $upToDateBranches,
            fn (Branch $branch) => $io->confirm(sprintf('Delete branch %s?', $branch->getName()), false),
        ),
    };

    if (!$branchesToDelete) {
        $io->success('No branch has been deleted.');

        return 0;
    }

    foreach ($branchesToDelete as $branch) {
        $io->write(sprintf('Deleting branch <info>%s</info>... ', $branch->getName()));
        $repository->run('branch', ['--delete', $branch->getName()]);
        $io->writeln('<info>✔</info>');
    }

    $io->success(sprintf('Successfully deleted %d branches!', count($branchesToDelete)));

    return 0;
}
