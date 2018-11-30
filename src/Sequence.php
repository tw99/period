<?php

/**
 * League.Period (https://period.thephpleague.com).
 *
 * (c) Ignace Nyamagana Butera <nyamsprod@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace League\Period;

use Countable;
use Iterator;
use IteratorAggregate;
use function array_filter;
use function array_merge;
use function count;
use function reset;
use function sort;
use function sprintf;
use function uasort;

/**
 * A class to manipulate interval collection.
 *
 * @package League.period
 * @author  Ignace Nyamagana Butera <nyamsprod@gmail.com>
 * @since   4.1.0
 */
final class Sequence implements Countable, IteratorAggregate
{
    /**
     * @var Period[]
     */
    private $intervals = [];

    /**
     * new instance.
     *
     * @param Period... $intervals
     */
    public function __construct(Period ...$intervals)
    {
        $this->intervals = $intervals;
    }

    /**
     * {@inheritdoc}
     */
    public function count(): int
    {
        return count($this->intervals);
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator(): Iterator
    {
        foreach ($this->intervals as $offset => $interval) {
            yield $offset => $interval;
        }
    }

    /**
     * Tells whether the sequence is empty.
     */
    public function isEmpty(): bool
    {
        return [] === $this->intervals;
    }

    /**
     * Removes all intervals from the collection.
     */
    public function clear(): void
    {
        $this->intervals = [];
    }

    /**
     * Returns the array representation of the sequence.
     *
     * @return Period[]
     */
    public function toArray(): array
    {
        return $this->intervals;
    }

    /**
     * Returns the interval specified at a given offset.
     *
     * @throws InvalidIndex If the offset is illegal for the current sequence
     */
    public function get(int $offset): Period
    {
        $period = $this->intervals[$offset] ?? null;
        if (null !== $period) {
            return $period;
        }

        throw new InvalidIndex(sprintf('%s is an invalid offset in the current sequence', $offset));
    }

    /**
     * Removes an interval from the sequence at the given offset and returns it.
     *
     * The sequence is re-indexed after removal
     *
     * @throws InvalidIndex If the offset is illegal for the current sequence.
     */
    public function remove(int $offset): Period
    {
        $interval = $this->get($offset);
        unset($this->intervals[$offset]);

        $this->intervals = array_values($this->intervals);

        return $interval;
    }

    /**
     * Adds new interval at the end of the sequence.
     * @param Period... $intervals
     */
    public function push(Period $interval, Period ...$intervals): void
    {
        $this->intervals = array_merge($this->intervals, [$interval], $intervals);
    }

    /**
     * Updates the interval at the specify offset.
     *
     * @throws InvalidIndex If the offset is illegal for the current sequence.
     */
    public function set(int $offset, Period $interval): void
    {
        $this->get($offset);
        $this->intervals[$offset] = $interval;
    }

    /**
     * Tells whether the given interval is present in the sequence.
     * @param Period... $intervals
     */
    public function contains(Period $interval, Period ... $intervals): bool
    {
        $intervals[] = $interval;
        foreach ($intervals as $period) {
            if (null === $this->find($period)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Attempts to find the first offset attached to the submitted interval.
     *
     * If no offset is found the method returns null.
     *
     * @return ?int
     */
    public function find(Period $interval): ?int
    {
        foreach ($this->intervals as $offset => $period) {
            if ($period->equals($interval)) {
                return $offset;
            }
        }

        return null;
    }

    /**
     * Returns the sequence boundaries as a Period instance.
     *
     * If the sequence contains no interval null is returned.
     *
     * @return ?Period
     */
    public function getInterval(): ?Period
    {
        $period = reset($this->intervals);
        if (false === $period) {
            return null;
        }

        return $period->merge(...$this->intervals);
    }

    /**
     * Sort the current instance according to the given comparison callable
     * and maintain index association.
     *
     * Returns true on success or false on failure
     */
    public function sort(callable $compare): bool
    {
        return uasort($this->intervals, $compare);
    }

    /**
     * Returns an instance sorted according to the given comparison callable
     * but does not maintain index association.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the sorted intervals. The key are re-indexed
     */
    public function sorted(callable $compare): self
    {
        $intervals = $this->intervals;
        usort($intervals, $compare);
        if ($intervals === $this->intervals) {
            return $this;
        }

        return new self(...$intervals);
    }

    /**
     * Filters the sequence according to the given predicate.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the interval which validate the predicate.
     */
    public function filter(callable $predicate): self
    {
        $intervals = array_filter($this->intervals, $predicate);
        if ($intervals === $this->intervals) {
            return $this;
        }

        return new self(...$intervals);
    }

    /**
     * Tells whether some interval in the current instance satisfies the predicate.
     */
    public function some(callable $predicate): bool
    {
        foreach ($this->intervals as $interval) {
            if (true === $predicate($interval)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tells whether all interval in the current instance satisfies the predicate.
     */
    public function every(callable $predicate): bool
    {
        foreach ($this->intervals as $interval) {
            if (true !== $predicate($interval)) {
                return false;
            }
        }

        return [] !== $this->intervals;
    }

    /**
     * Returns the gaps inside the instance.
     */
    public function getGaps(): self
    {
        $sequence = new self();
        $interval = null;
        $currentInterval = null;
        foreach ($this->sorted([$this, 'sortByStartDate']) as $period) {
            $currentInterval = $period;
            if (null === $interval) {
                $interval = $currentInterval;
                continue;
            }

            if (!$interval->overlaps($currentInterval) && !$interval->abuts($currentInterval)) {
                $sequence->push($interval->gap($currentInterval));
            }

            if (!$interval->contains($currentInterval)) {
                $interval = $currentInterval;
            }
        }

        return $sequence;
    }

    /**
     * Sort two Interval instance using their start datepoint.
     */
    private function sortByStartDate(Period $interval1, Period $interval2): int
    {
        return $interval1->getStartDate() <=> $interval2->getStartDate();
    }

    /**
     * Returns the intersections inside the instance.
     */
    public function getIntersections(): self
    {
        $sequence = new self();
        $interval = null;
        $currentInterval = null;
        foreach ($this->sorted([$this, 'sortByStartDate']) as $period) {
            if (null === $interval) {
                $interval = $period;
                continue;
            }

            if (null !== $currentInterval && $interval->contains($period)) {
                continue;
            }

            $currentInterval = $period;
            if ($interval->overlaps($currentInterval)) {
                $sequence->push($interval->intersect($currentInterval));
            }

            if (!$interval->contains($currentInterval)) {
                $interval = $currentInterval;
                $currentInterval = null;
            }
        }

        return $sequence;
    }
}
