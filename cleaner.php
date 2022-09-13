<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Git;
use App\ProgressBar;
use Gitonomy\Git\Exception\ProcessException;
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
    $tmpBranch = '__cleaner_tmp__';

    /** @var string $pathToRepository */
    $pathToRepository = $input->getArgument('path-to-repository');

    /** @var string $referenceBranch */
    $referenceBranch = $input->getArgument('reference-branch');

    /** @var string[] $skipBranches */
    $skipBranches = $input->getOption('skip-branch');

    $io = new SymfonyStyle($input, $output);

    $git = new Git($pathToRepository);

    $io->write('Fetching branches... ');
    $git->fetchAll();
    $io->writeln('<info>✔</info>');

    if (!$git->hasRemoteBranch($referenceBranch)) {
        if ($git->hasLocalBranch($referenceBranch)) {
            $io->error('Only remote reference branches are supported at the moment.');
        } else {
            $io->error(sprintf('Invalid reference branch %s', $referenceBranch));
        }

        return 1;
    }

    $io->write('Getting reference branch head commit... ');
    $git->checkout($referenceBranch);
    $referenceBranchHeadCommitHash = $git->getHeadCommitHash();
    $io->writeln('<info>✔</info>');

    $git->deleteBranchIfExists($tmpBranch);

    $io->newLine();
    $io->writeln('Analyzing branches...');

    $localBranches = $git->getLocalBranches();
    $localBranches = array_filter($localBranches, fn (string $branch) => !in_array($branch, $skipBranches));

    $progressBar = new ProgressBar($io, count($localBranches));

    $upToDateBranches = [];

    foreach ($localBranches as $branch) {
        $progressBar->setMessage($branch);
        $git->checkout($branch, $tmpBranch);

        $success = true;

        try {
            $git->rebase($referenceBranch);
        } catch (ProcessException) {
            $git->abortRebase();
            $success = false;
        }

        if ($success) {
            if ($git->getHeadCommitHash() === $referenceBranchHeadCommitHash) {
                $upToDateBranches[] = $branch;
            }
        }

        $git->checkout($referenceBranch);
        $git->deleteBranch($tmpBranch);

        $progressBar->advance();
    }

    $progressBar->finish();
    $io->newLine(2);

    if (!$upToDateBranches) {
        $io->success(sprintf('No branch is up-to-date with %s.', $referenceBranch));

        return 0;
    }

    $io->writeln(sprintf('The following local branches are up-to-date with <info>%s</info>:', $referenceBranch));
    $io->newLine();
    $io->listing($upToDateBranches);

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
            fn (string $branch) => $io->confirm(sprintf('Delete branch %s?', $branch), false),
        ),
    };

    if (!$branchesToDelete) {
        $io->success('No branch has been deleted.');

        return 0;
    }

    foreach ($branchesToDelete as $branch) {
        $io->write(sprintf('Deleting branch <info>%s</info>... ', $branch));
        $git->deleteBranch($branch);
        $io->writeln('<info>✔</info>');
    }

    $io->success(sprintf('Successfully deleted %d branches!', count($branchesToDelete)));

    return 0;
}
