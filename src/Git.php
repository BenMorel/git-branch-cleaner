<?php

declare(strict_types=1);

namespace App;

use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Repository;
use Gitonomy\Git\WorkingCopy;

/**
 * Wraps Gitonomy Git repository / working copy.
 */
class Git
{
    private Repository $repository;
    private WorkingCopy $workingCopy;

    public function __construct(string $directory)
    {
        $this->repository = new Repository($directory);
        $this->workingCopy = $this->repository->getWorkingCopy();
    }

    /**
     * @return string[]
     */
    public function getLocalBranches(): array
    {
        $localBranches = $this->repository->getReferences()->getLocalBranches();
        $localBranches = array_map(fn (Branch $branch) => $branch->getName(), $localBranches);

        sort($localBranches);

        return $localBranches;
    }

    public function hasLocalBranch(string $branch): bool
    {
        try {
            $this->repository->getReferences()->getBranch($branch);
        } catch (ReferenceNotFoundException) {
            return false;
        }

        return true;
    }

    public function hasRemoteBranch(string $branch): bool
    {
        try {
            $this->repository->getReferences()->getRemoteBranch($branch);
        } catch (ReferenceNotFoundException) {
            return false;
        }

        return true;
    }

    /**
     * @throws ProcessException
     */
    public function deleteBranch(string $branch): void
    {
        $this->repository->run('branch', ['--delete', '--force', $branch]);
    }

    public function deleteBranchIfExists(string $branch): void
    {
        try {
            $this->deleteBranch($branch);
        } catch (ProcessException) {
            // ignore
        }
    }

    public function getHeadCommitHash(): string
    {
        // force reload references, or getHeadCommit() would not know about newly created branches
        $this->repository->getReferences(true);

        return $this->repository->getHeadCommit()->getHash();
    }

    public function fetchAll(): void
    {
        $this->repository->run('fetch', ['--all']);
    }

    public function checkout(string $branch, ?string $branchTo = null): void
    {
        $this->workingCopy->checkout($branch, $branchTo);
    }

    /**
     * @throws ProcessException
     */
    public function rebase(string $referenceBranch): void
    {
        $this->repository->run('rebase', [$referenceBranch]);
    }

    public function abortRebase(): void
    {
        $this->repository->run('rebase', ['--abort']);
    }
}
