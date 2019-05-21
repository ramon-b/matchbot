<?php

namespace App\Jobs;

use App\Event;
use App\Player;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CalculatePoints implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        Player::where('status', 1)
            ->update([
                'points' => 50,
            ]);

        $matches = Event::where('events.status', 1)
            ->with('eventTeams.result')
            ->get();

        foreach ($matches as $match) {
            $this->calculatePoints($match);
        }
    }

    /**
     * Calculate points per match.
     *
     * @param  Event $match
     *
     * @return void
     */
    public function calculatePoints(Event $match): void
    {
        foreach ($match->eventTeams as $team) {
            $team->points = $this->calculatePointsForTeam($team);
        }

        $highestPointsMatch = max($match->eventTeams[0]->points, $match->eventTeams[1]->points);
        $lowestPointsMatch = min($match->eventTeams[0]->points, $match->eventTeams[1]->points);
        $fairnessCorrection = (($highestPointsMatch === 0 ? 1 : $highestPointsMatch) / ($lowestPointsMatch === 0 ? 1 : $lowestPointsMatch));
        $goalsDiff = abs($match->eventTeams[0]->result->score - $match->eventTeams[1]->result->score);
        $goalMultiplier = 1 + ($goalsDiff / 10);
        $basePoints = 20 * $goalMultiplier;
        $match->eventTeams[0]->win = $this->isWinner($match->eventTeams[0], $match->eventTeams[1]);
        $match->eventTeams[1]->win = $this->isWinner($match->eventTeams[1], $match->eventTeams[0]);

        foreach ($match->eventTeams as $team) {
            $team = $this->setPointsPerTeam($team, $highestPointsMatch, $basePoints, $fairnessCorrection);
        }
    }

    /**
     * Calculate points per team.
     *
     * @param  EventTeam $team
     *
     * @return int
     */
    public function calculatePointsForTeam(EventTeam $team): int
    {
        $points = 0;

        foreach ($team->players as $player) {
            $points += $player->points;
        }

        return $points;
    }

    /**
     * Set points per team
     * @param  \App\EventTeam $team
     * @param  int $highestPoints
     * @param  int $basePoints
     * @param  float $fairnessCorrection
     * @return \App\EventTeam
     */
    public function setPointsPerTeam($team, $highestPoints, $basePoints, $fairnessCorrection)
    {
        if ($team->win) {
            if ($highestPoints == $team->points) {
                $points = ($basePoints / $fairnessCorrection);
            } else {
                $points = ($basePoints * $fairnessCorrection);
            }
        } else {
            if ($highestPoints == $team->points) {
                $points = -($basePoints * $fairnessCorrection);
            } else {
                $points = -($basePoints / $fairnessCorrection);
            }
        }
        $team->pointsAcquired = round($points);

        foreach ($team->players as $player) {
            $player->points += $this->setPointsPerPlayer($player, $team);

            if ($player->points < 0) {
                $player->points = 0;
            }

            $player->save();
        }

        return $team;
    }

    /**
     * Set points per player
     * @param  \App\Player $player
     * @param  \App\EventTeam $team
     * @return int
     */
    public function setPointsPerPlayer($player, $team)
    {
        if ($team->points == 0) {
            $percentage = 0.50;
        } else {
            $percentage = ($player->points / $team->points);
        }

        if ($team->win) {
            $percentage = (1 - $percentage);
        }
        $points = ($team->pointsAcquired * $percentage);

        return round($points);
    }

    /**
     * Check if team is winner
     * @param  \App\EventTeam $team
     * @param  \App\EventTeam $opponent
     * @return bool
     */
    public function isWinner($team, $opponent)
    {
        return $team->result->score > $opponent->result->score;
    }
}