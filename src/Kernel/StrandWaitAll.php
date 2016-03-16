<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\Kernel;

use Recoil\Exception\TerminatedException;
use Throwable;

/**
 * Implementation of Api::all().
 */
final class StrandWaitAll implements Awaitable, StrandObserver
{
    public function __construct(Strand ...$substrands)
    {
        $this->substrands = $substrands;
    }

    /**
     * Perform the work.
     *
     * @param Strand $strand The strand to resume on completion.
     * @param Api    $api    The kernel API.
     */
    public function await(Strand $strand, Api $api)
    {
        $this->strand = $strand;
        $this->strand->setTerminator([$this, 'cancel']);

        foreach ($this->substrands as $substrand) {
            $substrand->attachObserver($this);
        }
    }

    /**
     * A strand completed successfully.
     *
     * @param Strand $strand The strand.
     * @param mixed  $value  The result of the strand's entry point coroutine.
     */
    public function success(Strand $strand, $value)
    {
        assert(in_array($strand, $this->substrands, true), 'unknown strand');

        $index = \array_search($strand, $this->substrands, true);
        unset($this->substrands[$index]);

        $this->values[$index] = $value;

        if (empty($this->substrands)) {
            $this->strand->resume($this->values);
        }
    }

    /**
     * A strand failed due to an uncaught exception.
     *
     * @param Strand    $strand    The strand.
     * @param Throwable $exception The exception.
     */
    public function failure(Strand $strand, Throwable $exception)
    {
        assert(in_array($strand, $this->substrands, true), 'unknown strand');

        foreach ($this->substrands as $s) {
            if ($s !== $strand) {
                $s->detachObserver($this);
                $s->terminate();
            }
        }

        $this->substrands = [];
        $this->strand->throw($exception);
    }

    /**
     * A strand was terminated.
     *
     * @param Strand $strand The strand.
     */
    public function terminated(Strand $strand)
    {
        $this->failure($strand, new TerminatedException($strand));
    }

    /**
     * Terminate all pending strands.
     */
    public function cancel()
    {
        foreach ($this->substrands as $strand) {
            $strand->detachObserver($this);
            $strand->terminate();
        }
    }

    /**
     * @var Strand|null The strand to resume.
     */
    private $strand;

    /**
     * @var array<Strand> The strands to wait for.
     */
    private $substrands;

    /**
     * @var array<integer, mixed> The results of the successful strands. Ordered
     *                     by completion order, indexed by strand order.
     */
    private $values = [];
}
